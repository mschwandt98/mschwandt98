<?php
declare(strict_types=1);

namespace Optout\Mail;

final class OutgoingMessage
{
    /**
     * @param list<string> $to
     * @param list<string> $bcc
     * @param list<string> $references
     */
    public function __construct(
        public readonly array $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly string $messageId,
        public readonly string $replyTo = '',
        public readonly array $bcc = [],
        public readonly string $inReplyTo = '',
        public readonly array $references = [],
        // Nur fuer Benachrichtigungen an dich selbst. Briefe an Firmen bekommen
        // den Header NICHT: Manche Ticketsysteme legen "auto-generated" Mails
        // gar nicht erst als Vorgang an.
        public readonly bool $autoSubmitted = false,
    ) {
    }

    /** Message-ID mit der Domain des Absenders, ohne spitze Klammern. */
    public static function newId(string $from): string
    {
        $at = strrchr($from, '@');
        $domain = $at === false || $at === '@' ? 'localhost' : substr($at, 1);
        return bin2hex(random_bytes(12)) . '@' . $domain;
    }
}
