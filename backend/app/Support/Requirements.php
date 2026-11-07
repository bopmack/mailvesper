<?php

namespace App\Support;

/**
 * What a server needs to run MailVesper: plain PHP and MySQL, nothing else. Used by the browser installer,
 * `mailvesper:install` (before anything is written) and `mailvesper:check`.
 * Each check is [name, ok, detail, required]; a failed optional check is a warning, not a stop.
 */
class Requirements
{
    public const PHP_MIN = '8.2.0';

    /** @var (callable(string): bool)|null tests replace the outgoing-connection check */
    public static $reach = null;

    /** Extensions Laravel, the AWS SDK, passkeys, the SMTP client and Pro's license check need. */
    public const EXTENSIONS = ['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'iconv', 'json', 'mbstring', 'openssl', 'pdo', 'session', 'simplexml', 'sodium', 'tokenizer', 'xml'];

    /**
     * In plain words: the people installing this are not technical, so a failed check says what to ask the host.
     *
     * @return list<array{name: string, ok: bool, detail: string, required: bool}>
     */
    public static function server(): array
    {
        $out = [];
        $php = version_compare(PHP_VERSION, self::PHP_MIN, '>=');
        $out[] = self::row('PHP version', $php, $php ? 'PHP '.PHP_VERSION
            : 'This site runs PHP '.PHP_VERSION.'. Ask your hosting company to switch it to PHP 8.3 (in cPanel: “Select PHP Version” or “MultiPHP Manager”).');
        $missing = array_values(array_filter(self::EXTENSIONS, fn ($e) => ! extension_loaded($e)));
        $out[] = self::row('PHP add-ons', $missing === [], $missing ? 'Ask your hosting company to turn on these PHP extensions: '.implode(', ', $missing).'.' : 'All there');
        $drivers = class_exists(\PDO::class) ? \PDO::getAvailableDrivers() : [];
        $db = (bool) array_intersect($drivers, ['mysql', 'sqlite']);
        $out[] = self::row('Database support', $db, $db ? 'MySQL ready' : 'Ask your hosting company to turn on the PHP “pdo_mysql” extension.');
        $locked = array_values(array_filter([base_path('storage'), base_path('bootstrap/cache')], fn ($p) => ! is_dir($p) || ! is_writable($p)));
        $out[] = self::row('Can save files', $locked === [], $locked === [] ? 'Yes'
            : 'PHP can’t write to '.implode(' and ', $locked).'. In File Manager, set '.(count($locked) > 1 ? 'those folders’' : 'that folder’s').' permissions to 755 (or ask your hosting company).');
        $reach = self::canReach('https://email.us-east-1.amazonaws.com');
        $out[] = self::row('Internet access', $reach, $reach ? 'Yes' : 'This server can’t reach other websites, so sending through Amazon SES and AI won’t work. Ask your hosting company to allow outgoing HTTPS.', false);
        $zip = extension_loaded('zip');
        $out[] = self::row('Zip files', $zip, $zip ? 'Yes' : 'Zipped DMARC reports can’t be opened (plain ones still can). Optional: the PHP “zip” extension.', false);

        return $out;
    }

    /** @param  list<array{ok: bool, required: bool}>  $rows */
    public static function passes(array $rows): bool
    {
        foreach ($rows as $r) {
            if (! $r['ok'] && $r['required']) {
                return false;
            }
        }

        return true;
    }

    private static function canReach(string $url): bool
    {
        if (self::$reach) {
            return (self::$reach)($url);
        }
        if (! function_exists('curl_init')) {
            return false;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_RETURNTRANSFER => true]);
        $ok = curl_exec($ch) !== false;
        curl_close($ch);

        return $ok;
    }

    /** @return array{name: string, ok: bool, detail: string, required: bool} */
    private static function row(string $name, bool $ok, string $detail, bool $required = true): array
    {
        return ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'required' => $required];
    }
}
