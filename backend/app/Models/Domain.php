<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property string $region
 * @property string $status
 * @property list<string>|null $dkim_tokens
 * @property string|null $dkim_status
 * @property string|null $mail_from_domain
 * @property string|null $mail_from_status
 * @property array<string, string>|null $dns_check
 * @property string|null $dns_provider
 * @property string|null $route53_zone_id
 * @property string|null $cloudflare_zone_id
 * @property bool $imported
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $verified_at
 * @property Carbon|null $created_at
 */
class Domain extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'dkim_tokens' => 'array',
            'dns_check' => 'array',
            'imported' => 'boolean',
            'last_checked_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * The DNS records this domain needs, with a stable key per record.
     *
     * @return list<array{key: string, type: string, host: string, value: string, purpose: string, required: bool}>
     */
    public function records(): array
    {
        $out = [];
        foreach ($this->dkim_tokens ?? [] as $i => $token) {
            $out[] = ['key' => 'dkim'.($i + 1), 'type' => 'CNAME', 'host' => "$token._domainkey.{$this->name}", 'value' => "$token.dkim.amazonses.com", 'purpose' => 'DKIM signature', 'required' => true];
        }
        if ($this->mail_from_domain) {
            $out[] = ['key' => 'mx', 'type' => 'MX', 'host' => $this->mail_from_domain, 'value' => "10 feedback-smtp.{$this->region}.amazonses.com", 'purpose' => 'Bounces (custom MAIL FROM)', 'required' => true];
            $out[] = ['key' => 'spf', 'type' => 'TXT', 'host' => $this->mail_from_domain, 'value' => 'v=spf1 include:amazonses.com ~all', 'purpose' => 'SPF', 'required' => true];
        }
        $out[] = ['key' => 'dmarc', 'type' => 'TXT', 'host' => '_dmarc.'.$this->name, 'value' => 'v=DMARC1; p=none;', 'purpose' => 'DMARC (start with monitoring)', 'required' => false];

        return $out;
    }
}
