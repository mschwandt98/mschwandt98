<?php
declare(strict_types=1);

namespace Kino\Notify;

use Kino\Decide\Event;
use Kino\Model\Status;

/**
 * Baut Betreff und Text der Benachrichtigungen.
 *
 * Leitgedanke: Die Mail wird auf dem Handy gelesen, vermutlich in Eile. Der
 * Direktlink steht deshalb ganz oben, nicht unter einer Erklaerung.
 */
final class Templates
{
    public const SUBJECT_PREFIX = '[KINO-ALARM]';

    /** @param list<Event> $events */
    public function subject(array $events, string $cinemaName): string
    {
        foreach ($events as $event) {
            if ($event->isAlarm() && $event->screening !== null) {
                return sprintf(
                    '%s %s %s -- jetzt buchbar',
                    self::SUBJECT_PREFIX,
                    self::shortTitle($event->screening->title),
                    $event->screening->when()
                );
            }
        }
        $first = $events[0] ?? null;
        if ($first === null) {
            return self::SUBJECT_PREFIX . ' Statusmeldung ' . $cinemaName;
        }
        if ($first->screening !== null) {
            return sprintf(
                '%s %s: %s',
                self::SUBJECT_PREFIX,
                self::shortTitle($first->screening->title),
                $first->screening->status->label()
            );
        }
        return self::SUBJECT_PREFIX . ' ' . self::trim($first->summary, 70);
    }

    /** @param list<Event> $events */
    public function text(array $events, string $cinemaName): string
    {
        $lines = [];
        $alarm = null;
        foreach ($events as $event) {
            if ($event->isAlarm()) {
                $alarm = $event;
                break;
            }
        }

        if ($alarm?->screening !== null) {
            $s = $alarm->screening;
            $lines[] = 'JETZT BUCHBAR: ' . $s->title;
            $lines[] = '';
            $lines[] = 'Direkt zur Buchung:';
            $lines[] = '  ' . ($s->bookingUrl ?? $s->url);
            $lines[] = '';
            $lines[] = 'Termin: ' . $s->when();
            $lines[] = 'Kino:   ' . $cinemaName;
            if ($alarm->previousStatus !== null) {
                $prev = Status::tryFrom($alarm->previousStatus);
                $lines[] = 'Vorher: ' . ($prev?->label() ?? $alarm->previousStatus);
            }
            $lines[] = '';
            $lines[] = str_repeat('-', 56);
            $lines[] = '';
        }

        $lines[] = 'Alle Aenderungen dieses Laufs:';
        $lines[] = '';
        foreach ($events as $event) {
            $lines[] = '* ' . $event->summary;
            if ($event->screening !== null) {
                $lines[] = '    Termin: ' . $event->screening->when();
                $lines[] = '    Link:   ' . ($event->screening->bookingUrl ?? $event->screening->url);
                $lines[] = '    Quelle: ' . $event->screening->source;
            }
            foreach ($event->details as $key => $value) {
                if (is_scalar($value)) {
                    $lines[] = '    ' . $key . ': ' . $value;
                }
            }
            $lines[] = '';
        }

        $lines[] = str_repeat('-', 56);
        $lines[] = 'Geprueft am ' . date('d.m.Y H:i:s');
        $lines[] = 'Kino-Ticket-Watcher';

        return implode("\n", $lines);
    }

    /** @param list<Event> $events */
    public function html(array $events, string $cinemaName): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $out = ['<html><body style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;font-size:15px;line-height:1.5;color:#1a1a1a">'];

        foreach ($events as $event) {
            if (!$event->isAlarm() || $event->screening === null) {
                continue;
            }
            $s = $event->screening;
            $link = $s->bookingUrl ?? $s->url;
            $out[] = '<div style="border:2px solid #b4232a;border-radius:8px;padding:16px;margin-bottom:20px">';
            $out[] = '<div style="font-size:20px;font-weight:700;color:#b4232a;margin-bottom:8px">Jetzt buchbar</div>';
            $out[] = '<div style="font-size:17px;font-weight:600;margin-bottom:12px">' . $e($s->title) . '</div>';
            $out[] = '<a href="' . $e($link) . '" style="display:inline-block;background:#b4232a;color:#fff;'
                   . 'padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:600">Zur Buchung</a>';
            $out[] = '<div style="margin-top:12px;color:#555">' . $e($s->when()) . ' &middot; ' . $e($cinemaName) . '</div>';
            $out[] = '</div>';
            break;
        }

        $out[] = '<p style="font-weight:600;margin-bottom:4px">Alle Aenderungen dieses Laufs</p><ul style="padding-left:20px">';
        foreach ($events as $event) {
            $out[] = '<li style="margin-bottom:8px">' . $e($event->summary);
            if ($event->screening !== null) {
                $link = $event->screening->bookingUrl ?? $event->screening->url;
                $out[] = '<br><a href="' . $e($link) . '" style="color:#1a5fb4">' . $e(self::trim($link, 70)) . '</a>';
            }
            $out[] = '</li>';
        }
        $out[] = '</ul>';
        $out[] = '<p style="color:#777;font-size:13px;border-top:1px solid #ddd;padding-top:10px">Geprueft am '
               . $e(date('d.m.Y H:i:s')) . ' &middot; Kino-Ticket-Watcher</p>';
        $out[] = '</body></html>';

        return implode("\n", $out);
    }

    private static function shortTitle(string $title): string
    {
        $title = preg_replace('/\s*[\(\[].*$/u', '', $title) ?? $title;
        return self::trim(trim($title), 48);
    }

    private static function trim(string $s, int $max): string
    {
        return mb_strlen($s, 'UTF-8') <= $max ? $s : mb_substr($s, 0, $max - 1, 'UTF-8') . '...';
    }
}
