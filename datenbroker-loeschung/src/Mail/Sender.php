<?php
declare(strict_types=1);

namespace Optout\Mail;

interface Sender
{
    /** Gibt bei Erfolg null zurueck, sonst die Fehlermeldung. */
    public function send(OutgoingMessage $message): ?string;
}
