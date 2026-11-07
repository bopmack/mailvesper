<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $auth_type
 * @property string $access_key_id
 * @property string $secret_access_key
 * @property string $region
 * @property string|null $aws_account_id
 * @property string|null $iam_arn
 * @property string $status
 * @property bool $production_access
 * @property bool $sending_enabled
 * @property string|null $enforcement_status
 * @property float $max_24h_send
 * @property float $max_send_rate
 * @property float $sent_last_24h
 * @property float|null $bounce_rate
 * @property float|null $complaint_rate
 * @property string|null $review_status
 * @property string|null $configuration_set
 * @property string|null $sns_topic_arn
 * @property string|null $subscription_arn
 * @property Carbon|null $subscription_confirmed_at
 * @property string $webhook_token
 * @property string $webhook_token_hash
 * @property array<string, array{status: string, detail?: string}>|null $setup_steps
 * @property array<string, bool>|null $permissions
 * @property string|null $last_error
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $last_event_at
 * @property string|null $connected_by
 * @property Carbon|null $created_at
 * @property-read Workspace $workspace
 */
class SesConnection extends Model
{
    use HasUlids;

    public const CONFIG_SET = 'sendline-events';

    /** The same events without SES's open and click tracking: for workspaces whose own tracking domain is active. */
    public const CONFIG_SET_OWN_TRACKING = 'sendline-events-own-tracking';

    public const TOPIC = 'sendline-events';

    protected $guarded = ['id'];

    protected $hidden = ['access_key_id', 'secret_access_key', 'webhook_token', 'webhook_token_hash'];

    protected function casts(): array
    {
        return [
            'access_key_id' => 'encrypted',
            'secret_access_key' => 'encrypted',
            'webhook_token' => 'encrypted',
            'production_access' => 'boolean',
            'sending_enabled' => 'boolean',
            'max_24h_send' => 'float',
            'max_send_rate' => 'float',
            'sent_last_24h' => 'float',
            'bounce_rate' => 'float',
            'complaint_rate' => 'float',
            'setup_steps' => 'array',
            'permissions' => 'array',
            'subscription_confirmed_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    public function webhookUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/api/v1/webhooks/ses/'.$this->webhook_token;
    }

    /** AKIA…WXYZ, never the full key. */
    public function maskedKey(): string
    {
        $k = $this->access_key_id;

        return substr($k, 0, 4).'…'.substr($k, -4);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
