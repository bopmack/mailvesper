<?php

namespace App\Console\Commands;

use App\Support\EnvFile;
use App\Support\Requirements;
use App\Support\WebInstaller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use Symfony\Component\Process\Process;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * The self-hosting installer: checks the server, writes .env, creates the database tables and the admin account,
 * builds the web root and prints (or adds) the one cron line. Every question has an option, for scripted installs:
 *   php artisan mailvesper:install --url=https://mail.example.com --db=mysql --db-name=mailvesper --db-user=mailvesper \
 *     --admin-email=you@example.com --webroot=/var/www/mailvesper --no-interaction
 * Secrets come from the environment in scripted installs: MAILVESPER_DB_PASSWORD, MAILVESPER_MAIL_PASSWORD, MAILVESPER_ADMIN_PASSWORD.
 */
class Install extends Command
{
    protected $signature = 'mailvesper:install
        {--url= : The address people will open, e.g. https://mail.example.com}
        {--db= : mysql, mariadb or sqlite}
        {--db-host=127.0.0.1} {--db-port=} {--db-name=sendline} {--db-user=sendline}
        {--sqlite-path= : SQLite only: the database file (default database/database.sqlite)}
        {--mail= : How MailVesper sends its own emails (sign-in codes, invites): smtp, sendmail or log}
        {--mail-host=} {--mail-port=587} {--mail-user=} {--mail-encryption=tls} {--mail-from=}
        {--admin-email=} {--admin-name=} {--skip-admin : Create the admin later in the browser instead}
        {--webroot= : Build the web root at this path}
        {--cron : Add the scheduler line to this user\'s crontab (appended; nothing else is touched)}
        {--force : Run even though MailVesper is already installed here}';

    /** The name before the rename to MailVesper; scripts and cron lines that use it keep working. */
    protected $aliases = ['sendline:install'];

    protected $description = 'Install MailVesper on this server';

    private EnvFile $env;

    public function handle(): int
    {
        $this->env = new EnvFile(base_path('.env'));
        $this->line('');
        $this->line('  <options=bold>MailVesper installer</>');
        $this->line('');

        if ($this->alreadyInstalled() && ! $this->option('force')) {
            $this->info('MailVesper is already installed here. Check it with: php artisan mailvesper:check');
            $this->line('To change settings, edit .env and run: php artisan optimize');

            return self::SUCCESS;
        }

        // 1. The server
        $rows = Requirements::server();
        $this->printChecks($rows);
        if (! Requirements::passes($rows)) {
            $this->error('Fix the items marked ✗ and run the installer again.');

            return self::FAILURE;
        }

        // 2. Address
        $url = rtrim((string) ($this->option('url') ?: text('The address people will open', placeholder: 'https://mail.example.com', required: true, validate: fn ($v) => $this->urlProblem($v))), '/');
        if ($problem = $this->urlProblem($url)) {
            $this->error($problem);

            return self::FAILURE;
        }
        $parts = (array) parse_url($url);
        $https = ($parts['scheme'] ?? '') === 'https';
        if (! $https && ! in_array($parts['host'] ?? '', ['localhost', '127.0.0.1'], true)) {
            $this->warn('Without https, browsers send the sign-in cookie unencrypted. Put a certificate on this address before real use.');
        }

        // 3. Database
        $db = $this->database();
        if ($db === null) {
            return self::FAILURE;
        }

        // 4. MailVesper's own emails
        $mail = $this->mail($parts['host'] ?? 'localhost');

        // 5. .env
        if (! $this->env->exists()) {
            copy(base_path('.env.example'), base_path('.env'));
        }
        $this->env->set(WebInstaller::baseEnv($url, (string) $db['DB_CONNECTION']) + $db + $mail);
        $this->info('✓ Settings saved to '.base_path('.env'));

        // 6. Key, tables, caches: in fresh processes, so they read the new .env
        if (trim((string) $this->env->get('APP_KEY')) === '' && ! $this->artisan(['key:generate', '--force'], 'Encryption key')) {
            return self::FAILURE;
        }
        if (! $this->artisan(['config:clear'], null) || ! $this->artisan(['migrate', '--force'], 'Database tables')) {
            return self::FAILURE;
        }

        // 7. Admin
        if (! $this->option('skip-admin')) {
            $email = (string) ($this->option('admin-email') ?: text('Admin email (your login)', required: true));
            $args = ['mailvesper:make-admin', '--email='.$email];
            if ($this->option('admin-name')) {
                $args[] = '--name='.$this->option('admin-name');
            }
            $env = [];
            if (! (getenv('MAILVESPER_ADMIN_PASSWORD') ?: getenv('MAILVESPER_ADMIN_PASSWORD'))) {
                if (! $this->input->isInteractive()) {
                    $this->error('Set MAILVESPER_ADMIN_PASSWORD (or use --skip-admin and create the admin in the browser).');

                    return self::FAILURE;
                }
                $env['MAILVESPER_ADMIN_PASSWORD'] = password('Admin password (12+ characters)', required: true, validate: fn ($v) => mb_strlen($v) < 12 ? 'Use at least 12 characters.' : null);
            }
            if (! $this->artisan([...$args, '--no-interaction'], 'Admin account', $env)) {
                return self::FAILURE;
            }
        } else {
            $this->warn('No admin yet: the first person to open '.$url.'/signup/ becomes the admin. Do it now.');
        }

        // 8. Web root
        $webroot = (string) ($this->option('webroot') ?: ($this->input->isInteractive() ? text('Web root to build (the web server\'s document root; empty to skip)', default: '') : ''));
        if ($webroot !== '' && ! $this->artisan(['mailvesper:webroot', $webroot], 'Web root')) {
            return self::FAILURE;
        }
        $this->artisan(['optimize'], 'Caches');

        // 9. Scheduler
        $cron = '* * * * * cd '.base_path().' && '.PHP_BINARY.' artisan schedule:run >> /dev/null 2>&1';
        $addCron = $this->option('cron') || ($this->input->isInteractive() && confirm('Add the scheduler to this user\'s crontab now? (One line is appended; existing lines stay.)', default: true));
        if ($addCron) {
            $this->addCron($cron);
        } else {
            $this->line('');
            $this->line('Add this line to the crontab of the user that owns these files (crontab -e):');
            $this->line("  $cron");
        }

        $this->line('');
        $this->info('Done. Open '.$url.'/app/ to log in.');
        if ($webroot === '') {
            $this->line('Build the web root with: php artisan mailvesper:webroot /path/to/document-root');
        }
        $this->line('Check the installation any time with: php artisan mailvesper:check');

        return self::SUCCESS;
    }

    private function alreadyInstalled(): bool
    {
        if (trim((string) $this->env->get('APP_KEY')) === '') {
            return false;
        }
        try {
            return DB::table('users')->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function urlProblem(string $url): ?string
    {
        return WebInstaller::urlProblem($url);
    }

    /** @return array<string, string|null>|null */
    private function database(): ?array
    {
        $driver = (string) ($this->option('db') ?: select('Database', ['mysql' => 'MySQL 8+', 'mariadb' => 'MariaDB 10.6+', 'sqlite' => 'SQLite (for trying MailVesper out)'], default: 'mysql'));
        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            $this->error('--db must be mysql, mariadb or sqlite.');

            return null;
        }
        if ($driver === 'sqlite') {
            $path = (string) ($this->option('sqlite-path') ?: database_path('database.sqlite'));
            if (! is_file($path) && @touch($path) === false) {
                $this->error("Couldn’t create $path.");

                return null;
            }
            $this->info('✓ SQLite database at '.$path);
            $this->warn('SQLite is for trying MailVesper out: it handles one write at a time. Use MySQL or MariaDB for real sending.');

            return ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $path, 'DB_HOST' => null, 'DB_PORT' => null, 'DB_USERNAME' => null, 'DB_PASSWORD' => null];
        }
        $interactive = $this->input->isInteractive() && ! $this->option('db');
        $defaultPort = '3306';
        $host = $interactive ? text('Database host', default: (string) $this->option('db-host')) : (string) $this->option('db-host');
        $port = (string) ($this->option('db-port') ?: ($interactive ? text('Database port', default: $defaultPort) : $defaultPort));
        $name = $interactive ? text('Database name', default: (string) $this->option('db-name')) : (string) $this->option('db-name');
        $user = $interactive ? text('Database user', default: (string) $this->option('db-user')) : (string) $this->option('db-user');
        $pass = (string) ((getenv('MAILVESPER_DB_PASSWORD') ?: getenv('MAILVESPER_DB_PASSWORD')) ?: ($this->input->isInteractive() ? password('Database password') : ''));

        $dsn = "mysql:host=$host;port=$port;dbname=$name";
        try {
            new PDO($dsn, $user, $pass, [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $e) {
            $this->error('Couldn’t connect to the database: '.$e->getMessage());
            $this->line('Create an empty database and a user that owns it, then run the installer again.');

            return null;
        }
        $this->info("✓ Connected to $driver database $name on $host");

        return ['DB_CONNECTION' => $driver, 'DB_HOST' => $host, 'DB_PORT' => $port, 'DB_DATABASE' => $name, 'DB_USERNAME' => $user, 'DB_PASSWORD' => $pass];
    }

    /** @return array<string, string|null> */
    private function mail(string $host): array
    {
        $how = (string) ($this->option('mail') ?: select('How should MailVesper send its own emails (sign-in codes, team invites)?', [
            'smtp' => 'An SMTP server', 'sendmail' => 'This server\'s sendmail', 'log' => 'Not yet (write them to the log)',
        ], default: 'smtp'));
        $from = (string) ($this->option('mail-from') ?: ($this->input->isInteractive() && $how !== 'log' ? text('From address for those emails', default: "no-reply@$host") : "no-reply@$host"));
        $out = ['MAIL_MAILER' => in_array($how, ['smtp', 'sendmail', 'log'], true) ? $how : 'log', 'MAIL_FROM_ADDRESS' => $from, 'MAIL_FROM_NAME' => 'MailVesper'];
        if ($how === 'smtp') {
            $interactive = $this->input->isInteractive() && ! $this->option('mail-host');
            $port = $interactive ? text('SMTP port', default: (string) $this->option('mail-port')) : (string) $this->option('mail-port');
            $out += [
                'MAIL_HOST' => $interactive ? text('SMTP host', required: true) : (string) $this->option('mail-host'),
                'MAIL_PORT' => $port,
                'MAIL_USERNAME' => ($interactive ? text('SMTP username', default: (string) $this->option('mail-user')) : (string) $this->option('mail-user')) ?: null,
                'MAIL_PASSWORD' => (string) ((getenv('MAILVESPER_MAIL_PASSWORD') ?: getenv('MAILVESPER_MAIL_PASSWORD')) ?: ($interactive ? password('SMTP password') : '')) ?: null,
                // Laravel's "scheme": smtps is implicit TLS (port 465); on 587 STARTTLS is used automatically.
                'MAIL_SCHEME' => $port === '465' || $this->option('mail-encryption') === 'ssl' ? 'smtps' : null,
            ];
        } elseif ($how === 'log') {
            $this->warn('Sign-in codes and invites will only appear in storage/logs until you set MAIL_* in .env.');
        }

        return $out;
    }

    /** @param  list<array{name: string, ok: bool, detail: string, required: bool}>  $rows */
    private function printChecks(array $rows): void
    {
        foreach ($rows as $r) {
            $mark = $r['ok'] ? '<fg=green>✓</>' : ($r['required'] ? '<fg=red>✗</>' : '<fg=yellow>!</>');
            $this->line("  $mark {$r['name']}  <fg=gray>{$r['detail']}</>");
        }
        $this->line('');
    }

    /**
     * Runs an artisan command in a new PHP process (so it reads the .env just written).
     *
     * @param  list<string>  $args
     * @param  array<string, string>  $env
     */
    private function artisan(array $args, ?string $label, array $env = []): bool
    {
        // This process loaded the old .env into its environment, and a child would inherit it (inherited variables
        // beat the .env file): unset every key the file defines.
        preg_match_all('/^\s*([A-Z][A-Z0-9_]*)\s*=/m', (string) @file_get_contents(base_path('.env')), $m);
        $p = new Process([PHP_BINARY, base_path('artisan'), ...$args], base_path(), $env + array_fill_keys($m[1], false));
        $p->setTimeout(600);
        $p->run();
        if (! $p->isSuccessful()) {
            $this->error('✗ '.($label ?? implode(' ', $args)).' failed:');
            $this->line(trim($p->getErrorOutput()."\n".$p->getOutput()));

            return false;
        }
        if ($label) {
            $this->info("✓ $label");
        }

        return true;
    }

    /** Appends the line unless an identical scheduler line is already there. Never rewrites the rest. */
    private function addCron(string $line): void
    {
        $list = new Process(['crontab', '-l']);
        $list->run();
        $current = $list->isSuccessful() ? $list->getOutput() : ''; // "no crontab for user" is a failure with no output
        if (str_contains($current, 'cd '.base_path().' && ') && str_contains($current, 'schedule:run')) {
            $this->info('✓ Scheduler already in the crontab');

            return;
        }
        $next = rtrim($current)."\n".($current === '' ? '' : "\n")."# MailVesper scheduler\n$line\n";
        $write = new Process(['crontab', '-']);
        $write->setInput(ltrim($next, "\n"));
        $write->run();
        if ($write->isSuccessful()) {
            $this->info('✓ Scheduler added to the crontab');
        } else {
            $this->warn('Couldn’t update the crontab ('.trim($write->getErrorOutput()).'). Add this line yourself (crontab -e):');
            $this->line("  $line");
        }
    }
}
