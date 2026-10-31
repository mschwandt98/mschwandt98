<?php
declare(strict_types=1);

namespace Optout\Mail;

interface Inbox
{
    /**
     * Neue Nachrichten seit dem letzten Abruf.
     *
     * @return list<array{message: IncomingMessage, raw: string}>
     */
    public function fetchNew(): array;
}
