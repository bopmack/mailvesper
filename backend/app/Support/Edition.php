<?php

namespace App\Support;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\Workspace;

/**
 * Which edition this installation runs. Community is the free edition: one admin login that can run many
 * workspaces. Pro is a separate, private package (never in the public repository) that plugs in through this class
 * when its folder is present and holds a valid MailVesper license. Core code never references Pro classes; Pro adds
 * its routes, settings, front-end scripts and hooks from its own service provider.
 */
class Edition
{
    /** Pro features, as shown on the locked pages of a Community install. */
    public const PRO_FEATURES = ['agent', 'team', 'copilot_chat', 'inbox_tests', 'saas'];

    private bool $pro = false;

    /** @var list<string> */
    private array $features = [];

    /** @var array<string, mixed>|null the verified license payload (licensee, expiry…) */
    private ?array $license = null;

    private ?string $licenseProblem = null;

    private ?string $version = null;

    /** @var array<string, string> name → URL of a Pro front-end script, loaded after the core scripts */
    private array $scripts = [];

    /** @var list<callable(User, array<string, mixed>): array<string, mixed>> */
    private array $meHooks = [];

    /** @var list<callable(User, array<string, mixed>): ?string> */
    private array $loginHooks = [];

    /** @var (callable(string): array{ok: bool, payload: array<string, mixed>|null, problem: string|null, token?: string})|null */
    private $licenseChecker = null;

    /** @var list<callable(string): void> */
    private array $removedHooks = [];

    /** @var (callable(Workspace, string): ?int)|null remaining room for contacts / emails (null = unlimited) */
    private $roomPolicy = null;

    /** @var (callable(Workspace, string): string)|null */
    private $limitMessage = null;

    /** @var list<callable(Workspace, User): void> */
    private array $workspaceHooks = [];

    /** @var list<callable(Workspace, User): void> */
    private array $deletionGuards = [];

    /** @var (callable(?string): bool)|null */
    private $signupPolicy = null;

    /**
     * Called by the Pro service provider once its license checks out.
     *
     * @param  list<string>  $features
     * @param  array<string, mixed>  $license
     */
    public function enablePro(array $features, array $license, string $version): void
    {
        $this->pro = true;
        $this->features = array_values(array_intersect($features, self::PRO_FEATURES));
        $this->license = $license;
        $this->version = $version;
        $this->licenseProblem = null;
    }

    /** The Pro package is installed but its license isn't valid (missing, expired, wrong domain…). */
    public function proUnavailable(string $reason): void
    {
        $this->licenseProblem = $reason;
    }

    /** @param callable(string): array{ok: bool, payload: array<string, mixed>|null, problem: string|null, token?: string} $checker Pro may exchange a key for a token to store */
    public function setLicenseChecker(callable $checker): void
    {
        $this->licenseChecker = $checker;
    }

    /** @param callable(string): void $hook told the old token when the admin removes the license (Pro frees the domain) */
    public function onLicenseRemoved(callable $hook): void
    {
        $this->removedHooks[] = $hook;
    }

    public function licenseRemoved(string $oldToken): void
    {
        foreach ($this->removedHooks as $hook) {
            $hook($oldToken);
        }
    }

    /**
     * Plan limits (Pro SaaS mode). $room returns how many more contacts/emails a workspace may add this period, or
     * null for no limit; $message explains a refusal ("Your plan includes 25,000 contacts…").
     *
     * @param  callable(Workspace, string): ?int  $room
     * @param  callable(Workspace, string): string  $message
     */
    public function limitPolicy(callable $room, callable $message): void
    {
        $this->roomPolicy = $room;
        $this->limitMessage = $message;
    }

    /** How many more of "contacts" or "emails" this workspace may add right now (null = no limit). */
    public function room(Workspace $ws, string $what): ?int
    {
        return $this->roomPolicy ? ($this->roomPolicy)($ws, $what) : null;
    }

    /** Refuses with a plan_limit error when adding $count would go over the workspace's limit. */
    public function assertRoom(Workspace $ws, string $what, int $count = 1): void
    {
        if ($count <= 0) {
            return;
        }
        $room = $this->room($ws, $what);
        if ($room !== null && $count > $room) {
            throw new ApiException('plan_limit', $this->limitMessage ? ($this->limitMessage)($ws, $what) : 'Your plan’s limit is reached.', 402, details: ['limit' => $what, 'room' => $room]);
        }
    }

    /** @param callable(Workspace, User): void $hook runs after a workspace is created (Pro: start the trial) */
    public function onWorkspaceCreated(callable $hook): void
    {
        $this->workspaceHooks[] = $hook;
    }

    public function workspaceCreated(Workspace $ws, User $user): void
    {
        foreach ($this->workspaceHooks as $hook) {
            $hook($ws, $user);
        }
    }

    /** @param callable(Workspace, User): void $guard throws an ApiException to refuse deleting the workspace (Pro: a plan still billing) */
    public function onWorkspaceDeleting(callable $guard): void
    {
        $this->deletionGuards[] = $guard;
    }

    public function workspaceDeleting(Workspace $ws, User $user): void
    {
        foreach ($this->deletionGuards as $guard) {
            $guard($ws, $user);
        }
    }

    /** Whether the Pro package is installed on this server (licensed or not). */
    public function proInstalled(): bool
    {
        return $this->licenseChecker !== null;
    }

    /**
     * Checks a license key with the installed Pro package; null when Pro isn't installed. "token" is what to store
     * when Pro exchanged the key for one (activation at the license server).
     *
     * @return array{ok: bool, payload: array<string, mixed>|null, problem: string|null, token?: string}|null
     */
    public function checkLicense(string $key): ?array
    {
        return $this->licenseChecker ? ($this->licenseChecker)($key) : null;
    }

    public function isPro(): bool
    {
        return $this->pro;
    }

    public function has(string $feature): bool
    {
        return $this->pro && in_array($feature, $this->features, true);
    }

    public function addScript(string $name, string $url): void
    {
        $this->scripts[$name] = $url;
    }

    /** @param callable(User, array<string, mixed>): array<string, mixed> $hook adds to (or adjusts) the /me payload */
    public function extendMe(callable $hook): void
    {
        $this->meHooks[] = $hook;
    }

    /** @param callable(User, array<string, mixed>): ?string $hook runs after a social sign-in; returns an error code or null */
    public function afterSocialLogin(callable $hook): void
    {
        $this->loginHooks[] = $hook;
    }

    /** @param callable(?string): bool $policy gets the new account's email (null = asking in general); replaces the Community rule */
    public function signupPolicy(callable $policy): void
    {
        $this->signupPolicy = $policy;
    }

    /** Whether a new account may be created right now (sign-up form and first social sign-in), for this email. */
    public function signupOpen(?string $email = null): bool
    {
        if ($this->signupPolicy) {
            return (bool) ($this->signupPolicy)($email);
        }

        return false; // Community: the installer creates the admin; nobody signs up. The admin adds workspaces.
    }

    /** Under the Community rule the first account is the installation's admin: trusted, and mail may not be set up yet. */
    public function trustsFirstAccount(): bool
    {
        return $this->signupPolicy === null;
    }

    /**
     * @param  array<string, mixed>  $me
     * @return array<string, mixed>
     */
    public function me(User $user, array $me): array
    {
        foreach ($this->meHooks as $hook) {
            $me = $hook($user, $me);
        }

        return $me;
    }

    /** @param array<string, mixed> $state */
    public function socialLogin(User $user, array $state): ?string
    {
        $error = null;
        foreach ($this->loginHooks as $hook) {
            $error ??= $hook($user, $state);
        }

        return $error;
    }

    /** @return array<string, mixed> what the front end needs: edition, features, Pro scripts, the upgrade link */
    public function toArray(): array
    {
        return [
            'name' => $this->pro ? 'pro' : 'community',
            'version' => $this->version,
            'features' => $this->pro ? $this->features : [],
            'scripts' => array_values($this->scripts),
            'license' => $this->license ? ['licensee' => $this->license['licensee'] ?? null, 'expires_at' => $this->license['expires_at'] ?? null] : null,
            'license_problem' => $this->licenseProblem,
            'upgrade_url' => (string) config('sendline.pro_url'),
        ];
    }
}
