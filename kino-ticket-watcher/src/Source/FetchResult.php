<?php
declare(strict_types=1);

namespace Kino\Source;

use Kino\Model\Screening;

final readonly class FetchResult
{
    /** @param list<Screening> $screenings */
    public function __construct(
        public array $screenings,
        public ?string $error = null,
        public ?string $digest = null,
        public bool $notModified = false,
    ) {
    }
}
