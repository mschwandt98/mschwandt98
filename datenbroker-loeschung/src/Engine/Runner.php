<?php
declare(strict_types=1);

namespace Optout\Engine;

use Optout\Cases\Attention;
use Optout\Cases\CaseFile;
use Optout\Cases\Classifier;
use Optout\Cases\Status;
use Optout\Cases\Store;
use Optout\Cases\Verdict;
use Optout\Config;
use Optout\Letters\Kind;
use Optout\Letters\Letters;
use Optout\Mail\IncomingMessage;
use Optout\Mail\Inbox;
use Optout\Mail\OutgoingMessage;
use Optout\Mail\Sender;
use Optout\Registry\Broker;
use Optout\Registry\Registry;

/**
 * Ein Lauf, angestossen vom Cron:
 *
 *   1. fuer jedes ausgewaehlte Unternehmen einen Vorgang anlegen (oder die
 *      naechste Runde starten, wenn die letzte lange genug her ist)
 *   2. neue Antworten abholen, zuordnen und einordnen
 *   3. Fristen pruefen: erinnern, dann eskalieren
 *   4. wartende Anfragen verschicken -- gedrosselt
 *   5. dich benachrichtigen, wenn du dran bist; einmal pro Woche Uebersicht
 *
 * Die Reihenfolge ist Absicht: Erst Antworten lesen, dann Fristen pruefen --
 * sonst bekaeme eine Firma eine Erinnerung, deren Antwort im selben Lauf
 * eintrifft.
 */
final class Runner
{
    private Letters $letters;
    /** @var list<string> */
    private array $log = [];
    /** @var list<string> */
    private array $errors = [];
    /** @var list<CaseFile> neu auf "du bist dran" gewechselt */
    private array $attention = [];
    /** @var list<array{case:CaseFile, verdict:Verdict}> */
    private array $closed = [];
    private int $sent = 0;
    private int $reminded = 0;
    private int $replies = 0;

    public function __construct(
        private readonly Config $config,
        private readonly Store $store,
        private readonly Registry $registry,
        private readonly Sender $sender,
        private readonly ?Inbox $inbox,
    ) {
        $this->letters = new Letters($config);
    }

    /** @return array{sent:int, reminded:int, replies:int, attention:int, errors:list<string>, log:list<string>} */
    public function run(int $now, bool $forceDigest = false): array
    {
        $this->store->setMeta('last_run_at', $now);

        $this->ensureCases($now);
        $this->processInbox($now);
        $this->checkDeadlines($now);
        $this->sendQueued($now);

        $notifier = new Notifier($this->config, $this->sender, $this->store, $this->registry);
        if ($this->attention !== []) {
            $err = $notifier->attention($this->attention, $now);
            if ($err !== null) {
                $this->errors[] = "Benachrichtigung fehlgeschlagen: $err";
            }
        }
        $this->checkInboxHealth($notifier, $now);
        if ($forceDigest || $notifier->digestDue($now)) {
            $err = $notifier->digest($now, $this->registry->selected($this->config), $this->registry->warnings());
            if ($err === null) {
                $this->store->setMeta('last_digest_at', $now);
                $this->log[] = 'Wochenübersicht verschickt.';
            } else {
                $this->errors[] = "Wochenübersicht fehlgeschlagen: $err";
            }
        }

        if ($this->errors !== []) {
            $this->store->appendEvent(['type' => 'errors', 'errors' => $this->errors]);
        }
        $this->store->save();

        return [
            'sent'      => $this->sent,
            'reminded'  => $this->reminded,
            'replies'   => $this->replies,
            'attention' => count($this->attention),
            'errors'    => $this->errors,
            'log'       => $this->log,
        ];
    }

    public function kindFor(Broker $broker): Kind
    {
        $bySlug = (array)$this->config->get('brokers.kind_by_slug', []);
        if (isset($bySlug[$broker->slug])) {
            return Kind::from((string)$bySlug[$broker->slug]);
        }
        $byCategory = (array)$this->config->get('brokers.kind_by_category', []);
        foreach ($broker->categories as $cat) {
            if (isset($byCategory[$cat])) {
                return Kind::from((string)$byCategory[$cat]);
            }
        }
        return Kind::from((string)$this->config->get('brokers.default_kind', 'erasure'));
    }

    // ------------------------------------------------------------------ 1

    private function ensureCases(int $now): void
    {
        foreach ($this->registry->selected($this->config) as $slug => $broker) {
            $current = $this->store->currentFor($slug);
            if ($current === null) {
                $case = CaseFile::open($slug, $this->kindFor($broker), 1, $now);
            } elseif ($current->status() === Status::Done && ($current->int('next_cycle_at') ?? PHP_INT_MAX) <= $now) {
                $case = CaseFile::open($slug, $this->kindFor($broker), (int)$current->d['cycle'] + 1, $now);
                $this->log[] = "{$broker->name}: neue Runde " . $case->d['cycle'] . '.';
            } else {
                continue;
            }
            $this->store->put($case);
            $this->store->appendEvent(['type' => 'case_opened', 'ref' => $case->ref(), 'slug' => $slug, 'cycle' => $case->d['cycle']]);
        }
    }

    // ------------------------------------------------------------------ 2

    private function processInbox(int $now): void
    {
        if ($this->inbox === null) {
            return;
        }
        try {
            $messages = $this->inbox->fetchNew();
        } catch (\Throwable $e) {
            $this->errors[] = 'Posteingang: ' . $e->getMessage();
            if ($this->store->meta('inbox_fail_since') === null) {
                $this->store->setMeta('inbox_fail_since', $now);
            }
            return;
        }
        $this->store->setMeta('inbox_ok_at', $now);
        $this->store->setMeta('inbox_fail_since', null);
        $this->store->setMeta('inbox_fail_notified', null);

        $own = array_filter(array_map('strtolower', [
            (string)$this->config->get('smtp.from'),
            (string)$this->config->get('notify.to'),
        ]));

        foreach ($messages as ['message' => $msg, 'raw' => $raw]) {
            if ($this->store->seenMessage($msg->messageId)) {
                continue;
            }
            $this->store->markMessageSeen($msg->messageId, $now);
            if (in_array($msg->from, $own, true) && !$msg->bounce) {
                continue; // eigene Kopie (Bcc) oder eigene Benachrichtigung
            }
            $case = $this->correlate($msg);
            if ($case === null) {
                $this->rememberUnmatched($msg, $now);
                continue;
            }
            $this->applyReply($case, $msg, $raw, $now);
        }
    }

    public function correlate(IncomingMessage $msg): ?CaseFile
    {
        $cases = $this->store->cases();
        $byMessageId = [];
        foreach ($cases as $ref => $case) {
            foreach ($case->messageIds() as $id) {
                $byMessageId[$id] = $ref;
            }
        }

        // 1. Thread-Header -- der sicherste Weg.
        foreach (array_merge([$msg->inReplyTo], $msg->references) as $id) {
            if ($id !== '' && isset($byMessageId[$id])) {
                return $cases[$byMessageId[$id]];
            }
        }
        // 2. Unsere Referenz im Betreff oder im zitierten Text.
        foreach (CaseFile::refsIn($msg->subject . "\n" . $msg->text) as $ref) {
            if (isset($cases[$ref])) {
                return $cases[$ref];
            }
        }
        // 3. Plus-Adresse (datenschutz+db-xxxxxx@...).
        foreach ($msg->recipients as $rcpt) {
            if (preg_match('/\+(db-[0-9a-z]{6})@/i', $rcpt, $m) && isset($cases[strtoupper($m[1])])) {
                return $cases[strtoupper($m[1])];
            }
        }
        // 4. Bounce: Message-ID der Anfrage steht im zurueckgeschickten Original.
        if ($msg->bounce) {
            foreach ($byMessageId as $id => $ref) {
                if (str_contains($msg->text, $id)) {
                    return $cases[$ref];
                }
            }
            return null;
        }
        // 5. Absenderdomain. Viele Firmen antworten aus einem Ticketsystem,
        // ohne Thread-Header und ohne den Betreff zu uebernehmen.
        $domain = \Optout\Registry\Broker::registrable($msg->fromDomain());
        if ($domain === '') {
            return null;
        }
        $candidates = [];
        foreach ($this->registry->all() as $broker) {
            if (in_array($domain, $broker->domains(), true)) {
                $current = $this->store->currentFor($broker->slug);
                if ($current !== null) {
                    $candidates[] = $current;
                }
            }
        }
        // Mehrere Firmen mit derselben Domain (Konzern): nur zuordnen, wenn
        // genau eine davon einen offenen Vorgang hat.
        $open = array_values(array_filter($candidates, static fn (CaseFile $c) => $c->status()->open()));
        if (count($open) === 1) {
            return $open[0];
        }
        return count($candidates) === 1 ? $candidates[0] : null;
    }

    private function applyReply(CaseFile $case, IncomingMessage $msg, string $raw, int $now): void
    {
        ['verdict' => $verdict, 'evidence' => $evidence] = Classifier::classify($msg);
        $this->replies++;
        $broker = $this->registry->find($case->slug());
        $name = $broker?->name ?? $case->slug();

        $case->addReply([
            'at'         => $now,
            'date'       => $msg->date,
            'from'       => $msg->from,
            'subject'    => $msg->subject,
            'verdict'    => $verdict->value,
            'evidence'   => $evidence,
            'excerpt'    => $msg->excerpt(),
            'message_id' => $msg->messageId,
            'file'       => $this->store->saveReply($case->ref(), $raw),
        ]);
        // Antworten im selben Thread fortfuehren.
        if ($msg->messageId !== '') {
            $case->d['message_ids'][] = $msg->messageId;
        }

        $status = $case->status();
        $label = "Antwort: {$verdict->label()}" . ($evidence !== '' ? " („{$evidence}“)" : '');

        if ($status === Status::Ignored) {
            $case->log($now, $label);
        } elseif ($verdict->closes()) {
            if ($status !== Status::Done) {
                $case->close($now, $this->recheckDays($case->kind()), $label);
                $this->closed[] = ['case' => $case, 'verdict' => $verdict];
            } else {
                $case->log($now, $label);
            }
        } elseif ($verdict === Verdict::Acknowledged) {
            $case->d['acknowledged'] = true;
            $case->log($now, $label);
        } elseif ($verdict === Verdict::Extended) {
            if (in_array($status, [Status::Waiting, Status::Reminded], true) && $case->int('sent_at') !== null) {
                $grace = (int)$this->config->get('schedule.grace_days', 3);
                $case->d['due_at'] = max((int)$case->d['due_at'], CaseFile::deadline((int)$case->d['sent_at'], 3, $grace));
                if ($status === Status::Reminded) {
                    // Die Verlaengerung kam als Antwort auf die Erinnerung --
                    // dann gilt wieder die normale Frist, keine Eskalation.
                    $case->d['status'] = Status::Waiting->value;
                }
            }
            $case->log($now, $label);
        } elseif ($status === Status::Done && $verdict === Verdict::Unknown) {
            // Nachzuegler zu einem erledigten Vorgang: festhalten, nicht neu oeffnen.
            $case->log($now, $label);
        } else {
            $case->setStatus(Status::Attention, $now, $label, Attention::fromVerdict($verdict));
            $this->attention[] = $case;
        }

        $this->store->put($case);
        $this->store->appendEvent(['type' => 'reply', 'ref' => $case->ref(), 'slug' => $case->slug(),
            'verdict' => $verdict->value, 'evidence' => $evidence, 'from' => $msg->from]);
        $this->log[] = "$name: $label";
    }

    private function rememberUnmatched(IncomingMessage $msg, int $now): void
    {
        $list = (array)$this->store->meta('unmatched', []);
        array_unshift($list, ['at' => $now, 'from' => $msg->from, 'subject' => $msg->subject]);
        $this->store->setMeta('unmatched', array_slice($list, 0, 20));
    }

    // ------------------------------------------------------------------ 3

    private function checkDeadlines(int $now): void
    {
        foreach ($this->store->cases() as $case) {
            $due = $case->int('due_at');
            if ($due === null || $due > $now) {
                continue;
            }
            $broker = $this->registry->find($case->slug());
            if ($case->status() === Status::Waiting) {
                if (!$this->config->live()) {
                    continue;
                }
                if ($broker === null || !$broker->canEmail()) {
                    $case->setStatus(Status::Attention, $now, 'Frist abgelaufen, Erinnerung nicht per Mail möglich', Attention::Manual);
                    $this->attention[] = $case;
                    $this->store->put($case);
                    continue;
                }
                $this->sendReminder($case, $broker, $now);
            } elseif ($case->status() === Status::Reminded) {
                $this->escalate($case, $broker, $now);
            }
        }
    }

    private function sendReminder(CaseFile $case, Broker $broker, int $now): void
    {
        $original = $this->store->readLetter((string)$case->firstLetterFile());
        $letter = $this->letters->reminder($broker, $case->kind(), $case->ref(), (int)$case->d['sent_at'], self::bodyOf($original));
        $id = OutgoingMessage::newId((string)$this->config->get('smtp.from'));
        $error = $this->sender->send(new OutgoingMessage(
            to: [$broker->email],
            subject: $letter['subject'],
            text: $letter['body'],
            messageId: $id,
            replyTo: $this->replyTo($case->ref()),
            bcc: $this->bcc(),
            inReplyTo: $case->lastMessageId(),
            references: $case->messageIds(),
        ));
        if ($error !== null) {
            $this->sendFailed($case, $now, "Erinnerung an {$broker->name}: $error");
            return;
        }
        $file = $this->store->saveLetter($case->ref(), 'erinnerung', self::envelope($broker->email, $letter['subject'], $letter['body'], $now));
        $case->markSent('reminder', $id, $file, $now);
        $grace = (int)$this->config->get('schedule.grace_days', 3);
        $case->d['due_at'] = $now + ((int)$this->config->get('schedule.reminder_days', 14) + $grace) * 86400;
        $case->setStatus(Status::Reminded, $now, 'Erinnerung verschickt');
        $this->store->put($case);
        $this->store->appendEvent(['type' => 'reminder_sent', 'ref' => $case->ref(), 'slug' => $case->slug()]);
        $this->reminded++;
        $this->log[] = "{$broker->name}: Erinnerung verschickt.";
    }

    private function escalate(CaseFile $case, ?Broker $broker, int $now): void
    {
        $broker ??= Broker::fromRecord(['slug' => $case->slug(), 'name' => $case->slug()]);
        $authority = $this->registry->authority((string)$this->config->get('authority'));
        $original = $this->store->readLetter((string)$case->firstLetterFile());
        $draft = $this->letters->complaint($broker, $case->kind(), $case->ref(), (int)$case->d['sent_at'],
            (int)($case->d['reminded_at'] ?? $now), self::bodyOf($original), $authority);
        $file = $this->store->saveLetter($case->ref(), 'beschwerde', self::envelope($draft['to'], $draft['subject'], $draft['body'], $now));
        $case->d['complaint_file'] = $file;
        $case->setStatus(Status::Attention, $now, 'Nachfrist abgelaufen, Beschwerde-Entwurf erstellt', Attention::Escalation);
        $this->attention[] = $case;
        $this->store->put($case);
        $this->store->appendEvent(['type' => 'escalated', 'ref' => $case->ref(), 'slug' => $case->slug()]);
        $this->log[] = "{$broker->name}: eskaliert, Beschwerde-Entwurf liegt bereit.";
    }

    // ------------------------------------------------------------------ 4

    private function sendQueued(int $now): void
    {
        $queued = array_filter($this->store->cases(), static fn (CaseFile $c) => $c->status() === Status::Queued);
        if ($queued === []) {
            return;
        }
        if (!$this->config->live()) {
            $this->log[] = 'Testmodus: ' . count($queued) . " Anfrage(n) warten auf 'live' => true.";
            return;
        }
        uasort($queued, static fn (CaseFile $a, CaseFile $b) => $a->d['created_at'] <=> $b->d['created_at']);

        $max = (int)$this->config->get('schedule.max_sends_per_run', 5);
        $minGap = (int)$this->config->get('schedule.min_resend_days', 7) * 86400;
        $grace = (int)$this->config->get('schedule.grace_days', 3);

        // Erst alles, was nicht per Mail geht: Brief vorbereiten, dich
        // benachrichtigen. Das zaehlt nicht gegen die Drossel -- es geht
        // nichts raus -- und soll nicht hinter ihr warten.
        foreach ($queued as $ref => $case) {
            $broker = $this->registry->find($case->slug());
            if ($broker === null) {
                $case->setStatus(Status::Ignored, $now, 'Unternehmen nicht mehr in der Datenbank');
                $this->store->put($case);
                unset($queued[$ref]);
                continue;
            }
            if (!$broker->canEmail()) {
                $letter = $this->letters->request($broker, $case->kind(), $case->ref());
                $file = $this->store->saveLetter($case->ref(), 'brief', self::envelope(
                    $broker->webform !== '' ? $broker->webform : $broker->address, $letter['subject'], $letter['body'], $now));
                $case->d['letters'][] = ['type' => 'manual', 'at' => $now, 'file' => $file, 'message_id' => ''];
                $case->setStatus(Status::Attention, $now, 'kein E-Mail-Kontakt, Brief vorbereitet', Attention::Manual);
                $this->attention[] = $case;
                $this->store->put($case);
                unset($queued[$ref]);
            }
        }

        foreach ($queued as $case) {
            $broker = $this->registry->find($case->slug());
            if ($this->sent >= $max) {
                break;
            }
            $lastSent = $this->lastSentTo($case->slug());
            if ($lastSent !== null && $now - $lastSent < $minGap) {
                continue;
            }

            $letter = $this->letters->request($broker, $case->kind(), $case->ref());
            $id = OutgoingMessage::newId((string)$this->config->get('smtp.from'));
            $error = $this->sender->send(new OutgoingMessage(
                to: [$broker->email],
                subject: $letter['subject'],
                text: $letter['body'],
                messageId: $id,
                replyTo: $this->replyTo($case->ref()),
                bcc: $this->bcc(),
            ));
            if ($error !== null) {
                $this->sendFailed($case, $now, "Anfrage an {$broker->name}: $error");
                // Ein SMTP-Fehler betrifft meist alle Mails -- nicht weiter
                // gegen die Wand laufen, im naechsten Lauf erneut versuchen.
                break;
            }

            $file = $this->store->saveLetter($case->ref(), 'anfrage', self::envelope($broker->email, $letter['subject'], $letter['body'], $now));
            $case->markSent('request', $id, $file, $now);
            $case->d['due_at'] = CaseFile::deadline($now, 1, $grace);
            if ($letter['missing'] !== []) {
                $case->d['notes'][] = 'Fehlende Angaben (laut Datenbank verlangt): ' . implode(', ', $letter['missing']);
            }
            $case->setStatus(Status::Waiting, $now, $case->kind()->label() . ' an ' . $broker->email . ' verschickt');
            $this->store->put($case);
            $this->store->appendEvent(['type' => 'request_sent', 'ref' => $case->ref(), 'slug' => $case->slug(), 'to' => $broker->email]);
            $this->sent++;
            $this->log[] = "{$broker->name}: {$case->kind()->label()} verschickt ({$case->ref()}).";
        }
    }

    private function lastSentTo(string $slug): ?int
    {
        $last = null;
        foreach ($this->store->casesFor($slug) as $c) {
            foreach ($c->d['letters'] as $l) {
                if (in_array($l['type'], ['request', 'reminder'], true)) {
                    $last = max($last ?? 0, (int)$l['at']);
                }
            }
        }
        return $last;
    }

    private function sendFailed(CaseFile $case, int $now, string $error): void
    {
        $this->errors[] = $error;
        $case->d['send_failures'] = (int)($case->d['send_failures'] ?? 0) + 1;
        $case->log($now, 'Versand fehlgeschlagen: ' . $error);
        if ($case->d['send_failures'] >= 3) {
            $case->setStatus(Status::Attention, $now, 'Versand dreimal fehlgeschlagen', Attention::SendFailed);
            $this->attention[] = $case;
        }
        $this->store->put($case);
    }

    // ------------------------------------------------------------------ 5

    private function checkInboxHealth(Notifier $notifier, int $now): void
    {
        $since = $this->store->meta('inbox_fail_since');
        if ($since === null || $this->store->meta('inbox_fail_notified') !== null) {
            return;
        }
        if ($now - (int)$since >= 86400) {
            $err = $notifier->inboxDown((int)$since, $this->errors);
            if ($err === null) {
                $this->store->setMeta('inbox_fail_notified', $now);
            }
        }
    }

    // ------------------------------------------------------------------ Hilfen

    public function recheckDays(Kind $kind): int
    {
        $days = (array)$this->config->get('schedule.recheck_days', []);
        return (int)($days[$kind->value] ?? 90);
    }

    private function replyTo(string $ref): string
    {
        $tpl = (string)$this->config->get('smtp.reply_to', '');
        return $tpl === '' ? '' : str_replace('{ref}', strtolower($ref), $tpl);
    }

    /** @return list<string> */
    private function bcc(): array
    {
        $bcc = trim((string)$this->config->get('smtp.bcc', ''));
        return $bcc === '' ? [] : [$bcc];
    }

    /** Abgelegter Brief = kleiner Kopf + Text, damit die Datei fuer sich verstaendlich ist. */
    public static function envelope(string $to, string $subject, string $body, int $now): string
    {
        return "An: $to\nDatum: " . date('d.m.Y H:i', $now) . "\nBetreff: $subject\n\n" . $body;
    }

    public static function bodyOf(string $stored): string
    {
        $pos = strpos($stored, "\n\n");
        return $pos === false ? $stored : substr($stored, $pos + 2);
    }
}
