<?php
declare(strict_types=1);

namespace Optout\Engine;

use Optout\Cases\Attention;
use Optout\Cases\CaseFile;
use Optout\Cases\Status;
use Optout\Cases\Store;
use Optout\Cases\Verdict;
use Optout\Config;
use Optout\Mail\OutgoingMessage;
use Optout\Mail\Sender;
use Optout\Registry\Broker;
use Optout\Registry\Registry;

/** Mails an dich: "du bist dran" sofort, die Uebersicht einmal pro Woche. */
final class Notifier
{
    public function __construct(
        private readonly Config $config,
        private readonly Sender $sender,
        private readonly Store $store,
        private readonly Registry $registry,
    ) {
    }

    /** @param list<CaseFile> $cases */
    public function attention(array $cases, int $now): ?string
    {
        $unique = [];
        foreach ($cases as $c) {
            $unique[$c->ref()] = $c;
        }
        $n = count($unique);
        $blocks = [];
        foreach ($unique as $case) {
            $blocks[] = $this->caseBlock($case, full: true);
        }
        $text = ($n === 1 ? "Ein Vorgang braucht dich:\n\n" : "$n Vorgänge brauchen dich:\n\n")
            . implode("\n\n" . str_repeat('=', 60) . "\n\n", $blocks)
            . $this->footer();
        $first = reset($unique);
        $name = $this->broker($first->slug())->name;
        $subject = $n === 1 ? "Du bist dran: $name" : "Du bist dran: $n Vorgänge";
        return $this->send($subject, $text);
    }

    public function digestDue(int $now): bool
    {
        $weekday = (int)$this->config->get('notify.digest_weekday', 1);
        $hour = (int)$this->config->get('notify.digest_hour', 8);
        if ($weekday < 0) {
            return false;
        }
        if ((int)date('N', $now) !== ($weekday === 0 ? 7 : $weekday) || (int)date('G', $now) < $hour) {
            return false;
        }
        $last = $this->store->meta('last_digest_at');
        return $last === null || date('Y-m-d', (int)$last) !== date('Y-m-d', $now);
    }

    /**
     * @param array<string, Broker> $selected
     * @param list<string> $warnings
     */
    public function digest(int $now, array $selected, array $warnings): ?string
    {
        $current = [];
        foreach (array_keys($selected) as $slug) {
            $c = $this->store->currentFor($slug);
            if ($c !== null) {
                $current[$slug] = $c;
            }
        }
        $counts = [];
        foreach ($current as $c) {
            $counts[$c->status()->value] = ($counts[$c->status()->value] ?? 0) + 1;
        }

        $lines = [];
        if (!$this->config->live()) {
            $lines[] = "TESTMODUS: Es wird nichts verschickt. Briefe mit bin/preview.php prüfen, dann in config.php 'live' => true setzen.";
            $lines[] = '';
        }
        $lines[] = 'Stand ' . date('d.m.Y H:i', $now) . ', ' . count($selected) . ' Unternehmen ausgewählt.';
        $lines[] = '';
        foreach (Status::cases() as $s) {
            if (!empty($counts[$s->value])) {
                $lines[] = sprintf('  %-22s %3d', $s->label(), $counts[$s->value]);
            }
        }

        $attention = array_filter($current, static fn (CaseFile $c) => $c->status() === Status::Attention);
        if ($attention !== []) {
            $lines[] = '';
            $lines[] = 'DU BIST DRAN';
            $lines[] = '------------';
            foreach ($attention as $c) {
                $lines[] = $this->caseBlock($c, full: false);
                $lines[] = '';
            }
        }

        $weekAgo = $now - 7 * 86400;
        $recent = array_filter($current, static fn (CaseFile $c) => $c->status() === Status::Done && (int)$c->d['closed_at'] >= $weekAgo);
        if ($recent !== []) {
            $lines[] = '';
            $lines[] = 'DIESE WOCHE ERLEDIGT';
            foreach ($recent as $c) {
                $last = end($c->d['replies']) ?: null;
                $verdict = $last ? Verdict::tryFrom((string)$last['verdict']) : null;
                $lines[] = '  ' . $this->broker($c->slug())->name . ': ' . ($verdict?->label() ?? 'von dir abgeschlossen');
            }
        }

        $soon = array_filter($current, static fn (CaseFile $c) => in_array($c->status(), [Status::Waiting, Status::Reminded], true)
            && (int)$c->d['due_at'] < $now + 7 * 86400);
        if ($soon !== []) {
            $lines[] = '';
            $lines[] = 'FRIST LÄUFT IN DEN NÄCHSTEN 7 TAGEN AB';
            foreach ($soon as $c) {
                $lines[] = '  ' . $this->broker($c->slug())->name . ': ' . date('d.m.Y', (int)$c->d['due_at'])
                    . ($c->status() === Status::Reminded ? ' (Nachfrist, danach Beschwerde-Entwurf)' : ' (danach Erinnerung)');
            }
        }

        $nextCycle = array_filter($current, static fn (CaseFile $c) => $c->status() === Status::Done);
        if ($nextCycle !== []) {
            $next = min(array_map(static fn (CaseFile $c) => (int)$c->d['next_cycle_at'], $nextCycle));
            $lines[] = '';
            $lines[] = 'Nächste Runde frühestens am ' . date('d.m.Y', $next) . '.';
        }

        $lines[] = '';
        $lines[] = 'TECHNIK';
        $lastRun = $this->store->meta('last_run_at');
        $lines[] = '  Letzter Lauf:     ' . ($lastRun ? date('d.m.Y H:i', (int)$lastRun) : 'nie');
        if ((string)$this->config->get('imap.host') === '') {
            $lines[] = '  Posteingang:      NICHT EINGERICHTET. Antworten werden nicht ausgewertet, alles läuft in die Erinnerung.';
        } else {
            $ok = $this->store->meta('inbox_ok_at');
            $lines[] = '  Posteingang:      ' . ($ok ? 'zuletzt gelesen ' . date('d.m.Y H:i', (int)$ok) : 'noch nie erfolgreich gelesen');
            if ($this->store->meta('inbox_fail_since') !== null) {
                $lines[] = '                    FEHLER seit ' . date('d.m.Y H:i', (int)$this->store->meta('inbox_fail_since'));
            }
        }
        $unmatched = (array)$this->store->meta('unmatched', []);
        $unmatched = array_filter($unmatched, static fn ($u) => (int)$u['at'] >= $weekAgo);
        if ($unmatched !== []) {
            $lines[] = '  Nicht zugeordnet: ' . count($unmatched) . ' Mail(s) diese Woche, z. B. „' . $unmatched[array_key_first($unmatched)]['subject'] . '“';
        }
        foreach ($warnings as $w) {
            $lines[] = '  Warnung: ' . $w;
        }

        return $this->send('Wochenübersicht', implode("\n", $lines) . $this->footer());
    }

    /** @param list<string> $errors */
    public function inboxDown(int $since, array $errors): ?string
    {
        $text = 'Der Posteingang lässt sich seit ' . date('d.m.Y H:i', $since) . " nicht mehr lesen.\n\n"
            . "Solange das so bleibt, werden Antworten nicht ausgewertet. Das kann zu Erinnerungen an Firmen führen, die längst geantwortet haben.\n\n"
            . "Letzter Fehler:\n  " . implode("\n  ", $errors) . "\n\n"
            . 'Prüfen mit: php bin/selftest.php --imap' . $this->footer();
        return $this->send('Posteingang nicht erreichbar', $text);
    }

    public function caseBlock(CaseFile $case, bool $full): string
    {
        $b = $this->broker($case->slug());
        $att = $case->attention() ?? Attention::Unknown;
        $lines = [
            "{$b->name}  [{$case->ref()}]",
            '  ' . $att->label(),
            '  → ' . $att->advice(),
        ];
        $last = $case->replies() === [] ? null : end($case->d['replies']);
        if ($last !== null && !in_array($att, [Attention::Manual, Attention::Escalation, Attention::SendFailed], true)) {
            $lines[] = '';
            $lines[] = '  Antwort von ' . $last['from'] . ' am ' . date('d.m.Y', (int)$last['at']) . ':';
            $lines[] = '  Betreff: ' . $last['subject'];
            if ($full) {
                foreach (explode("\n", (string)$last['excerpt']) as $l) {
                    $lines[] = '  | ' . $l;
                }
            }
        }
        if ($att === Attention::Manual) {
            if ($b->webform !== '') {
                $lines[] = '  Formular: ' . $b->webform;
            } elseif ($b->address !== '') {
                $lines[] = '  Anschrift: ' . str_replace("\n", ', ', $b->address);
            }
            if ($full) {
                $file = '';
                foreach ($case->d['letters'] as $l) {
                    $file = $l['file'];
                }
                $lines[] = '';
                $lines[] = '  Text zum Einfügen:';
                $lines[] = '';
                $lines[] = Runner::bodyOf($this->store->readLetter($file));
            }
        }
        if ($att === Attention::Escalation && $full && isset($case->d['complaint_file'])) {
            $stored = $this->store->readLetter((string)$case->d['complaint_file']);
            $authority = $this->registry->authority((string)$this->config->get('authority'));
            $lines[] = '';
            if ($authority === null) {
                $lines[] = "  Keine Aufsichtsbehörde eingestellt ('authority' in config.php). Zuständig ist die Behörde deines Bundeslandes.";
            } else {
                $lines[] = '  An: ' . $authority['name'] . ($authority['email'] ?? '' ? ' <' . $authority['email'] . '>' : '');
                if (!empty($authority['webform'])) {
                    $lines[] = '  oder Formular: ' . $authority['webform'];
                }
            }
            $lines[] = '';
            $lines[] = '  Entwurf:';
            $lines[] = '';
            $lines[] = $stored;
        }
        return implode("\n", $lines);
    }

    private function broker(string $slug): Broker
    {
        return $this->registry->find($slug) ?? Broker::fromRecord(['slug' => $slug, 'name' => $slug]);
    }

    private function footer(): string
    {
        $url = (string)$this->config->get('dashboard_url', '');
        return "\n\n--\n" . ($url !== '' ? "Übersicht: $url\n" : '') . 'Status auf der Kommandozeile: php bin/status.php';
    }

    private function send(string $subject, string $text): ?string
    {
        $to = (string)($this->config->get('notify.to') ?: $this->config->get('smtp.from'));
        if ($to === '') {
            return "Kein Empfänger: 'notify.to' fehlt.";
        }
        $prefix = (string)$this->config->get('notify.subject_prefix', '[DATENSCHUTZ]');
        return $this->sender->send(new OutgoingMessage(
            to: [$to],
            subject: trim("$prefix $subject"),
            text: $text,
            messageId: OutgoingMessage::newId((string)$this->config->get('smtp.from')),
            autoSubmitted: true,
        ));
    }
}
