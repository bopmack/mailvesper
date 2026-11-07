<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates the installation's admin, the very first account, from the server. The installer runs it, so there is never
 * a window in which a stranger could sign up first. The password comes from a prompt or MAILVESPER_ADMIN_PASSWORD
 * (never a command-line argument, which other users of the server could read).
 */
class MakeAdmin extends Command
{
    protected $signature = 'mailvesper:make-admin {--email= : The admin\'s email} {--name= : The admin\'s name} {--timezone=UTC}';

    /** The name before the rename to MailVesper; scripts and cron lines that use it keep working. */
    protected $aliases = ['sendline:make-admin'];

    protected $description = 'Create the first account: this installation\'s admin';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->error('This installation already has accounts, and its admin is the first of them. To add another admin: php artisan platform:admin you@example.com');

            return self::FAILURE;
        }
        $email = (string) ($this->option('email') ?: text('Admin email', required: true));
        $name = (string) ($this->option('name') ?: text('Admin name', default: ucfirst(strstr($email, '@', true) ?: 'Admin'), required: true));
        $pass = (string) ((getenv('MAILVESPER_ADMIN_PASSWORD') ?: getenv('SENDLINE_ADMIN_PASSWORD')) ?: '');
        if ($pass === '') {
            if (! $this->input->isInteractive()) {
                $this->error('Set MAILVESPER_ADMIN_PASSWORD, or run this command in a terminal to be asked for it.');

                return self::FAILURE;
            }
            $pass = password('Admin password (12+ characters)', required: true);
        }

        $v = Validator::make(['name' => $name, 'email' => $email, 'password' => $pass, 'timezone' => $this->option('timezone')], [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', Password::min(12)->max(128)],
            'timezone' => ['required', 'timezone:all'],
        ], ['password.min' => 'Use at least 12 characters.']);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $e) {
                $this->error($e);
            }

            return self::FAILURE;
        }

        $user = User::create(['name' => trim($name), 'email' => User::normalizeEmail($email), 'password' => $pass, 'timezone' => (string) $this->option('timezone')]);
        $user->markEmailAsVerified();
        Audit::log('auth.signup', $user, meta: ['first_admin' => true, 'via' => 'cli']);
        $this->info("Admin account created for {$user->email}. Log in and name your first workspace.");

        return self::SUCCESS;
    }
}
