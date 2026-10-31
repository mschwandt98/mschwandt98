<?php
declare(strict_types=1);

namespace Optout\Cases;

use Optout\Letters\Kind;

/**
 * Ein Vorgang: eine Anfrage an ein Unternehmen, mit Erinnerung, Antworten
 * und Ergebnis. Jede Runde (alle paar Monate) ist ein eigener Vorgang.
 *
 * Bewusst ein duennes Objekt ueber einem Array: Der Zustand liegt als JSON
 * auf der Platte und soll mit blossem Auge lesbar bleiben.
 */
final class CaseFile
{
    private const REF_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private function __construct(public array $d)
    {
    }

    public static function open(string $slug, Kind $kind, int $cycle, int $now, ?string $ref = null): self
    {
        return new self([
            'ref'           => $ref ?? self::newRef(),
            'slug'          => $slug,
            'kind'          => $kind->value,
            'cycle'         => $cycle,
            'status'        => Status::Queued->value,
            'attention'     => null,
            'created_at'    => $now,
            'sent_at'       => null,
            'due_at'        => null,
            'reminded_at'   => null,
            'closed_at'     => null,
            'next_cycle_at' => null,
            'acknowledged'  => false,
            'send_failures' => 0,
            'message_ids'   => [],
            'letters'       => [],
            'replies'       => [],
            'notes'         => [],
            'history'       => [['at' => $now, 'event' => 'angelegt']],
        ]);
    }

    public static function fromArray(array $d): self
    {
        return new self($d);
    }

    public static function newRef(): string
    {
        $ref = '';
        for ($i = 0; $i < 6; $i++) {
            $ref .= self::REF_ALPHABET[random_int(0, 31)];
        }
        return 'DB-' . $ref;
    }

    /** Findet Referenzen in beliebigem Text. @return list<string> */
    public static function refsIn(string $text): array
    {
        preg_match_all('/\bDB-([0-9A-HJKMNP-TV-Z]{6})\b/i', $text, $m);
        return array_values(array_unique(array_map(static fn ($r) => 'DB-' . strtoupper($r), $m[1])));
    }

    public function ref(): string
    {
        return $this->d['ref'];
    }

    public function slug(): string
    {
        return $this->d['slug'];
    }

    public function kind(): Kind
    {
        return Kind::from($this->d['kind']);
    }

    public function status(): Status
    {
        return Status::from($this->d['status']);
    }

    public function attention(): ?Attention
    {
        return $this->d['attention'] === null ? null : Attention::from($this->d['attention']);
    }

    public function int(string $key): ?int
    {
        return isset($this->d[$key]) ? (int)$this->d[$key] : null;
    }

    public function setStatus(Status $status, int $now, string $event, ?Attention $attention = null): void
    {
        $this->d['status'] = $status->value;
        $this->d['attention'] = $status === Status::Attention ? ($attention ?? Attention::Unknown)->value : null;
        $this->log($now, $event);
    }

    public function log(int $now, string $event): void
    {
        $this->d['history'][] = ['at' => $now, 'event' => $event];
    }

    public function markSent(string $type, string $messageId, string $file, int $now): void
    {
        $this->d['message_ids'][] = $messageId;
        $this->d['letters'][] = ['type' => $type, 'at' => $now, 'file' => $file, 'message_id' => $messageId];
        $this->d['send_failures'] = 0;
        if ($type === 'request') {
            $this->d['sent_at'] = $now;
        } else {
            $this->d['reminded_at'] = $now;
        }
    }

    public function close(int $now, int $recheckDays, string $event): void
    {
        $this->d['closed_at'] = $now;
        $this->d['next_cycle_at'] = $now + $recheckDays * 86400;
        $this->setStatus(Status::Done, $now, $event);
    }

    public function addReply(array $reply): void
    {
        $this->d['replies'][] = $reply;
    }

    /** @return list<array> */
    public function replies(): array
    {
        return $this->d['replies'];
    }

    /** @return list<string> */
    public function messageIds(): array
    {
        return $this->d['message_ids'];
    }

    public function firstLetterFile(): ?string
    {
        foreach ($this->d['letters'] as $l) {
            if ($l['type'] === 'request') {
                return $l['file'];
            }
        }
        return null;
    }

    public function lastMessageId(): string
    {
        return $this->d['message_ids'] === [] ? '' : (string)end($this->d['message_ids']);
    }

    public function toArray(): array
    {
        return $this->d;
    }

    /**
     * Monatsfrist nach Art. 12 Abs. 3 DSGVO plus Zuschlag. "Ein Monat" ist
     * kalendarisch gemeint, nicht 30 Tage.
     */
    public static function deadline(int $from, int $months, int $graceDays): int
    {
        $d = (new \DateTimeImmutable('@' . $from))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $target = $d->modify("+$months months");
        // 31. Januar + 1 Monat = 3. Maerz bei PHP; gemeint ist der 28./29. Februar.
        if ((int)$target->format('j') !== (int)$d->format('j')) {
            $target = $target->modify('last day of previous month');
        }
        return $target->getTimestamp() + $graceDays * 86400;
    }
}
