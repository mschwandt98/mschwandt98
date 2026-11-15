<?php
declare(strict_types=1);

namespace Optout\Mail;

/** Gemeinsame Verbindungslogik fuer SMTP und IMAP. */
final class Tls
{
    /**
     * 'auto' leitet die Verschluesselung aus dem Port ab: Die Ports mit
     * sofortigem TLS (465 SMTP, 993 IMAP) bekommen 'ssl', alle anderen
     * STARTTLS. Die haeufigste Fehlkonfiguration ist der TLS-Port mit
     * STARTTLS -- dann legt der Server ohne Erklaerung auf.
     */
    public static function resolve(string $configured, int $port): string
    {
        $configured = strtolower(trim($configured));
        if ($configured !== '' && $configured !== 'auto') {
            return $configured;
        }
        return in_array($port, [465, 993], true) ? 'ssl' : 'tls';
    }

    /** @return resource */
    public static function connect(string $host, int $port, string $encryption, int $timeout)
    {
        $dsn = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $context = stream_context_create(['ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
            'SNI_enabled'      => true,
            'peer_name'        => $host,
        ]]);
        $socket = @stream_socket_client($dsn, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new \RuntimeException("Verbindung zu $dsn fehlgeschlagen: $errstr ($errno)");
        }
        stream_set_timeout($socket, $timeout);
        return $socket;
    }

    /** @param resource $socket */
    public static function upgrade($socket): void
    {
        $ok = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($ok !== true) {
            throw new \RuntimeException('STARTTLS fehlgeschlagen (TLS-Handshake abgelehnt).');
        }
    }
}
