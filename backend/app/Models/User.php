<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use App\Mail\ResetPasswordMail;
use App\Services\SafeMail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property Carbon|null $suspended_at
 * @property string|null $suspended_reason
 * @property string|null $current_workspace_id
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $last_login_at
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlids, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'timezone'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return ['suspended_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
        ];
    }

    /** @return BelongsToMany<Workspace, $this> */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<SocialAccount, $this> */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    public function roleIn(Workspace $workspace): ?WorkspaceRole
    {
        $role = DB::table('workspace_user')->where('workspace_id', $workspace->id)->where('user_id', $this->id)->value('role');

        return is_string($role) ? WorkspaceRole::from($role) : null;
    }

    /** Reset links open the MailVesper login page, not a Laravel route. */
    public function sendPasswordResetNotification($token): void
    {
        $url = rtrim((string) config('app.frontend_url'), '/').'/login/?reset='.urlencode($token).'&email='.urlencode($this->email);
        SafeMail::send($this->email, new ResetPasswordMail($url));
    }

    /** Email is normalised everywhere: trimmed and lower-cased. */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
