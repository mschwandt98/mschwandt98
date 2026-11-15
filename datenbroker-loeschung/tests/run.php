#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Testlaeufer ohne PHPUnit, ohne Netz.   php tests/run.php
 */

require __DIR__ . '/../src/autoload.php';

use Optout\Cases\Attention;
use Optout\Cases\CaseFile;
use Optout\Cases\Classifier;
use Optout\Cases\Status;
use Optout\Cases\Store;
use Optout\Cases\Verdict;
use Optout\Config;
use Optout\Engine\Actions;
use Optout\Engine\Notifier;
use Optout\Engine\Runner;
use Optout\Letters\Kind;
use Optout\Letters\Letters;
use Optout\Mail\Imap;
use Optout\Mail\Inbox;
use Optout\Mail\IncomingMessage;
use Optout\Mail\MimeParser;
use Optout\Mail\OutgoingMessage;
use Optout\Mail\Sender;
use Optout\Mail\Smtp;
use Optout\Registry\Broker;
use Optout\Registry\Registry;
use Optout\Web\Dashboard;

date_default_timezone_set('Europe/Berlin');

final class T
{
    public static int $pass = 0;
    public static int $fail = 0;

    public static function group(string $name): void
    {
        echo "\n$name\n" . str_repeat('-', mb_strlen($name)) . "\n";
    }

    public static function ok(bool $cond, string $label, string $detail = ''): void
    {
        if ($cond) {
            self::$pass++;
            echo "  ok   $label\n";
            return;
        }
        self::$fail++;
        echo "  FAIL $label" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    }

    public static function eq(mixed $expected, mixed $actual, string $label): void
    {
        self::ok($expected === $actual, $label, 'erwartet ' . var_export($expected, true) . ', bekommen ' . var_export($actual, true));
    }
}

final class FakeSender implements Sender
{
    /** @var list<OutgoingMessage> */
    public array $sent = [];
    public ?string $fail = null;

    public function send(OutgoingMessage $message): ?string
    {
        if ($this->fail !== null) {
            return $this->fail;
        }
        $this->sent[] = $message;
        return null;
    }

    /** @return list<OutgoingMessage> */
    public function take(): array
    {
        $s = $this->sent;
        $this->sent = [];
        return $s;
    }
}

final class FakeInbox implements Inbox
{
    public array $queue = [];
    public ?string $fail = null;

    public function push(string $raw): void
    {
        $this->queue[] = ['message' => MimeParser::parse($raw), 'raw' => $raw];
    }

    public function fetchNew(): array
    {
        if ($this->fail !== null) {
            throw new RuntimeException($this->fail);
        }
        $q = $this->queue;
        $this->queue = [];
        return $q;
    }
}

function tmpdir(): string
{
    $d = sys_get_temp_dir() . '/optout-test-' . bin2hex(random_bytes(4));
    mkdir($d, 0775, true);
    return $d;
}

function config(array $over = []): Config
{
    return Config::fromArray(array_replace_recursive([
        'data_dir' => dirname(__DIR__) . '/data',
        'live' => true,
        'identity' => [
            'name' => 'Erika Mustermann',
            'address' => "Heidestraße 17\n51147 Köln",
            'emails' => ['erika@example.org'],
        ],
        'smtp' => ['host' => 'smtp.example.org', 'user' => 'ds@example.org', 'pass' => 'x', 'from' => 'ds@example.org'],
        'notify' => ['to' => 'erika@example.org', 'digest_weekday' => -1],
        'authority' => 'desalbd',
    ], $over));
}

function mail_raw(array $headers, string $body): string
{
    $h = '';
    foreach ($headers as $k => $v) {
        $h .= "$k: $v\r\n";
    }
    return $h . "\r\n" . str_replace("\n", "\r\n", $body);
}

/** Socket-Paar: Die Serverantworten liegen vorab im Puffer. */
function scripted(string $serverSays): Closure
{
    return static function () use ($serverSays) {
        [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($server, $serverSays);
        $GLOBALS['__server'] = $server;
        return $client;
    };
}

function serverHeard(): string
{
    $s = $GLOBALS['__server'];
    stream_set_blocking($s, false);
    return (string)stream_get_contents($s);
}

// ====================================================================

T::group('Config');
$c = Config::fromArray(['brokers' => ['packs' => ['credit-agencies']]]);
T::eq(['credit-agencies'], $c->get('brokers.packs'), 'Listen werden ersetzt, nicht gemischt');
T::eq(5, $c->get('schedule.max_sends_per_run'), 'Vorgaben bleiben erhalten');
T::eq(false, $c->live(), 'Ohne Angabe: Testmodus');

T::group('Registry');
$cfg = config();
$reg = Registry::load($cfg);
$sel = $reg->selected($cfg);
T::ok(count($sel) >= 25, 'Standardpakete liefern mindestens 25 Firmen', (string)count($sel));
T::eq([], $reg->warnings(), 'Keine Warnungen mit Standardpaketen');
T::ok(isset($sel['schufa'], $sel['acxiom'], $sel['zoominfo']), 'SCHUFA, Acxiom und ZoomInfo sind dabei');
T::eq('de', $reg->find('schufa')->language, 'SCHUFA: deutscher Brief');
T::eq('en', $reg->find('zoominfo')->language, 'ZoomInfo (relevant-countries all): englischer Brief');
T::eq(['schufa.de'], $reg->find('schufa')->domains(), 'Domains aus E-Mail und Website');
T::eq('example.co.uk', Broker::registrable('mail.example.co.uk'), 'registrable: co.uk');
T::eq('schufa.de', Broker::registrable('Mail.Schufa.DE.'), 'registrable: Groß/Klein, Punkt am Ende');
T::ok(!$reg->find('universum-business')->canEmail(), 'Ikadis ohne E-Mail: kein Mailversand');
$cfg2 = config(['brokers' => ['packs' => ['gibtsnicht'], 'add' => ['nope'], 'custom' => [['slug' => 'eigen', 'name' => 'Eigen GmbH', 'email' => 'privacy@eigen.example']]]]);
$reg2 = Registry::load($cfg2);
$sel2 = $reg2->selected($cfg2);
T::eq(['eigen'], array_keys($sel2), 'Eigene Firma aus brokers.custom');
T::eq(2, count($reg2->warnings()), 'Warnung fuer unbekanntes Paket und unbekannten Slug');
T::ok(count($reg->search('schufa')) >= 1, 'Suche nach Name');

T::group('Briefe');
$letters = new Letters($cfg);
$runner0 = new Runner($cfg, new Store(tmpdir()), $reg, new FakeSender(), null);
T::eq(Kind::Access, $runner0->kindFor($reg->find('schufa')), 'Auskunftei bekommt Selbstauskunft');
T::eq(Kind::Erasure, $runner0->kindFor($reg->find('acxiom')), 'Adresshaendler bekommt Loeschung');
$l = $letters->request($reg->find('acxiom'), Kind::Erasure, 'DB-ABC123');
T::ok(str_contains($l['subject'], 'Art. 17') && str_contains($l['subject'], '[Ref DB-ABC123]'), 'Betreff mit Artikel und Referenz');
T::ok(str_contains($l['body'], 'Art. 21 Abs. 2 DSGVO der Verarbeitung für Zwecke der Direktwerbung'), 'Werbewiderspruch eingebaut');
T::ok(str_contains($l['body'], 'Anschrift: Heidestraße 17, 51147 Köln'), 'Anschrift einzeilig im Identitaetsblock');
T::ok(str_contains($l['body'], "Mit freundlichen Grüßen\n\nErika Mustermann") || str_contains($l['body'], "Mit freundlichen Grüßen\nErika Mustermann"), 'Unterschrift');
T::ok(!preg_match('/\[[a-z_]+>|\{[a-z_]+\}/', $l['body']), 'Keine uebrig gebliebenen Platzhalter');
$li = $letters->request($reg->find('infoscore'), Kind::Access, 'DB-ABC123');
T::eq(['birthdate'], $li['missing'], 'infoscore verlangt Geburtsdatum -- wird gemeldet');
T::ok(!str_contains($li['body'], '<italic>'), 'Keine Auszeichnungen im Klartext');
$le = $letters->request($reg->find('zoominfo'), Kind::Erasure, 'DB-ABC123');
T::ok(str_contains($le['body'], 'Article 17') && str_contains($le['body'], 'Address: Heidestraße'), 'Englischer Brief');
T::eq("a\n", Letters::render('[x>a][y>b]', ['x' => true], []), 'Bedingte Abschnitte');
$cfgB = config(['identity' => ['birthdate' => '01.02.1980', 'phones' => ['+49 1']]]);
[$block] = (new Letters($cfgB))->identityBlock($reg->find('acxiom'), 'de');
T::ok(!str_contains($block, '1980') && !str_contains($block, '+49'), 'Geburtsdatum/Telefon nur, wenn verlangt');
[$block] = (new Letters($cfgB))->identityBlock($reg->find('infoscore'), 'de');
T::ok(str_contains($block, 'Geburtsdatum: 01.02.1980'), '... und dann auch mitgeschickt');

T::group('Fristen und Referenzen');
$jan31 = (new DateTimeImmutable('2027-01-31 10:00'))->getTimestamp();
T::eq('2027-02-28', date('Y-m-d', CaseFile::deadline($jan31, 1, 0)), '31.1. + 1 Monat = 28.2.');
T::eq('2027-03-03', date('Y-m-d', CaseFile::deadline($jan31, 1, 3)), '... plus 3 Tage Zuschlag');
$mar15 = (new DateTimeImmutable('2027-03-15 10:00'))->getTimestamp();
T::eq('2027-04-15', date('Y-m-d', CaseFile::deadline($mar15, 1, 0)), '15.3. + 1 Monat = 15.4.');
T::ok(preg_match('/^DB-[0-9A-HJKMNP-TV-Z]{6}$/', CaseFile::newRef()) === 1, 'Referenzformat');
T::eq(['DB-7K3Q9X'], CaseFile::refsIn('Re: Antrag [Ref db-7k3q9x] ... DB-7K3Q9X'), 'Referenz im Text, unabhaengig von Gross/Klein');

T::group('MIME');
$m = MimeParser::parse(mail_raw([
    'From' => '"Datenschutz, Team" <datenschutz@acxiom.com>',
    'To' => 'ds@example.org',
    'Subject' => '=?ISO-8859-1?Q?Ihre_L=F6schanfrage?=',
    'Message-ID' => '<abc@acxiom.com>',
    'In-Reply-To' => '<x1@example.org>',
    'References' => '<x0@example.org> <x1@example.org>',
    'Content-Type' => 'text/plain; charset="iso-8859-1"',
    'Content-Transfer-Encoding' => 'quoted-printable',
], "Ihre Daten wurden gel=F6scht.\n"));
T::eq('Ihre Löschanfrage', $m->subject, 'RFC-2047-Betreff in Latin-1');
T::eq('Ihre Daten wurden gelöscht.', $m->text, 'Quoted-printable in Latin-1');
T::eq('datenschutz@acxiom.com', $m->from, 'Absender trotz Komma im Namen');
T::eq('Datenschutz, Team', $m->fromName, 'Anzeigename');
T::eq('x1@example.org', $m->inReplyTo, 'In-Reply-To');
T::eq(['x0@example.org', 'x1@example.org'], $m->references, 'References');
$m = MimeParser::parse(mail_raw([
    'From' => 'noreply@zoominfo.com',
    'Subject' => 'Re: Request',
    'Content-Type' => 'multipart/alternative; boundary="XX"',
], "--XX\nContent-Type: text/html; charset=utf-8\nContent-Transfer-Encoding: base64\n\n" . chunk_split(base64_encode('<p>We have <b>deleted</b>&nbsp;your data.</p><style>p{}</style>')) . "--XX--\n"));
T::eq('We have deleted your data.', $m->text, 'Nur-HTML, base64, Entities');
$bounce = mail_raw([
    'From' => 'MAILER-DAEMON@mx.example.org',
    'Subject' => 'Undelivered Mail Returned to Sender',
    'Content-Type' => 'multipart/report; report-type=delivery-status; boundary="B"',
], "--B\nContent-Type: text/plain\n\nUser unknown\n--B\nContent-Type: message/delivery-status\n\nStatus: 5.1.1\n--B\nContent-Type: message/rfc822\n\nMessage-ID: <orig42@example.org>\nSubject: Antrag\n\nText\n--B--\n");
$m = MimeParser::parse($bounce);
T::ok($m->bounce, 'Bounce erkannt');
T::ok(str_contains($m->text, 'orig42@example.org'), 'Message-ID des Originals im Text');
$m = MimeParser::parse(mail_raw(['From' => 'a@b.de', 'Subject' => 'Abwesenheitsnotiz', 'Auto-Submitted' => 'auto-replied'], 'Bin weg.'));
T::ok($m->autoReply, 'Autoantwort erkannt');
T::eq('Viele Grüße', MimeParser::decodeHeader('=?UTF-8?B?VmllbGUg?= =?UTF-8?B?R3LDvMOfZQ==?='), 'Mehrere encoded-words');

T::group('Klassifizierung');
$cls = static function (string $text, string $subject = 'Re: Anfrage', bool $auto = false): Verdict {
    return Classifier::classify(new IncomingMessage('', '', [], 'x@y.de', '', [], $subject, 0, $text, $auto, false))['verdict'];
};
T::eq(Verdict::Confirmed, $cls('Ihre Daten wurden in unserem System gelöscht.'), 'DE: gelöscht');
T::eq(Verdict::Confirmed, $cls('Wir haben Ihre Anschrift in unsere Werbesperrdatei aufgenommen.'), 'DE: Werbesperrdatei');
T::eq(Verdict::Confirmed, $cls('Ihr Widerspruch wurde bei uns vermerkt.'), 'DE: Widerspruch vermerkt');
T::eq(Verdict::Confirmed, $cls('Ihre Daten wurden geloescht.'), 'DE: umschriebene Umlaute');
T::eq(Verdict::Confirmed, $cls('Your personal data has been deleted from our systems.'), 'EN: deleted');
T::eq(Verdict::Confirmed, $cls('We have added you to our suppression list.'), 'EN: suppression list');
T::eq(Verdict::NoData, $cls('Zu Ihrer Person sind bei uns keine personenbezogenen Daten gespeichert.'), 'DE: keine Daten');
T::eq(Verdict::NoData, $cls('Es liegen uns keine Daten zu Ihrer Person vor.'), 'DE: liegen keine Daten vor');
T::eq(Verdict::NoData, $cls('We could not find any personal data matching your details.'), 'EN: no data');
T::eq(Verdict::Verification, $cls('Bitte senden Sie uns eine Kopie Ihres Personalausweises.'), 'DE: Ausweis');
T::eq(Verdict::Verification, $cls('To proceed, please submit your request via our privacy portal.'), 'EN: Portal');
T::eq(Verdict::Verification, $cls('Please confirm your email by clicking this link.'), 'EN: Bestaetigungslink');
T::eq(Verdict::Rejected, $cls('Eine Löschung ist aufgrund unseres berechtigten Interesses nicht möglich.'), 'DE: berechtigtes Interesse');
T::eq(Verdict::Rejected, $cls('We are unable to delete your data due to legal obligations.'), 'EN: unable to delete');
T::eq(Verdict::Extended, $cls('Aufgrund der Komplexität verlängern wir die Frist gemäß Art. 12 Abs. 3 S. 2 DSGVO.'), 'DE: Fristverlängerung');
T::eq(Verdict::Acknowledged, $cls('Vielen Dank, wir haben Ihre Anfrage erhalten und melden uns.'), 'DE: Eingangsbestätigung');
T::eq(Verdict::Acknowledged, $cls('Thank you for your request. Your ticket number is 4711.'), 'EN: ticket');
T::eq(Verdict::Acknowledged, $cls('Ich bin bis Montag nicht im Büro.', 'Abwesend', true), 'Autoantwort ohne Schlagwort');
T::eq(Verdict::Unknown, $cls('Können Sie uns bitte mitteilen, um welchen Vorgang es geht?'), 'Unklares bleibt unklar');
$quoted = "Wir prüfen das.\n\nAm 01.11.2026 um 10:00 schrieb Erika:\n> Sollten Sie die Löschung ablehnen ... keine Daten gespeichert ...";
T::eq(Verdict::Unknown, $cls($quoted), 'Zitierte eigene Anfrage verfälscht nichts');
T::eq(Verdict::Unknown, $cls("Danke.\n> Ihre Daten wurden gelöscht"), 'Zitat mit > zaehlt nicht');
T::eq(Verdict::Unknown, $cls('Regarding your request, we need more details.'), '"request" wird nicht zu "reqüst"');

T::group('SMTP');
$smtp = new Smtp(['host' => 'h', 'user' => 'u', 'pass' => 'p', 'from' => 'ds@example.org', 'from_name' => 'Erika Müller'],
    scripted("220 hi\r\n250-hi\r\n250 OK\r\n334 VXNlcm5hbWU6\r\n334 UGFzc3dvcmQ6\r\n235 ok\r\n250 ok\r\n250 ok\r\n250 ok\r\n354 go\r\n250 queued\r\n221 bye\r\n"));
$err = $smtp->send(new OutgoingMessage(['privacy@acxiom.com'], 'Antrag auf Löschung [Ref DB-ABC123]', ".Punkt am Anfang\nZeile 2", 'id1@example.org',
    replyTo: 'ds+db-abc123@example.org', bcc: ['archiv@example.org'], inReplyTo: 'prev@example.org', references: ['a@x', 'prev@example.org']));
T::eq(null, $err, 'Versand ohne Fehler');
$heard = serverHeard();
T::ok(str_contains($heard, "RCPT TO:<privacy@acxiom.com>") && str_contains($heard, "RCPT TO:<archiv@example.org>"), 'Bcc im Umschlag');
T::ok(!preg_match('/^Bcc:/mi', $heard), '... aber nicht im Header');
T::ok(str_contains($heard, "In-Reply-To: <prev@example.org>") && str_contains($heard, 'References: <a@x> <prev@example.org>'), 'Thread-Header');
T::ok(str_contains($heard, "Reply-To: ds+db-abc123@example.org"), 'Reply-To');
T::ok(str_contains($heard, "\r\n..Punkt am Anfang"), 'Dot-Stuffing');
T::ok(!str_contains($heard, 'Auto-Submitted'), 'Briefe an Firmen ohne Auto-Submitted');
T::ok(preg_match('/^From: =\?UTF-8\?B\?/m', $heard) === 1, 'Absendername kodiert');
T::ok(!str_contains($smtp->transcript(), base64_encode('p')), 'Passwort nicht im Protokoll');
T::eq('Ungueltige Empfaengeradresse: a b@c.de', (new Smtp(['host' => 'h', 'user' => 'u', 'pass' => 'p', 'from' => 'f@x.de']))
    ->send(new OutgoingMessage(['a b@c.de'], 's', 't', 'i@x')), 'Ungueltige Adresse wird abgewiesen');
$long = Smtp::encodeHeader(str_repeat('Löschantrag ', 10));
T::ok(max(array_map('strlen', explode("\r\n", $long))) <= 76, 'Lange Betreffe in kurze encoded-words geteilt');

T::group('IMAP');
$body = "From: a@b.de\r\nSubject: Hallo\r\n\r\nText\r\n";
$imap = new Imap(['host' => 'h', 'user' => 'u', 'pass' => 'p"w'], scripted(
    "* OK IMAP ready\r\n"
    . "A0001 OK LOGIN done\r\n"
    . "* 3 EXISTS\r\n* OK [UIDVALIDITY 777] ok\r\n* OK [UIDNEXT 13] ok\r\nA0002 OK [READ-ONLY] done\r\n"
    . "* SEARCH 10 11 12\r\nA0003 OK done\r\n"
    . "* 2 FETCH (UID 11 BODY[]<0> {" . strlen($body) . "}\r\n" . $body . ")\r\nA0004 OK done\r\n"
    . "* BYE\r\nA0005 OK bye\r\n"
));
$imap->open();
T::eq(['uidvalidity' => 777, 'uidnext' => 13, 'exists' => 3], $imap->examine('INBOX'), 'EXAMINE liefert UIDVALIDITY/UIDNEXT');
T::eq([11, 12], $imap->uidsAfter(10), 'UID-Suche filtert die eigene Grenze heraus');
T::eq($body, $imap->fetch(11, 1000), 'Literal vollstaendig gelesen');
$imap->close();
$heard = serverHeard();
T::ok(str_contains($heard, 'A0001 LOGIN "u" "p\\"w"'), 'Passwort korrekt gequotet');
T::ok(str_contains($heard, 'EXAMINE "INBOX"') && str_contains($heard, 'BODY.PEEK[]<0.1000>'), 'Nur lesend: EXAMINE und BODY.PEEK');
T::ok(!str_contains($imap->transcript(), 'p\\"w'), 'Passwort nicht im Protokoll');

// ====================================================================

T::group('Ablauf: Versand, Antwort, Erinnerung, Eskalation, neue Runde');
$dir = tmpdir();
$cfg = config(['var_dir' => $dir, 'brokers' => ['packs' => [], 'add' => ['acxiom', 'az-direct', 'schufa', 'universum-business', 'zoominfo', 'nexiga']],
    'schedule' => ['max_sends_per_run' => 3], 'smtp' => ['reply_to' => 'ds+{ref}@example.org']]);
$reg = Registry::load($cfg);
$store = new Store($dir);
$sender = new FakeSender();
$inbox = new FakeInbox();
$run = static fn (int $now) => (new Runner($cfg, $store, $reg, $sender, $inbox))->run($now);
$t0 = (new DateTimeImmutable('2026-11-02 10:00'))->getTimestamp();

$r = $run($t0);
$out = $sender->take();
T::eq(3, $r['sent'], 'Drossel: 3 Anfragen im ersten Lauf');
T::eq(4, count($out), '... plus eine Benachrichtigung (Ikadis nur per Brief)');
$notice = array_values(array_filter($out, static fn ($m) => $m->autoSubmitted))[0] ?? null;
T::ok($notice !== null && str_contains($notice->text, 'Ikadis') && str_contains($notice->text, 'Text zum Einfügen'), 'Benachrichtigung enthält Brieftext fuer Ikadis');
T::eq(['erika@example.org'], $notice?->to, '... an notify.to');
$acx = $store->currentFor('acxiom');
T::eq(Status::Waiting, $acx->status(), 'Acxiom: Frist läuft');
T::ok(str_starts_with($out[0]->replyTo, 'ds+db-'), 'Plus-Adresse mit Referenz');
T::ok(is_file($dir . '/' . $acx->d['letters'][0]['file']), 'Brief im Wortlaut abgelegt');
T::eq(Attention::Manual, $store->currentFor('universum-business')->attention(), 'Ikadis: manuell');

$r = $run($t0 + 3600);
$sender->take();
T::eq(2, $r['sent'], 'Zweiter Lauf: Rest verschickt');
$r = $run($t0 + 7200);
T::eq(0, $r['sent'], 'Dritter Lauf: nichts mehr offen');
T::eq([], $sender->take(), '... und keine Mails');

// Antwort im Thread: bestaetigt
$inbox->push(mail_raw(['From' => 'datenschutz@acxiom.com', 'Subject' => 'AW: Ihre Anfrage', 'Message-ID' => '<r1@acxiom.com>',
    'In-Reply-To' => '<' . $acx->messageIds()[0] . '>'], "Ihre Daten wurden gelöscht und gesperrt.\n\n> zitat"));
// Antwort nur ueber die Domain zuordenbar: Identitaetsnachweis
$inbox->push(mail_raw(['From' => 'Kundenservice <service@az-direct.com>', 'Subject' => 'Ihr Anliegen', 'Message-ID' => '<r2@az-direct.com>'],
    'Bitte senden Sie uns eine Kopie Ihres Personalausweises.'));
// Eingangsbestaetigung ueber die Referenz im Betreff
$zoom = $store->currentFor('zoominfo');
$inbox->push(mail_raw(['From' => 'noreply@zendesk.example', 'Subject' => "Re: Request [Ref {$zoom->ref()}]", 'Message-ID' => '<r3@zd>'],
    'Thank you for your request. Your ticket number is 1.'));
// Dieselbe Mail noch einmal (z. B. in zwei Ordnern)
$inbox->push(mail_raw(['From' => 'noreply@zendesk.example', 'Subject' => "Re: Request [Ref {$zoom->ref()}]", 'Message-ID' => '<r3@zd>'], 'x'));
// Bounce
$nex = $store->currentFor('nexiga');
$inbox->push(mail_raw(['From' => 'MAILER-DAEMON@mx.example.org', 'Subject' => 'Undelivered Mail Returned to Sender',
    'Content-Type' => 'multipart/report; report-type=delivery-status; boundary="B"'],
    "--B\nContent-Type: text/plain\n\nNo such user\n--B\nContent-Type: message/rfc822\n\nMessage-ID: <{$nex->messageIds()[0]}>\n\nx\n--B--\n"));
// Fremde Mail
$inbox->push(mail_raw(['From' => 'newsletter@shop.example', 'Subject' => 'Angebote', 'Message-ID' => '<n@shop>'], 'Rabatt!'));

$r = $run($t0 + 86400);
$out = $sender->take();
T::eq(4, $r['replies'], 'Vier Antworten zugeordnet (Duplikat und Newsletter nicht)');
T::eq(Status::Done, $store->currentFor('acxiom')->status(), 'Acxiom: erledigt');
T::eq(Attention::Verification, $store->currentFor('az-direct')->attention(), 'AZ Direct: über Domain zugeordnet, Nachweis verlangt');
T::ok($store->currentFor('zoominfo')->d['acknowledged'] === true && $store->currentFor('zoominfo')->status() === Status::Waiting, 'ZoomInfo: Eingang bestätigt, Frist läuft weiter');
T::eq(Attention::Bounce, $store->currentFor('nexiga')->attention(), 'Nexiga: unzustellbar');
T::eq(1, count($out), 'Eine gesammelte Benachrichtigung');
T::ok(str_contains($out[0]->subject, '2 Vorgänge'), '... für zwei Vorgänge', $out[0]->subject ?? '');
T::eq('Angebote', $store->meta('unmatched')[0]['subject'] ?? null, 'Nicht zugeordnete Mail gemerkt');

// Frist laeuft ab: Erinnerung im Thread
$schufa = $store->currentFor('schufa');
$due = (int)$schufa->d['due_at'];
$r = $run($due + 60);
$out = $sender->take();
T::eq(2, $r['reminded'], 'Erinnerung an SCHUFA und ZoomInfo');
$rem = array_values(array_filter($out, static fn ($m) => in_array('datenschutz@schufa.de', $m->to, true)))[0] ?? null;
T::ok($rem !== null && str_starts_with($rem->subject, 'Erinnerung:') && $rem->inReplyTo === $schufa->messageIds()[0], 'SCHUFA-Erinnerung im Thread');
T::ok($rem !== null && str_contains($rem->text, '> Guten Tag'), '... mit zitierter Originalanfrage');
T::eq(Status::Reminded, $store->currentFor('schufa')->status(), 'SCHUFA: erinnert');

// Antwort auf die Erinnerung: Fristverlaengerung -> keine Eskalation
$inbox->push(mail_raw(['From' => 'datenschutz@schufa.de', 'Subject' => 'Ihre Anfrage', 'Message-ID' => '<r9@schufa>',
    'In-Reply-To' => '<' . $store->currentFor('schufa')->lastMessageId() . '>'], 'Wir verlängern die Frist um zwei weitere Monate.'));
$nachfrist = (int)$store->currentFor('zoominfo')->d['due_at'];
$r = $run($nachfrist + 60);
$out = $sender->take();
T::eq(Status::Waiting, $store->currentFor('schufa')->status(), 'SCHUFA: Verlängerung, wieder im Fristlauf');
T::eq(Attention::Escalation, $store->currentFor('zoominfo')->attention(), 'ZoomInfo: Nachfrist abgelaufen, eskaliert');
$esc = $out[0] ?? null;
T::ok($esc !== null && str_contains($esc->text, 'Beschwerde nach Art. 77') && str_contains($esc->text, 'Sachsen-Anhalt'), 'Beschwerde-Entwurf mit Behörde in der Benachrichtigung');

// Von Hand: AZ Direct nach Nachweis wieder auf Warten, Nexiga ignorieren
$actions = new Actions($cfg, $store);
T::eq(null, $actions->apply($store->currentFor('az-direct')->ref(), 'wait', $nachfrist), 'Aktion "wartet wieder"');
T::eq(Status::Waiting, $store->currentFor('az-direct')->status(), 'AZ Direct wartet wieder');
T::ok((int)$store->currentFor('az-direct')->d['due_at'] > $nachfrist + 28 * 86400, '... mit neuer Monatsfrist');
T::eq(null, $actions->apply($store->currentFor('nexiga')->ref(), 'ignore', $nachfrist), 'Aktion "ignorieren"');
T::ok($actions->apply('DB-000000', 'done', 0) !== null, 'Unbekannter Vorgang wird gemeldet');
$store->save();

// Neue Runde nach recheck_days
$acxDone = $store->currentFor('acxiom');
$r = $run((int)$acxDone->d['next_cycle_at'] + 60);
$sender->take();
$acxNew = $store->currentFor('acxiom');
T::ok($acxNew->ref() !== $acxDone->ref() && (int)$acxNew->d['cycle'] === 2, 'Acxiom: Runde 2 angelegt');
T::eq(Status::Waiting, $acxNew->status(), '... und verschickt');
T::eq(Status::Ignored, $store->currentFor('nexiga')->status(), 'Ignorierte Firma bleibt ignoriert');
T::eq(2, count($store->casesFor('acxiom')), 'Alte Runde bleibt als Beleg erhalten');

// Spaete Antwort auf die alte Runde landet bei der alten Runde
$inbox->push(mail_raw(['From' => 'datenschutz@acxiom.com', 'Subject' => 'Nachtrag', 'Message-ID' => '<late@acx>',
    'In-Reply-To' => '<' . $acxDone->messageIds()[0] . '>'], 'Wir haben Ihre Anfrage erhalten.'));
$run((int)$acxDone->d['next_cycle_at'] + 7200);
T::eq(2, count($store->case($acxDone->ref())->replies()), 'Nachzügler zur alten Runde zugeordnet');
T::eq(Status::Waiting, $store->currentFor('acxiom')->status(), 'Neue Runde unberührt');

T::group('Testmodus, Fehler, Posteingang');
$dir = tmpdir();
$cfgT = config(['var_dir' => $dir, 'live' => false, 'brokers' => ['packs' => [], 'add' => ['acxiom']]]);
$sender = new FakeSender();
$storeT = new Store($dir);
$r = (new Runner($cfgT, $storeT, Registry::load($cfgT), $sender, null))->run($t0);
T::eq(0, $r['sent'], 'Testmodus: nichts verschickt');
T::eq([], $sender->sent, '... wirklich nichts');
T::eq(Status::Queued, $storeT->currentFor('acxiom')->status(), '... Vorgang wartet');

$dir = tmpdir();
$cfgF = config(['var_dir' => $dir, 'brokers' => ['packs' => [], 'add' => ['acxiom', 'az-direct']]]);
$sender = new FakeSender();
$sender->fail = 'SMTP-Fehler: 535 auth failed';
$storeF = new Store($dir);
$inbox = new FakeInbox();
$inbox->fail = 'IMAP-Fehler: login';
$runnerF = static fn (int $now) => (new Runner($cfgF, $storeF, Registry::load($cfgF), $sender, $inbox))->run($now);
$r = $runnerF($t0);
T::eq(2, count($r['errors']), 'SMTP- und IMAP-Fehler gemeldet');
T::eq(1, (int)$storeF->currentFor('acxiom')->d['send_failures'] + (int)$storeF->currentFor('az-direct')->d['send_failures'], 'Nach dem ersten SMTP-Fehler wird abgebrochen');
$runnerF($t0 + 3600);
$runnerF($t0 + 7200);
T::eq(Attention::SendFailed, $storeF->currentFor('acxiom')->attention(), 'Nach drei Fehlversuchen: du bist dran');
$sender->fail = null;
$runnerF($t0 + 86400 + 60);
$out = $sender->take();
T::ok((bool)array_filter($out, static fn ($m) => str_contains($m->subject, 'Posteingang nicht erreichbar')), 'Nach 24 h ohne Posteingang: Warnung');
$runnerF($t0 + 2 * 86400);
T::eq([], array_filter($sender->take(), static fn ($m) => str_contains($m->subject, 'Posteingang')), '... nur einmal');

T::group('Wochenübersicht');
$cfgD = config(['var_dir' => $dir, 'notify' => ['digest_weekday' => 1, 'digest_hour' => 8]]);
$n = new Notifier($cfgD, new FakeSender(), new Store(tmpdir()), Registry::load($cfgD));
$mon9 = (new DateTimeImmutable('2026-11-02 09:00'))->getTimestamp(); // Montag
T::ok($n->digestDue($mon9), 'Montag 9 Uhr: fällig');
T::ok(!$n->digestDue($mon9 - 2 * 3600), 'Montag 7 Uhr: noch nicht');
T::ok(!$n->digestDue($mon9 + 86400), 'Dienstag: nicht');
$s = new FakeSender();
$storeD = new Store(tmpdir());
(new Runner(config(['var_dir' => $storeD->dir(), 'brokers' => ['packs' => [], 'add' => ['acxiom', 'universum-business']]]), $storeD, Registry::load($cfgD), $s, null))->run($t0, true);
$digest = array_values(array_filter($s->sent, static fn ($m) => str_contains($m->subject, 'Wochenübersicht')))[0] ?? null;
T::ok($digest !== null && str_contains($digest->text, 'DU BIST DRAN') && str_contains($digest->text, 'NICHT EINGERICHTET'), 'Übersicht mit offenen Punkten und Technikstatus');

T::group('Weboberfläche');
$html = (new Dashboard($cfg, $store, $reg, str_repeat('k', 24)))->render('', '');
T::ok(str_contains($html, 'Acxiom') && str_contains($html, 'name="action" value="done"'), 'Tabelle mit Aktionen');
T::ok(!str_contains($html, '<script'), 'Kein JavaScript');
$detail = (new Dashboard($cfg, $store, $reg, str_repeat('k', 24)))->render('', $store->currentFor('zoominfo')->ref());
T::ok(str_contains($detail, 'Beschwerde nach Art. 77'), 'Detailansicht zeigt Beschwerde-Entwurf');
$xss = config(['var_dir' => tmpdir(), 'brokers' => ['packs' => [], 'custom' => [['slug' => 'x', 'name' => '<script>alert(1)</script>', 'email' => 'a@b.de']]]]);
$sx = new Store($xss->varDir());
(new Runner(config(['live' => false, 'var_dir' => $sx->dir(), 'brokers' => ['packs' => [], 'custom' => [['slug' => 'x', 'name' => '<script>', 'email' => 'a@b.de']]]]), $sx, Registry::load($xss), new FakeSender(), null))->run($t0);
T::ok(!str_contains((new Dashboard($xss, $sx, Registry::load($xss), str_repeat('k', 24)))->render('<b>', ''), '<script>'), 'Ausgabe wird maskiert');

echo "\n" . T::$pass . ' ok, ' . T::$fail . " fehlgeschlagen\n";
exit(T::$fail === 0 ? 0 : 1);
