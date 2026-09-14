<?php
declare(strict_types=1);

namespace Kino\Parse;

final class Normalize
{
    /** Umlaute und Sonderzeichen entfalten, damit Regex-Muster einfach bleiben. */
    public static function fold(string $s): string
    {
        $map = [
            'ä'=>'ae','ö'=>'oe','ü'=>'ue','Ä'=>'ae','Ö'=>'oe','Ü'=>'ue','ß'=>'ss',
            'é'=>'e','è'=>'e','ê'=>'e','á'=>'a','à'=>'a','â'=>'a','í'=>'i','ó'=>'o','ú'=>'u',
            "\u{2013}"=>'-', "\u{2014}"=>'-', "\u{2019}"=>"'", "\u{00a0}"=>' ',
        ];
        return strtr($s, $map);
    }

    /** Kleinschreibung, entfaltet, ohne Mehrfach-Leerzeichen. Basis fuer Titelvergleiche. */
    public static function title(string $s): string
    {
        $s = self::fold($s);
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /**
     * Schluessel fuer den Fingerprint: zusaetzlich werden Fassungs- und
     * Formatzusaetze entfernt. Ohne das gilt derselbe Marathon nach einem
     * Titel-Feinschliff der Website als neue Vorstellung -- und loest einen
     * Fehlalarm aus.
     */
    public static function forFingerprint(string $s): string
    {
        $s = self::title($s);
        $noise = [
            '/\b(ov|omu|omdu|imax|3d|2d|dolby ?atmos|dbox|4dx|hfr)\b/u',
            '/\b(extended|special|director\'?s cut|edition|fassung|version)\b/u',
            '/\b(teil|part) ?\d+\b/u',
            '/[\(\)\[\]\-:,\.\|]/u',
        ];
        foreach ($noise as $re) {
            $s = preg_replace($re, ' ', $s) ?? $s;
        }
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /**
     * Sichtbaren Text aus HTML ziehen und so weit normalisieren, dass sich ein
     * stabiler Digest daraus bilden laesst. Scripts, Styles und Whitespace
     * fliegen raus, damit nicht jede Cache-Busting-URL einen falschen Alarm
     * ausloest.
     */
    public static function visibleText(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript|svg)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<!--.*?-->#s', ' ', $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = self::fold($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    /**
     * Digest des sichtbaren Texts. Reine Zahlenfolgen, die sich staendig
     * aendern (Zeitstempel, Zaehler), werden vorher maskiert -- sonst meldet
     * das Sicherheitsnetz bei jedem Abruf eine "Aenderung".
     */
    public static function digest(string $html): string
    {
        $text = self::visibleText($html);
        $text = preg_replace('/\b\d{6,}\b/u', '#', $text) ?? $text;
        return sha1($text);
    }
}
