<?php
declare(strict_types=1);

namespace Optout\Mail;

final class IncomingMessage
{
    /**
     * @param list<string> $references Message-IDs ohne spitze Klammern
     * @param list<string> $recipients alle Empfaengeradressen (To, Cc, Delivered-To, ...)
     */
    public function __construct(
        public readonly string $messageId,
        public readonly string $inReplyTo,
        public readonly array $references,
        public readonly string $from,
        public readonly string $fromName,
        public readonly array $recipients,
        public readonly string $subject,
        public readonly int $date,
        public readonly string $text,
        public readonly bool $autoReply,
        public readonly bool $bounce,
        public readonly string $folder = '',
        public readonly int $uid = 0,
    ) {
    }

    public function fromDomain(): string
    {
        $at = strrchr($this->from, '@');
        return $at === false ? '' : strtolower(substr($at, 1));
    }

    /** Kurzer Auszug fuer Zustand und Benachrichtigungen. */
    public function excerpt(int $length = 600): string
    {
        $text = preg_replace('/^>.*$/m', '', $this->text) ?? $this->text;
        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . ' …' : $text;
    }
}
