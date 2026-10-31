<?php
declare(strict_types=1);

namespace Optout\Engine;

use Optout\Cases\CaseFile;
use Optout\Cases\Status;
use Optout\Cases\Store;
use Optout\Config;

/**
 * Was du von Hand mit einem Vorgang machen kannst -- gemeinsam fuer
 * Kommandozeile (bin/case.php) und Weboberflaeche.
 */
final class Actions
{
    public const ALL = [
        'done'   => 'erledigt',
        'wait'   => 'wartet wieder',
        'resend' => 'erneut senden',
        'ignore' => 'ignorieren',
        'reopen' => 'wieder aufnehmen',
    ];

    public function __construct(private readonly Config $config, private readonly Store $store)
    {
    }

    /** Gibt bei Erfolg null zurueck, sonst die Fehlermeldung. */
    public function apply(string $ref, string $action, int $now): ?string
    {
        $case = $this->store->case($ref);
        if ($case === null) {
            return "Vorgang $ref nicht gefunden.";
        }
        $status = $case->status();
        switch ($action) {
            case 'done':
                $days = (array)$this->config->get('schedule.recheck_days', []);
                $case->close($now, (int)($days[$case->kind()->value] ?? 90), 'von Hand als erledigt markiert');
                break;
            case 'wait':
                // Z. B. nach Identitaetsnachweis oder Formular: Frist neu ab jetzt.
                $grace = (int)$this->config->get('schedule.grace_days', 3);
                $case->d['due_at'] = max((int)($case->d['due_at'] ?? 0), CaseFile::deadline($now, 1, $grace));
                if ($case->d['sent_at'] === null) {
                    $case->d['sent_at'] = $now; // per Formular/Brief gestellt
                }
                $case->setStatus(Status::Waiting, $now, 'von Hand: wartet wieder auf Antwort');
                break;
            case 'resend':
                $case->d['send_failures'] = 0;
                $case->setStatus(Status::Queued, $now, 'von Hand: erneut senden');
                break;
            case 'ignore':
                $case->setStatus(Status::Ignored, $now, 'von Hand ignoriert');
                break;
            case 'reopen':
                if ($status !== Status::Ignored && $status !== Status::Done) {
                    return 'Nur erledigte oder ignorierte Vorgänge lassen sich wieder aufnehmen.';
                }
                $case->setStatus(Status::Queued, $now, 'von Hand wieder aufgenommen');
                break;
            default:
                return "Unbekannte Aktion '$action'. Möglich: " . implode(', ', array_keys(self::ALL));
        }
        $this->store->put($case);
        $this->store->appendEvent(['type' => 'manual', 'ref' => $ref, 'action' => $action]);
        return null;
    }
}
