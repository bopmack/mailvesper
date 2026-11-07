<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use App\Support\EnvFile;
use App\Support\Requirements;
use App\Support\WebInstaller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

/**
 * The browser installer behind /install/ (the zip download, for hosting without a terminal). Four steps, each its own
 * request so every step after the first runs with the .env it wrote: check → settings (.env) → tables → admin.
 * These routes have no session, cookies or database middleware: they run before any of that exists. Once the admin
 * exists they answer 404 for good.
 */
class InstallController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        if (WebInstaller::installed()) {
            return response()->json(['installed' => true]);
        }
        $checks = Requirements::server();
        $root = WebInstaller::webroot();
        $checks[] = ['name' => 'Web folder', 'ok' => $root !== null, 'detail' => $root ?? 'Not found: upload the whole zip, then point the domain at its public_html folder', 'required' => true];

        return response()->json([
            'installed' => false,
            'checks' => $checks,
            'ready' => Requirements::passes($checks),
            'defaults' => [
                'url' => $request->getSchemeAndHttpHost(),
                'sqlite_path' => database_path('database.sqlite'),
                'mail_from' => 'no-reply@'.$request->getHost(),
            ],
        ]);
    }

    public function database(Request $request): JsonResponse
    {
        $this->open();
        $db = $this->dbInput($request);
        $problem = WebInstaller::databaseProblem($db);

        return $problem === null ? response()->json(['ok' => true]) : $this->fail('db', $problem);
    }

    /** Step 1: checks everything, then writes .env. Returns the token the next steps need. */
    public function settings(Request $request): JsonResponse
    {
        $this->open();
        $url = rtrim(trim((string) $request->input('url')), '/');
        if ($problem = WebInstaller::urlProblem($url)) {
            return $this->fail('url', $problem);
        }
        $db = $this->dbInput($request);
        if ($problem = WebInstaller::databaseProblem($db)) {
            return $this->fail('db', $problem);
        }
        $mail = $this->mailInput($request);
        if (is_string($mail)) {
            return $this->fail('mail', $mail);
        }

        $path = WebInstaller::envPath();
        $env = new EnvFile($path);
        if (! $env->exists() && ! @copy(base_path('.env.example'), $path)) {
            return $this->fail('env', "Couldn’t create $path. Make the backend folder writable for PHP and try again.");
        }
        $values = WebInstaller::baseEnv($url, $db['driver']) + $this->dbEnv($db) + $mail;
        if (trim((string) $env->get('APP_KEY')) === '') {
            $values['APP_KEY'] = 'base64:'.base64_encode(random_bytes(32));
        }
        if ($root = WebInstaller::webroot()) {
            $values['MAILVESPER_PUBLIC_ROOT'] = $root; // uploaded images go straight into the web folder's media/
        }
        try {
            $env->set($values);
            @chmod($path, 0600);
        } catch (Throwable $e) {
            return $this->fail('env', "Couldn’t write $path: ".$e->getMessage());
        }

        return response()->json(['ok' => true, 'token' => WebInstaller::newToken()]);
    }

    /** Step 2: the database tables (a fresh request, so it reads the new .env). */
    public function tables(Request $request): JsonResponse
    {
        $this->open($request);
        try {
            @set_time_limit(300);
            Artisan::call('migrate', ['--force' => true]);
        } catch (Throwable $e) {
            return $this->fail('tables', 'Creating the tables failed: '.mb_substr($e->getMessage(), 0, 300));
        }

        return response()->json(['ok' => true]);
    }

    /** Step 3: the installation's admin (super admin). Then the installer closes. */
    public function admin(Request $request): JsonResponse
    {
        $this->open($request);
        $v = Validator::make($request->only('name', 'email', 'password', 'timezone'), [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', Password::min(12)->max(128)],
            'timezone' => ['nullable', 'timezone:all'],
        ], ['password.min' => 'Use at least 12 characters.']);
        if ($v->fails()) {
            return response()->json(['ok' => false, 'step' => 'admin', 'fields' => $v->errors()->toArray(), 'message' => $v->errors()->first()], 422);
        }
        if (User::query()->exists()) {
            abort(404);
        }
        $d = $v->validated();
        $user = User::create(['name' => trim($d['name']), 'email' => User::normalizeEmail($d['email']), 'password' => $d['password'], 'timezone' => $d['timezone'] ?? 'UTC']);
        $user->markEmailAsVerified();
        Audit::log('auth.signup', $user, meta: ['first_admin' => true, 'via' => 'web_installer']);
        WebInstaller::lock();
        if (! app()->runningUnitTests()) {
            try {
                Artisan::call('optimize');
            } catch (Throwable) {
                // caches are a speed-up only
            }
        }

        return response()->json(['ok' => true, 'cron' => WebInstaller::cronLine(), 'cron_command' => WebInstaller::cronCommand(), 'login' => rtrim((string) config('app.frontend_url'), '/').'/login/']);
    }

    /** 404 once installed; with a $request, the step also needs this run's token. */
    private function open(?Request $request = null): void
    {
        if (WebInstaller::installed()) {
            abort(404);
        }
        if ($request && ! WebInstaller::tokenMatches($request->header('X-Install-Token'))) {
            abort(403, 'This install was started in another window. Reload the installer and start again.');
        }
    }

    /** @return array{driver: string, host: string, port: string, name: string, user: string, password: string, path: string} */
    private function dbInput(Request $request): array
    {
        $driver = (string) $request->input('db.driver', 'mysql');

        return [
            'driver' => in_array($driver, ['mysql', 'mariadb', 'sqlite'], true) ? $driver : 'mysql',
            'host' => trim((string) $request->input('db.host', '127.0.0.1')) ?: '127.0.0.1',
            'port' => (string) ((int) $request->input('db.port', 3306) ?: 3306),
            'name' => trim((string) $request->input('db.name')),
            'user' => trim((string) $request->input('db.user')),
            'password' => (string) $request->input('db.password', ''),
            'path' => database_path('database.sqlite'),
        ];
    }

    /**
     * @param  array{driver: string, host: string, port: string, name: string, user: string, password: string, path: string}  $db
     * @return array<string, string|null>
     */
    private function dbEnv(array $db): array
    {
        if ($db['driver'] === 'sqlite') {
            return ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $db['path'], 'DB_HOST' => null, 'DB_PORT' => null, 'DB_USERNAME' => null, 'DB_PASSWORD' => null];
        }

        return ['DB_CONNECTION' => $db['driver'], 'DB_HOST' => $db['host'], 'DB_PORT' => $db['port'], 'DB_DATABASE' => $db['name'], 'DB_USERNAME' => $db['user'], 'DB_PASSWORD' => $db['password']];
    }

    /** @return array<string, string|null>|string The MAIL_* settings, or what to fix. */
    private function mailInput(Request $request): array|string
    {
        $how = (string) $request->input('mail.mailer', 'log');
        $from = trim((string) $request->input('mail.from', 'no-reply@'.$request->getHost()));
        if ($how !== 'log' && ! filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return 'Enter the From address for MailVesper’s own emails, like no-reply@example.com.';
        }
        $out = ['MAIL_MAILER' => in_array($how, ['smtp', 'sendmail', 'log'], true) ? $how : 'log', 'MAIL_FROM_ADDRESS' => $from ?: 'no-reply@'.$request->getHost(), 'MAIL_FROM_NAME' => 'MailVesper'];
        if ($out['MAIL_MAILER'] === 'smtp') {
            $host = trim((string) $request->input('mail.host'));
            if ($host === '') {
                return 'Enter the SMTP server, like mail.example.com.';
            }
            $port = (string) ((int) $request->input('mail.port', 587) ?: 587);
            $out += [
                'MAIL_HOST' => $host, 'MAIL_PORT' => $port,
                'MAIL_USERNAME' => trim((string) $request->input('mail.user')) ?: null,
                'MAIL_PASSWORD' => (string) $request->input('mail.password', '') ?: null,
                'MAIL_SCHEME' => $port === '465' ? 'smtps' : null, // implicit TLS on 465; STARTTLS on 587 is automatic
            ];
        }

        return $out;
    }

    private function fail(string $step, string $message): JsonResponse
    {
        return response()->json(['ok' => false, 'step' => $step, 'message' => $message], 422);
    }
}
