<?php
declare(strict_types=1);

namespace Kino\State;

/**
 * Zustandsspeicher als JSON-Datei.
 *
 * Bewusst kein SQLite: Auf Shared Hosting ist jede Extension ein Risiko, und
 * die Datenmenge ist winzig. Die Datei laesst sich per FTP herunterladen und
 * mit blossem Auge lesen -- das ist beim Debuggen in einem Jahr mehr wert als
 * eine Datenbank.
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

    private function file(): string
    {
        return $this->dir . '/state.json';
    }

    private function read(): array
    {
        $file = $this->file();
        if (!is_file($file)) {
            return ['screenings' => [], 'meta' => [], 'http_cache' => [], 'notifications' => []];
        }
        $raw = file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data)) {
            // Kaputte Datei nicht stillschweigend ueberschreiben -- beiseite
            // legen, damit man hinterher nachsehen kann, was passiert ist.
            @rename($file, $file . '.broken.' . date('Ymd-His'));
            return ['screenings' => [], 'meta' => [], 'http_cache' => [], 'notifications' => []];
        }
        $data['screenings']    ??= [];
        $data['meta']          ??= [];
        $data['http_cache']    ??= [];
        $data['notifications'] ??= [];
        return $data;
    }

    /**
     * Exklusives Lock. Gibt false zurueck, wenn bereits ein Lauf aktiv ist --
     * der Aufrufer soll sich dann sofort beenden statt zu warten.
     */
    public function acquireLock(): bool
    {
        $handle = fopen($this->dir . '/watch.lock', 'c');
        if ($handle === false) {
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        $this->lock = $handle;
        // Zustand nach dem Lock erneut lesen: Zwischen Konstruktor und Lock
        // kann ein anderer Lauf geschrieben haben.
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

    /** Atomar schreiben: erst in eine Temp-Datei, dann umbenennen. */
    public function save(): void
    {
        $file = $this->file();
        $tmp  = $file . '.tmp' . getmypid();
        $json = json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Zustand nicht serialisierbar: ' . json_last_error_msg());
        }
        if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Zustand nicht schreibbar: $file");
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

    /** @return array<string,array<string,mixed>> */
    public function screenings(): array
    {
        return $this->state['screenings'];
    }

    /** @param array<string,array<string,mixed>> $screenings */
    public function setScreenings(array $screenings): void
    {
        $this->state['screenings'] = $screenings;
    }

    public function httpCache(string $url): array
    {
        return $this->state['http_cache'][$url] ?? [];
    }

    public function setHttpCache(string $url, ?string $etag, ?string $lastModified, ?string $digest): void
    {
        $this->state['http_cache'][$url] = array_filter([
            'etag'          => $etag,
            'last_modified' => $lastModified,
            'digest'        => $digest,
            'seen_at'       => date('c'),
        ], static fn ($v) => $v !== null);
    }

    public function lastNotification(string $kind): ?int
    {
        $v = $this->state['notifications'][$kind] ?? null;
        return is_int($v) ? $v : null;
    }

    public function markNotified(string $kind, ?int $at = null): void
    {
        $this->state['notifications'][$kind] = $at ?? time();
    }

    /** Append-only-Historie. Die wichtigste Datei fuer die Fehlersuche. */
    public function appendEvent(array $event): void
    {
        $event['at'] = date('c');
        $line = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line !== false) {
            @file_put_contents($this->dir . '/events.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    public function saveSnapshot(string $name, string $body, int $keep): void
    {
        $dir = $this->dir . '/snapshots';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $safe = preg_replace('/[^a-z0-9._-]+/i', '_', $name) ?? 'snapshot';
        @file_put_contents($dir . '/' . date('Ymd-His') . '-' . substr($safe, 0, 60) . '.txt', $body);

        $files = glob($dir . '/*.txt') ?: [];
        if (count($files) > $keep) {
            sort($files);
            foreach (array_slice($files, 0, count($files) - $keep) as $old) {
                @unlink($old);
            }
        }
    }
}
