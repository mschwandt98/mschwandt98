<?php
declare(strict_types=1);

namespace Optout\Web;

use Optout\Cases\CaseFile;
use Optout\Cases\Status;
use Optout\Cases\Store;
use Optout\Config;
use Optout\Engine\Actions;
use Optout\Engine\Runner;
use Optout\Registry\Registry;

/**
 * Uebersicht im Browser, mit den Knoepfen aus Actions. Keine Sitzung, kein
 * JavaScript: Der Schluessel steht in der URL bzw. im Formular, jede Aktion
 * ist ein POST.
 */
final class Dashboard
{
    public function __construct(
        private readonly Config $config,
        private readonly Store $store,
        private readonly Registry $registry,
        private readonly string $key,
    ) {
    }

    public function handlePost(array $post, int $now): string
    {
        $ref = strtoupper((string)($post['ref'] ?? ''));
        $action = (string)($post['action'] ?? '');
        if (!$this->store->acquireLock()) {
            return 'Gerade läuft ein Durchgang. Bitte in einer Minute noch einmal.';
        }
        try {
            $error = (new Actions($this->config, $this->store))->apply($ref, $action, $now);
            if ($error !== null) {
                return $error;
            }
            $this->store->save();
            return "$ref: " . (Actions::ALL[$action] ?? $action);
        } finally {
            $this->store->releaseLock();
        }
    }

    public function render(string $flash, string $view): string
    {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $selected = $this->registry->selected($this->config);
        $rows = [];
        foreach ($selected as $slug => $b) {
            $c = $this->store->currentFor($slug);
            $rows[] = ['broker' => $b, 'case' => $c];
        }
        // Wer dich braucht, steht oben.
        $order = [Status::Attention->value => 0, Status::Reminded->value => 1, Status::Waiting->value => 2,
                  Status::Queued->value => 3, Status::Done->value => 4, Status::Ignored->value => 5];
        usort($rows, static fn ($a, $b) => [($a['case'] ? $order[$a['case']->d['status']] : 3), $a['broker']->name]
            <=> [($b['case'] ? $order[$b['case']->d['status']] : 3), $b['broker']->name]);

        $counts = [];
        foreach ($rows as $r) {
            if ($r['case']) {
                $counts[$r['case']->status()->label()] = ($counts[$r['case']->status()->label()] ?? 0) + 1;
            }
        }

        $out = $this->head();
        $out .= '<header><h1>Datenbroker-Löschung</h1><p class="meta">'
            . ($this->config->live() ? '' : '<strong class="warn">Testmodus: Es wird nichts verschickt.</strong> ')
            . count($selected) . ' Unternehmen · ';
        $parts = [];
        foreach ($counts as $label => $n) {
            $parts[] = $h("$n $label");
        }
        $out .= implode(' · ', $parts);
        $last = $this->store->meta('last_run_at');
        $out .= '<br>Letzter Lauf: ' . ($last ? date('d.m.Y H:i', (int)$last) : 'nie');
        $inbox = $this->store->meta('inbox_ok_at');
        $out .= ' · Posteingang: ' . ((string)$this->config->get('imap.host') === '' ? '<span class="warn">nicht eingerichtet</span>'
            : ($inbox ? 'gelesen ' . date('d.m.Y H:i', (int)$inbox) : '<span class="warn">noch nie gelesen</span>'));
        $out .= '</p></header>';
        if ($flash !== '') {
            $out .= '<p class="flash">' . $h($flash) . '</p>';
        }

        if ($view !== '') {
            $case = $this->store->case(strtoupper($view));
            $out .= $case ? $this->detail($case) : '<p>Vorgang nicht gefunden.</p>';
            return $out . '</main></body></html>';
        }

        $out .= '<table><thead><tr><th>Unternehmen</th><th>Art</th><th>Status</th><th>Frist</th><th>Letzte Antwort</th><th></th></tr></thead><tbody>';
        foreach ($rows as ['broker' => $b, 'case' => $c]) {
            if ($c === null) {
                $out .= '<tr><td>' . $h($b->name) . '</td><td colspan="5" class="muted">wird beim nächsten Lauf angelegt</td></tr>';
                continue;
            }
            $last = $c->replies() === [] ? null : end($c->d['replies']);
            $cls = 's-' . $c->d['status'];
            $out .= '<tr class="' . $cls . '">'
                . '<td><a href="?' . $this->q(['view' => $c->ref()]) . '">' . $h($b->name) . '</a><br><span class="muted">' . $h($c->ref()) . '</span></td>'
                . '<td>' . $h($c->kind()->label()) . '</td>'
                . '<td><span class="badge">' . $h($c->status()->label()) . '</span>'
                . ($c->attention() ? '<br><span class="muted">' . $h($c->attention()->label()) . '</span>' : '') . '</td>'
                . '<td>' . ($c->int('due_at') && $c->status()->open() ? date('d.m.Y', (int)$c->d['due_at']) : ($c->int('next_cycle_at') ? '<span class="muted">neu ab ' . date('d.m.Y', (int)$c->d['next_cycle_at']) . '</span>' : '')) . '</td>'
                . '<td>' . ($last ? date('d.m.', (int)$last['at']) . ' ' . $h((string)$last['verdict']) : '') . '</td>'
                . '<td class="actions">' . $this->buttons($c) . '</td></tr>';
        }
        $out .= '</tbody></table>';
        foreach ($this->registry->warnings() as $w) {
            $out .= '<p class="muted">Warnung: ' . $h($w) . '</p>';
        }
        return $out . '</main></body></html>';
    }

    private function detail(CaseFile $c): string
    {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $b = $this->registry->find($c->slug());
        $out = '<p><a href="?' . $this->q([]) . '">← Übersicht</a></p>';
        $out .= '<h2>' . $h($b?->name ?? $c->slug()) . ' <span class="muted">' . $h($c->ref()) . '</span></h2>';
        $out .= '<p>' . $h($c->kind()->label()) . ', Runde ' . (int)$c->d['cycle'] . ' · <span class="badge">' . $h($c->status()->label()) . '</span>';
        if ($c->attention()) {
            $out .= '<br><strong>' . $h($c->attention()->label()) . '.</strong> ' . $h($c->attention()->advice());
        }
        $out .= '</p><div class="actions">' . $this->buttons($c) . '</div>';
        if ($b !== null) {
            $out .= '<p class="muted">' . $h($b->email ?: 'keine E-Mail') . ($b->webform !== '' ? ' · <a href="' . $h($b->webform) . '" rel="noreferrer" target="_blank">Formular</a>' : '')
                . ($b->address !== '' ? ' · ' . $h(str_replace("\n", ', ', $b->address)) : '') . '</p>';
            foreach ($b->comments as $comment) {
                $out .= '<p class="muted">Hinweis: ' . $h($comment) . '</p>';
            }
        }
        foreach ($c->d['notes'] as $n) {
            $out .= '<p class="warn">' . $h($n) . '</p>';
        }
        $out .= '<h3>Verlauf</h3><ul>';
        foreach ($c->d['history'] as $e) {
            $out .= '<li>' . date('d.m.Y H:i', (int)$e['at']) . ' – ' . $h((string)$e['event']) . '</li>';
        }
        $out .= '</ul>';
        foreach ($c->replies() as $r) {
            $out .= '<h3>Antwort vom ' . date('d.m.Y', (int)$r['at']) . ' <span class="muted">' . $h((string)$r['verdict']) . '</span></h3>'
                . '<p class="muted">' . $h((string)$r['from']) . ' · ' . $h((string)$r['subject']) . '</p>'
                . '<pre>' . $h((string)$r['excerpt']) . '</pre>';
        }
        $files = array_column($c->d['letters'], 'file');
        if (isset($c->d['complaint_file'])) {
            $files[] = $c->d['complaint_file'];
        }
        foreach (array_unique($files) as $file) {
            $out .= '<h3>' . $h(basename((string)$file)) . '</h3><pre>' . $h($this->store->readLetter((string)$file)) . '</pre>';
        }
        return $out;
    }

    private function buttons(CaseFile $c): string
    {
        $allowed = match ($c->status()) {
            Status::Attention => ['done', 'wait', 'resend', 'ignore'],
            Status::Waiting, Status::Reminded => ['done', 'ignore'],
            Status::Queued => ['ignore'],
            Status::Done => ['reopen'],
            Status::Ignored => ['reopen'],
        };
        $out = '';
        foreach ($allowed as $a) {
            $out .= '<form method="post" action="?' . $this->q([]) . '">'
                . '<input type="hidden" name="key" value="' . htmlspecialchars($this->key, ENT_QUOTES) . '">'
                . '<input type="hidden" name="ref" value="' . htmlspecialchars($c->ref(), ENT_QUOTES) . '">'
                . '<button name="action" value="' . $a . '">' . htmlspecialchars(Actions::ALL[$a]) . '</button></form>';
        }
        return $out;
    }

    private function q(array $extra): string
    {
        return http_build_query(['key' => $this->key] + $extra, '', '&amp;');
    }

    private function head(): string
    {
        return <<<HTML
        <!doctype html><html lang="de"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>Datenbroker-Löschung</title>
        <style>
        :root{--bg:#fff;--fg:#1d1d1f;--muted:#6e6e73;--line:#e5e5ea;--accent:#0b57d0;--warn:#b3261e;--att:#fff4e5;--done:#eef7ee}
        @media (prefers-color-scheme:dark){:root{--bg:#141416;--fg:#ececf0;--muted:#9a9aa2;--line:#2c2c31;--accent:#8ab4f8;--warn:#f28b82;--att:#3a2a12;--done:#16291a}}
        body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.45 system-ui,-apple-system,Segoe UI,sans-serif}
        main{max-width:1100px;margin:0 auto;padding:16px}
        h1{font-size:22px;margin:8px 0}.meta,.muted{color:var(--muted)}.warn{color:var(--warn)}
        a{color:var(--accent)}table{width:100%;border-collapse:collapse}
        th,td{text-align:left;padding:8px 6px;border-bottom:1px solid var(--line);vertical-align:top}
        tr.s-attention{background:var(--att)}tr.s-done td{color:var(--muted)}
        .badge{display:inline-block;padding:1px 8px;border:1px solid var(--line);border-radius:10px;font-size:13px}
        .actions form{display:inline}.actions button{margin:2px;padding:4px 10px;border:1px solid var(--line);border-radius:6px;background:transparent;color:var(--fg);cursor:pointer}
        .actions button:hover{border-color:var(--accent)}
        .flash{padding:8px 12px;border-left:3px solid var(--accent);background:var(--att)}
        pre{white-space:pre-wrap;background:color-mix(in srgb,var(--line) 40%,transparent);padding:12px;border-radius:6px;overflow-x:auto}
        @media (max-width:700px){thead{display:none}td{display:block;border:0;padding:2px 0}tr{display:block;border-bottom:1px solid var(--line);padding:8px 0}}
        </style></head><body><main>
        HTML;
    }
}
