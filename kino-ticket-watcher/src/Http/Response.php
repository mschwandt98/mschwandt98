<?php
declare(strict_types=1);

namespace Kino\Http;

final readonly class Response
{
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
        public ?string $etag = null,
        public ?string $lastModified = null,
        public ?string $error = null,
        public bool $notModified = false,
        public string $finalUrl = '',
    ) {
    }

    public function ok(): bool
    {
        return $this->error === null && ($this->status === 200 || $this->notModified);
    }
}
