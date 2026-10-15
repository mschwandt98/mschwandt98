<?php
declare(strict_types=1);

namespace Kino;

final class Config
{
    private function __construct(private readonly array $data)
    {
    }

    public static function load(?string $path = null): self
    {
        $path ??= dirname(__DIR__) . '/config/config.php';
        if (!is_file($path)) {
            throw new \RuntimeException(
                "Konfiguration fehlt: $path\n" .
                "Bitte config/config.example.php nach config/config.php kopieren und ausfuellen."
            );
        }
        $data = require $path;
        if (!is_array($data)) {
            throw new \RuntimeException("config.php muss ein Array zurueckgeben, tut es aber nicht.");
        }
        return new self(array_replace_recursive(self::defaults(), $data));
    }

    public static function fromArray(array $data): self
    {
        return new self(array_replace_recursive(self::defaults(), $data));
    }

    /** Punktnotation: $config->get('smtp.host') */
    public function get(string $key, mixed $default = null): mixed
    {
        $node = $this->data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    public function all(): array
    {
        return $this->data;
    }

    public function baseDir(): string
    {
        return dirname(__DIR__);
    }

    public function varDir(): string
    {
        return (string)$this->get('var_dir', $this->baseDir() . '/var');
    }

    public static function defaults(): array
    {
        return [
            'timezone' => 'Europe/Berlin',
            'cinema'   => [
                // Numerische Kino-Kennung der Showings-API. 1391 = Magdeburg,
                // belegt aus dem __NEXT_DATA__ der Programmseite. Leer lassen,
                // um sie automatisch ermitteln zu lassen.
                'id'           => '1391',
                'city'         => 'magdeburg',
                'name'         => 'CinemaxX Magdeburg',
                'base_url'     => 'https://www.cinemaxx.de',
                'program_path' => '/kinoprogramm/magdeburg',
            ],
            // Breit halten: ein Fehlalarm kostet eine Mail, ein verpasster
            // Treffer kostet den Marathon.
            'watch_patterns' => [
                '/herr[ -]?der[ -]?ringe/u',
                '/lord[ -]?of[ -]?the[ -]?rings/u',
                '/mittelerde|tolkien/u',
                '/(triple|trilogie|marathon)/u',
            ],
            'date_window' => ['from' => '10-01', 'to' => '02-28'],
            'known_urls'  => [
                'https://www.cinemaxx.de/kinoprogramm/magdeburg/film/der-herr-der-ringe-triple-extended-version',
            ],
            // Link-Muster, die auf einen aktiven Buchungsweg hindeuten.
            // Vorlaeufig: wird nach dem Discovery-Lauf praezisiert.
            'booking_link_patterns' => [
                '#/vorstellung/#i', '#/booking#i', '#/tickets?#i', '#/kaufen#i', '#/checkout#i', '#buchen#i',
            ],
            'smtp' => [
                'host' => '', 'port' => 587, 'user' => '', 'pass' => '',
                'from' => '', 'from_name' => 'Kino-Watcher', 'to' => '',
                // 'auto' leitet die Verschluesselung aus dem Port ab:
                // 465 -> ssl (TLS sofort), sonst tls (STARTTLS). Die haeufigste
                // Fehlkonfiguration ist Port 465 mit STARTTLS; dann legt der
                // Server ohne Erklaerung auf.
                'encryption' => 'auto',
                'timeout' => 20,
            ],
            'tick_secret' => '',
            'http' => [
                'timeout' => 15,
                'retries' => 2,
                'min_interval_per_host' => 2,
                'respect_robots' => true,
                'user_agent' => 'KinoTicketWatcher/1.0 (persoenlicher Benachrichtigungs-Watcher)',
                // KEIN Origin-Header. Gemessen auf einem IONOS-Webspace:
                // Jede Variante MIT Origin antwortet mit 401, jede ohne ihn
                // mit 200. Der Endpunkt behandelt einen gesetzten Origin als
                // fremde Herkunft und weist den Request ab -- ein eigenes
                // Backend schickt eben keinen.
                //
                // Was die API braucht, ist die Sitzung der Programmseite. Die
                // holt der Watcher selbst nach, wenn sie fehlt oder ablaeuft
                // (siehe Runner::fetchApi()).
                'api_headers' => [
                    'Accept'          => 'application/json, text/plain, */*',
                    'Accept-Language' => 'de-DE,de;q=0.9',
                ],
            ],
            'schedule' => [
                'quiet_season_seconds' => 12 * 3600,
                'hot_season_month'     => 9,        // ab September schaerfer takten
                'hot_absent_seconds'   => 1800,
                'page_exists_seconds'  => 600,
                'dated_seconds'        => 300,
                'presale_far_seconds'  => 900,
                'presale_near_seconds' => 60,
                'presale_near_window'  => 3600,
                'bookable_seconds'     => 600,
                'sold_out_seconds'     => 6 * 3600,
                'error_backoff'        => [300, 600, 1200, 2400, 3600],
                'jitter_seconds'       => 20,
                // Wie oft die HTML-Rueckfallebene zusaetzlich geprueft wird,
                // wenn die API nichts Passendes meldet. Bremst den Minutentakt.
                'html_crosscheck_seconds' => 1800,
                // Wie oft das gesamte Kinoprogramm geladen wird (ca. 0,5 MB).
                // Dazwischen wird gezielt nach der bekannten Film-Kennung
                // gefragt -- wenige Kilobyte statt einem halben Megabyte.
                'full_scan_seconds'       => 1800,
            ],
            'notify' => [
                'bookable_repeat_after' => [6 * 3600, 24 * 3600],
                'heartbeat_weekday'     => 1,        // Montag
                'heartbeat_from_month'  => 9,
                'tick_silence_alert'    => 3600,     // externer Taktgeber stumm?
            ],
            'snapshots_to_keep' => 40,
        ];
    }
}
