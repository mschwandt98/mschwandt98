<?php
declare(strict_types=1);

namespace Optout\Cases;

/**
 * Zustand als JSON-Datei, wie beim Kino-Watcher: kein SQLite, keine
 * Extension, per FTP herunterladbar und mit blossem Auge lesbar.
 *
 *   var/state.json     Vorgaenge, Posteingangs-Cursor, Metadaten
 *   var/events.jsonl   Append-only-Historie
 *   var/letters/       jeder verschickte Brief im Wortlaut (dein Beleg)
 */
final class Store
{
    private array $state;
    /** @var resource|null */
    private $lock = null;

    public function __construct(private readonly string $dir)
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Verzeichnis nicht anlegbar: {$this->dir}");
        }
        $this->state = $this->read();
    }

    public function dir(): string
    {
        return $this->dir;
    }

    private function file(): string
    {
        return $this->dir . '/state.json';
    }

    private static function empty(): array
    {
        return ['cases' => [], 'brokers' => [], 'inbox' => [], 'meta' => []];
    }

    private function read(): array
    {
        $file = $this->file();
        if (!is_file($file)) {
            return self::empty();
        }
        $raw = file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            // Kaputte Datei nicht stillschweigend ueberschreiben.
            @rename($file, $file . '.broken.' . date('Ymd-His'));
            return self::empty();
        }
        return array_replace(self::empty(), $data);
    }

    /** Exklusives Lock; false, wenn bereits ein Lauf aktiv ist. */
    public function acquireLock(): bool
    {
        $handle = fopen($this->dir . '/run.lock', 'c');
        if ($handle === false) {
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        $this->lock = $handle;
        $this->state = $this->read();
        return true;
    }

    public function releaseLock(): void
    {
        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    public function save(): void
    {
        $file = $this->file();
        $tmp = $file . '.tmp' . getmypid();
        $json = json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Zustand nicht serialisierbar: ' . json_last_error_msg());
        }
        if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Zustand nicht schreibbar: $file");
        }
    }

    /** @return array<string, CaseFile> */
    public function cases(): array
    {
        $out = [];
        foreach ($this->state['cases'] as $ref => $d) {
            $out[$ref] = CaseFile::fromArray($d);
        }
        return $out;
    }

    public function case(string $ref): ?CaseFile
    {
        $d = $this->state['cases'][$ref] ?? null;
        return $d === null ? null : CaseFile::fromArray($d);
    }

    public function put(CaseFile $case): void
    {
        $this->state['cases'][$case->ref()] = $case->toArray();
        // Nur eine neuere Runde wird zur aktuellen. Eine spaete Antwort auf
        // eine alte Runde darf die laufende nicht verdraengen.
        $current = $this->state['brokers'][$case->slug()]['current'] ?? null;
        $currentCycle = $current === null ? 0 : (int)($this->state['cases'][$current]['cycle'] ?? 0);
        if ($current === null || $current === $case->ref() || (int)$case->d['cycle'] > $currentCycle) {
            $this->state['brokers'][$case->slug()]['current'] = $case->ref();
        }
    }

    /** Der aktuelle (juengste) Vorgang zu einem Unternehmen. */
    public function currentFor(string $slug): ?CaseFile
    {
        $ref = $this->state['brokers'][$slug]['current'] ?? null;
        return $ref === null ? null : $this->case((string)$ref);
    }

    /** @return list<CaseFile> alle Vorgaenge zu einem Unternehmen, neueste zuerst */
    public function casesFor(string $slug): array
    {
        $list = array_values(array_filter($this->cases(), static fn (CaseFile $c) => $c->slug() === $slug));
        usort($list, static fn (CaseFile $a, CaseFile $b) => ($b->d['created_at'] <=> $a->d['created_at']) ?: ($b->d['cycle'] <=> $a->d['cycle']));
        return $list;
    }

    /** @return array{uidvalidity:int, last_uid:int}|null */
    public function inboxCursor(string $folder): ?array
    {
        $c = $this->state['inbox'][$folder] ?? null;
        return is_array($c) ? ['uidvalidity' => (int)$c['uidvalidity'], 'last_uid' => (int)$c['last_uid']] : null;
    }

    public function setInboxCursor(string $folder, int $uidvalidity, int $lastUid): void
    {
        $this->state['inbox'][$folder] = ['uidvalidity' => $uidvalidity, 'last_uid' => $lastUid];
    }

    /** Wurde diese Nachricht schon verarbeitet? Schuetzt vor Doppelzaehlung. */
    public function seenMessage(string $messageId): bool
    {
        return $messageId !== '' && isset($this->state['meta']['seen'][$messageId]);
    }

    public function markMessageSeen(string $messageId, int $now): void
    {
        if ($messageId === '') {
            return;
        }
        $this->state['meta']['seen'][$messageId] = $now;
        // Nicht endlos wachsen lassen: ein Jahr reicht.
        foreach ($this->state['meta']['seen'] as $id => $at) {
            if ($at < $now - 400 * 86400) {
                unset($this->state['meta']['seen'][$id]);
            }
        }
    }

    public function meta(string $key, mixed $default = null): mixed
    {
        return $this->state['meta'][$key] ?? $default;
    }

    public function setMeta(string $key, mixed $value): void
    {
        $this->state['meta'][$key] = $value;
    }

    public function appendEvent(array $event): void
    {
        $event = ['at' => date('c')] + $event;
        $line = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line !== false) {
            @file_put_contents($this->dir . '/events.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    /** Legt einen Brief im Wortlaut ab und gibt den relativen Pfad zurueck. */
    public function saveLetter(string $ref, string $type, string $content): string
    {
        $dir = $this->dir . '/letters/' . $ref;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Briefablage nicht anlegbar: $dir");
        }
        $n = count(glob($dir . '/*.txt') ?: []) + 1;
        $name = sprintf('%02d-%s.txt', $n, preg_replace('/[^a-z]/', '', $type));
        if (file_put_contents($dir . '/' . $name, $content) === false) {
            throw new \RuntimeException("Brief nicht speicherbar: $dir/$name");
        }
        return 'letters/' . $ref . '/' . $name;
    }

    public function readLetter(string $relative): string
    {
        if (str_contains($relative, '..')) {
            return '';
        }
        return (string)@file_get_contents($this->dir . '/' . $relative);
    }

    /** Antwort im Rohformat ablegen -- fuer die Nachpruefung einer Einordnung. */
    public function saveReply(string $ref, string $raw): string
    {
        $dir = $this->dir . '/letters/' . $ref;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return '';
        }
        $n = count(glob($dir . '/*') ?: []) + 1;
        $name = sprintf('%02d-antwort.eml', $n);
        return file_put_contents($dir . '/' . $name, $raw) === false ? '' : 'letters/' . $ref . '/' . $name;
    }
}
