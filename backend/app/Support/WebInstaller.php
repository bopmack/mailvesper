<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * The browser installer's state (the zip download's /install/ page). It works only until the installation has its
 * admin: then it closes for good. Each run gets a one-time token, saved next to the .env, which the later steps must
 * send back, so nobody else can finish an install somebody started.
 */
class WebInstaller
{
    /** Set in the zip's api/index.php: the web root the domain points at (the static pages, api/ and media/). */
    public static function webroot(): ?string
    {
        $root = defined('SENDLINE_WEBROOT') ? (string) constant('SENDLINE_WEBROOT') : '';

        return $root !== '' && is_dir($root) ? $root : null;
    }

    /** Tests point this at a temporary folder: .env, the lock and the token then live there. */
    public static ?string $dir = null;

    public static function envPath(): string
    {
        return self::$dir !== null ? self::$dir.'/.env' : base_path('.env');
    }

    public static function lockPath(): string
    {
        return self::$dir !== null ? self::$dir.'/installed' : storage_path('app/installed');
    }

    public static function tokenPath(): string
    {
        return self::$dir !== null ? self::$dir.'/install-token' : storage_path('app/install-token');
    }

    /** Installed: the lock file exists, or the database already has an account (also after a command-line install). */
    public static function installed(): bool
    {
        if (is_file(self::lockPath())) {
            return true;
        }
        if (trim((string) config('app.key')) === '') {
            return false;
        }
        try {
            return DB::table('users')->exists();
        } catch (Throwable) {
            return false; // no database or no tables yet
        }
    }

    public static function lock(): void
    {
        @file_put_contents(self::lockPath(), gmdate('c')."\n");
        @unlink(self::tokenPath());
    }

    public static function newToken(): string
    {
        $token = bin2hex(random_bytes(24));
        file_put_contents(self::tokenPath(), $token);
        @chmod(self::tokenPath(), 0600);

        return $token;
    }

    public static function tokenMatches(?string $token): bool
    {
        $saved = is_file(self::tokenPath()) ? trim((string) file_get_contents(self::tokenPath())) : '';

        return $saved !== '' && is_string($token) && hash_equals($saved, $token);
    }

    /**
     * Connects with the details from the form. Returns null when it works, else what to fix.
     *
     * @param  array{driver: string, host?: string, port?: string|int, name?: string, user?: string, password?: string, path?: string}  $db
     */
    public static function databaseProblem(array $db): ?string
    {
        try {
            if ($db['driver'] === 'sqlite') {
                $path = (string) ($db['path'] ?? '');
                if ($path === '' || (! is_file($path) && @touch($path) === false)) {
                    return "Couldn’t create the SQLite file at $path.";
                }
                new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

                return null;
            }
            $dsn = 'mysql:host='.($db['host'] ?? '127.0.0.1').';port='.($db['port'] ?? 3306).';dbname='.($db['name'] ?? '');
            $pdo = new PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['password'] ?? ''), [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $names = $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
            // A half-finished earlier run of this installer (MailVesper's own tables, no account yet) may be resumed.
            $resumable = in_array('migrations', $names, true) && (! in_array('users', $names, true) || ! $pdo->query('SELECT 1 FROM users LIMIT 1')->fetchColumn());
            if ($names && ! $resumable) {
                $n = count($names);

                return "The database “{$db['name']}” isn’t empty ($n ".($n === 1 ? 'table' : 'tables').'). Use a new, empty database.';
            }

            return null;
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Access denied')) {
                return 'The database refused that user or password. Check them, and that the user is added to the database with all privileges.';
            }
            if (str_contains($msg, 'Unknown database')) {
                return "There’s no database called “{$db['name']}”. Create it first (in cPanel: MySQL Databases).";
            }

            return 'Couldn’t connect to the database: '.mb_substr($msg, 0, 200);
        }
    }

    /** Null when the address works for MailVesper, else what to fix. */
    public static function urlProblem(string $url): ?string
    {
        $p = parse_url(trim($url));
        if (! is_array($p) || ! in_array($p['scheme'] ?? '', ['http', 'https'], true) || empty($p['host'])) {
            return 'Enter a full address, like https://mail.example.com';
        }
        if (! empty($p['path']) && $p['path'] !== '/') {
            return 'MailVesper needs its own (sub)domain: an address without a path, like https://mail.example.com';
        }

        return null;
    }

    /**
     * The .env settings every install writes (command line and browser), apart from the database and mail ones.
     *
     * @return array<string, string|bool|null>
     */
    public static function baseEnv(string $url, string $driver): array
    {
        $parts = (array) parse_url($url);
        $https = ($parts['scheme'] ?? '') === 'https';
        $hostPort = ($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        return [
            'APP_NAME' => 'MailVesper', 'APP_ENV' => 'production', 'APP_DEBUG' => false,
            'APP_URL' => $url.'/api', 'FRONTEND_URL' => $url,
            'LOG_CHANNEL' => 'daily', 'LOG_STACK' => 'daily', 'LOG_LEVEL' => 'info',
            'SESSION_DRIVER' => 'database', 'SESSION_COOKIE' => 'mailvesper_session', 'SESSION_PATH' => '/', 'SESSION_DOMAIN' => null,
            'SESSION_SECURE_COOKIE' => $https, 'SESSION_SAME_SITE' => 'lax', 'SANCTUM_STATEFUL_DOMAINS' => $hostPort,
            // SQLite: one writer at a time, so the rate-limit counters live in files and a single queue worker runs.
            'QUEUE_CONNECTION' => 'database', 'CACHE_STORE' => $driver === 'sqlite' ? 'file' : 'database',
            'QUEUE_WORKERS' => $driver === 'sqlite' ? '1' : '4',
            'HASH_DRIVER' => defined('PASSWORD_ARGON2ID') ? 'argon2id' : 'bcrypt',
        ];
    }

    /** The PHP command for cron: the CLI binary next to this PHP (under PHP-FPM, PHP_BINARY is the FPM daemon). */
    public static function phpCli(): string
    {
        foreach ([PHP_BINDIR.'/php', '/usr/local/bin/php', '/usr/bin/php'] as $bin) {
            if (is_file($bin) && is_executable($bin)) {
                return $bin;
            }
        }

        return 'php';
    }

    /** What goes in cPanel's "Command" box (cPanel sets the timing itself). */
    public static function cronCommand(): string
    {
        return 'cd '.base_path().' && '.self::phpCli().' artisan schedule:run >> /dev/null 2>&1';
    }

    /** The whole crontab line: every minute. */
    public static function cronLine(): string
    {
        return '* * * * * '.self::cronCommand();
    }
}
