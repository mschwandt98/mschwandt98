<?php
declare(strict_types=1);

namespace Optout;

use Optout\Cases\Store;
use Optout\Engine\Runner;
use Optout\Mail\ImapInbox;
use Optout\Mail\Smtp;
use Optout\Registry\Registry;

/** Baut die Teile aus der Konfiguration zusammen -- fuer alle Entrypoints. */
final class App
{
    public static function smtpConfig(Config $config): array
    {
        $smtp = (array)$config->get('smtp');
        // Briefe an Firmen kommen von dir, nicht von einem "Watcher".
        if (trim((string)($smtp['from_name'] ?? '')) === '') {
            $smtp['from_name'] = (string)$config->get('identity.name');
        }
        return $smtp;
    }

    public static function runner(Config $config, Store $store): Runner
    {
        $imap = (array)$config->get('imap');
        $inbox = trim((string)($imap['host'] ?? '')) === '' ? null : new ImapInbox($imap, $store);
        return new Runner($config, $store, Registry::load($config), new Smtp(self::smtpConfig($config)), $inbox);
    }

    /** @return list<string> Probleme, die einen Live-Betrieb verhindern */
    public static function configProblems(Config $config): array
    {
        $p = [];
        if (trim((string)$config->get('identity.name')) === '') {
            $p[] = "identity.name fehlt.";
        }
        if (trim((string)$config->get('identity.address')) === '') {
            $p[] = "identity.address fehlt (Adresshändler finden dich nur über die Anschrift).";
        }
        foreach (['host', 'user', 'pass', 'from'] as $k) {
            if (trim((string)$config->get("smtp.$k")) === '') {
                $p[] = "smtp.$k fehlt.";
            }
        }
        if (trim((string)$config->get('notify.to')) === '') {
            $p[] = 'notify.to fehlt (wohin sollen Benachrichtigungen?).';
        }
        $from = strtolower((string)$config->get('smtp.from'));
        $user = strtolower((string)$config->get('smtp.user'));
        if (str_contains($user, '@') && $from !== '' && substr(strrchr($user, '@') ?: '', 1) !== substr(strrchr($from, '@') ?: '', 1)) {
            $p[] = 'smtp.from und smtp.user haben verschiedene Domains. SPF schlägt dann fehl, und Anfragen landen im Spam.';
        }
        return $p;
    }
}
