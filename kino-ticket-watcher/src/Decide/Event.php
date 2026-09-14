<?php
declare(strict_types=1);

namespace Kino\Decide;

use Kino\Model\Screening;

final readonly class Event
{
    public const NEW_MATCH      = 'new_match';
    public const STATUS_CHANGED = 'status_changed';
    public const REMINDER       = 'reminder';
    public const CONTENT_DIFF   = 'content_diff';
    public const WARNING        = 'warning';

    public function __construct(
        public string $type,
        public string $summary,
        public ?Screening $screening = null,
        public ?string $previousStatus = null,
        public array $details = [],
    ) {
    }

    /** Nur der Hauptalarm darf in der Betreffzeile schreien. */
    public function isAlarm(): bool
    {
        return $this->screening !== null
            && $this->screening->status === \Kino\Model\Status::Bookable;
    }
}
