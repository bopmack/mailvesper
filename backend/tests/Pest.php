<?php

use App\Models\User;
use App\Models\Workspace;
use App\Support\Edition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/** Sign-up as on a Pro install with open sign-up (Community only lets the first account, the admin, sign up). */
function openSignup(): void
{
    app(Edition::class)->signupPolicy(fn (?string $email) => true);
}

/** Runs the test as a licensed Pro install (core only: no Pro routes or scripts), for what Community locks. */
function asProEdition(): void
{
    app(Edition::class)->enablePro([], ['licensee' => 'Test'], 'test');
}

const STRONG_PASSWORD = 'correct horse battery staple';

function makeUser(array $attrs = []): User
{
    return User::create(array_merge([
        'name' => 'Amara Okafor',
        'email' => 'amara@example.com',
        'password' => STRONG_PASSWORD,
    ], $attrs));
}

function verifiedUser(array $attrs = []): User
{
    $user = makeUser($attrs);
    $user->markEmailAsVerified();

    return $user;
}

/** Make the breached-password API report this password as compromised. */
function markPasswordBreached(string $password): void
{
    TestCase::$breached[] = $password;
}

const AWS_KEY = 'AKIAIOSFODNN7EXAMPLE';
const AWS_SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

/** A verified owner signed in to workspace "Acme" (plus optional members by role). Returns [workspace, people]. */
function sesWorkspace($test, array $roles = []): array
{
    $owner = verifiedUser(['email' => 'owner@example.com', 'name' => 'Olu Owner']);
    $ws = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'owner_id' => $owner->id]);
    $ws->members()->attach($owner->id, ['role' => 'owner']);
    $owner->forceFill(['current_workspace_id' => $ws->id])->save();
    $people = ['owner' => $owner];
    foreach ($roles as $role) {
        $u = verifiedUser(['email' => "$role@example.com", 'name' => ucfirst($role)]);
        $ws->members()->attach($u->id, ['role' => $role]);
        $people[$role] = $u;
    }
    $test->postJson('/v1/auth/login', ['email' => 'owner@example.com', 'password' => STRONG_PASSWORD])->assertOk();

    return [$ws, $people];
}

function sesConnect($test, Workspace $ws, array $overrides = [])
{
    return $test->postJson("/v1/workspaces/{$ws->id}/ses/connect", $overrides + ['access_key_id' => AWS_KEY, 'secret_access_key' => AWS_SECRET, 'region' => 'eu-west-1']);
}

/** Signs in as someone else in the same test (clears the previous session). */
function switchTo($test, string $email): void
{
    app('auth')->forgetGuards();
    $test->postJson('/v1/auth/logout');
    app('auth')->forgetGuards();
    $test->postJson('/v1/auth/login', ['email' => $email, 'password' => STRONG_PASSWORD])->assertOk();
}
