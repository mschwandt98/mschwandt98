<?php
declare(strict_types=1);

namespace Optout\Mail;

use Optout\Cases\Store;

/**
 * Holt neue Nachrichten per IMAP und merkt sich pro Ordner, wie weit gelesen
 * wurde (UIDVALIDITY + letzte UID).
 *
 * Beim allerersten Lauf wird der Cursor ans Ende gesetzt, ohne alte Post zu
 * lesen: Antworten kann es erst auf Anfragen geben, die dieses Werkzeug
 * verschickt hat.
 */
final class ImapInbox implements Inbox
{
    public function __construct(
        private readonly array $cfg,
        private readonly Store $store,
        private readonly ?\Closure $connector = null,
    ) {
    }

    public function fetchNew(): array
    {
        $imap = new Imap($this->cfg, $this->connector);
        $max = (int)($this->cfg['max_messages_per_run'] ?? 50);
        $maxBytes = (int)($this->cfg['max_message_bytes'] ?? 300_000);
        $out = [];
        try {
            $imap->open();
            foreach ((array)($this->cfg['folders'] ?? ['INBOX']) as $folder) {
                $folder = (string)$folder;
                $info = $imap->examine($folder);
                $cursor = $this->store->inboxCursor($folder);

                if ($cursor === null || $cursor['uidvalidity'] !== $info['uidvalidity']) {
                    // Erster Lauf -- oder der Server hat die UIDs neu vergeben
                    // (UIDVALIDITY geaendert). Dann ab jetzt weiterlesen.
                    $last = max(0, $info['uidnext'] - 1);
                    if ($info['uidnext'] === 0) {
                        $uids = $imap->uidsAfter(0);
                        $last = $uids === [] ? 0 : max($uids);
                    }
                    $this->store->setInboxCursor($folder, $info['uidvalidity'], $last);
                    continue;
                }

                $last = $cursor['last_uid'];
                foreach ($imap->uidsAfter($last) as $uid) {
                    if (count($out) >= $max) {
                        break 2;
                    }
                    $raw = $imap->fetch($uid, $maxBytes);
                    if ($raw !== '') {
                        $out[] = ['message' => MimeParser::parse($raw, $folder, $uid), 'raw' => $raw];
                    }
                    $last = $uid;
                    // Cursor nach jeder Nachricht fortschreiben: Bricht der
                    // Lauf ab, wird nichts doppelt und nichts ausgelassen.
                    $this->store->setInboxCursor($folder, $info['uidvalidity'], $last);
                }
            }
        } finally {
            $imap->close();
        }
        return $out;
    }
}
