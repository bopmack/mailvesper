<?php

namespace App\Console\Commands;

use App\Services\Ops\Health;
use App\Support\Edition;
use App\Support\Requirements;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Is this installation set up right? Server, settings, database, scheduler, queue and the public address. */
class Check extends Command
{
    protected $signature = 'mailvesper:check {--no-http : Skip calling the public address}';

    /** The name before the rename to MailVesper; scripts and cron lines that use it keep working. */
    protected $aliases = ['sendline:check'];

    protected $description = 'Check this MailVesper installation';

    public function handle(): int
    {
        $rows = Requirements::server();

        $key = (string) config('app.key');
        $rows[] = $this->row('Encryption key (APP_KEY)', $key !== '', $key !== '' ? 'Set. Back it up with the database: without it, saved passwords and keys can’t be read' : 'Missing: php artisan key:generate');
        $prod = app()->environment('production');
        $debug = (bool) config('app.debug');
        $rows[] = $this->row('Production settings', $prod && ! $debug, 'APP_ENV='.app()->environment().', APP_DEBUG='.($debug ? 'true (shows errors to visitors: turn it off)' : 'false'), false);
        $front = rtrim((string) config('app.frontend_url'), '/');
        $api = rtrim((string) config('app.url'), '/');
        $rows[] = $this->row('Addresses', $api === $front.'/api', "FRONTEND_URL=$front, APP_URL=$api".($api === $front.'/api' ? '' : ' (APP_URL must be FRONTEND_URL + /api)'));
        $stateful = (array) config('sanctum.stateful');
        $host = (string) parse_url($front, PHP_URL_HOST).(parse_url($front, PHP_URL_PORT) ? ':'.parse_url($front, PHP_URL_PORT) : '');
        $rows[] = $this->row('Sign-in cookie domain', in_array($host, $stateful, true), in_array($host, $stateful, true) ? "SANCTUM_STATEFUL_DOMAINS includes $host" : "Add $host to SANCTUM_STATEFUL_DOMAINS, or logins won’t stick");

        try {
            DB::select('select 1');
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);
            $pending = $migrator->repositoryExists() ? count(array_diff(array_keys($files), $migrator->getRepository()->getRan())) : count($files);
            $rows[] = $this->row('Database', $pending === 0, DB::connection()->getDriverName().($pending ? ": $pending update(s) waiting, run php artisan migrate --force" : ': connected, up to date'));
            $h = Health::snapshot();
            $age = $h['scheduler']['age_seconds'];
            $rows[] = $this->row('Background tasks', (bool) $h['scheduler']['ok'], match ($h['scheduler']['mode'] ?? null) {
                'cron' => "Cron job: last ran {$age}s ago",
                'visits' => 'No cron job: scheduled campaigns and automations only run while people use MailVesper. Add the cron job (INSTALL.md → The cron job)',
                default => $age === null ? 'No cron job yet. Add it: INSTALL.md → The cron job' : "Cron job stopped: last ran {$age}s ago",
            });
            $rows[] = $this->row('Queue', (bool) $h['queue']['ok'], "{$h['queue']['pending']} waiting, {$h['queue']['failed_24h']} failed in 24h", false);
            $admins = DB::table('users')->count();
            $rows[] = $this->row('Admin account', $admins > 0, $admins > 0 ? 'Created' : 'None yet: php artisan mailvesper:make-admin');
            $edition = app(Edition::class);
            $rows[] = $this->row('Edition', true, $edition->isPro() ? 'Pro' : ($edition->proInstalled() ? 'Community (Pro files present: add the license key in Settings → General)' : 'Community'));
        } catch (Throwable $e) {
            $rows[] = $this->row('Database', false, 'Can’t connect: '.$e->getMessage());
        }

        if (! $this->option('no-http')) {
            try {
                $r = Http::timeout(10)->acceptJson()->get($api.'/v1/health');
                $rows[] = $this->row('Public address', $r->ok() && $r->json('status') === 'ok', "GET $api/v1/health → HTTP {$r->status()}", false);
                $page = Http::timeout(10)->get($front.'/app/');
                $rows[] = $this->row('App pages', $page->ok() && str_contains($page->body(), '<html'), "GET $front/app/ → HTTP {$page->status()}".($page->ok() ? '' : ' (build the web root: php artisan mailvesper:webroot)'), false);
            } catch (Throwable $e) {
                $rows[] = $this->row('Public address', false, "Couldn’t reach $api: ".$e->getMessage(), false);
            }
        }

        foreach ($rows as $r) {
            $mark = $r['ok'] ? '<fg=green>✓</>' : ($r['required'] ? '<fg=red>✗</>' : '<fg=yellow>!</>');
            $this->line("  $mark {$r['name']}  <fg=gray>{$r['detail']}</>");
        }
        $ok = Requirements::passes($rows);
        $this->line('');
        $ok ? $this->info('MailVesper is set up correctly.') : $this->error('Fix the items marked ✗.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{name: string, ok: bool, detail: string, required: bool} */
    private function row(string $name, bool $ok, string $detail, bool $required = true): array
    {
        return ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'required' => $required];
    }
}
