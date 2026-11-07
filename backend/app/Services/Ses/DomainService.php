<?php

namespace App\Services\Ses;

use App\Exceptions\ApiException;
use App\Models\DnsConnection;
use App\Models\Domain;
use App\Models\SesConnection;
use App\Services\Dns\Cloudflare;
use App\Services\Platform\Brand;
use Aws\Exception\AwsException;

/** Sending domains: create the SES identity, list the DNS records, check them live, write them to Route 53. */
class DomainService
{
    public function __construct(private AwsFactory $aws, private DnsLookup $dns, private Cloudflare $cloudflare) {}

    /** The workspace's Cloudflare token, if it connected one. */
    public static function cloudflareToken(string $workspaceId): ?string
    {
        return DnsConnection::where('workspace_id', $workspaceId)->where('provider', 'cloudflare')->first()?->token; // through the model: it's encrypted
    }

    /** Notes the domain's Cloudflare zone when its DNS isn't on Route 53 and the workspace connected Cloudflare. */
    public function detectCloudflare(Domain $domain): void
    {
        if ($domain->route53_zone_id || ! ($token = self::cloudflareToken($domain->workspace_id))) {
            return;
        }
        try {
            $zone = $this->cloudflare->zone($token, $domain->name);
        } catch (ApiException) {
            return; // the token can't see it; the page still shows the records to add by hand
        }
        $domain->forceFill(['cloudflare_zone_id' => $zone, 'dns_provider' => $zone ? 'cloudflare' : null])->save();
    }

    /** Adds the missing records through Cloudflare. Returns how many it wrote. */
    public function applyCloudflare(Domain $domain): int
    {
        $token = self::cloudflareToken($domain->workspace_id);
        if (! $token || ! $domain->cloudflare_zone_id) {
            throw new ApiException('cloudflare_not_found', 'This domain isn’t in a Cloudflare zone your token can edit. Add the records at your DNS provider instead.', 422);
        }

        return $this->cloudflare->apply($token, $domain->cloudflare_zone_id, array_map(fn ($r) => ['type' => $r['type'], 'host' => $r['host'], 'value' => $r['value'],
            'keep_existing' => $r['key'] === 'dmarc', 'replace_prefix' => $r['key'] === 'spf' ? 'v=spf1' : null], $domain->records()));
    }

    public static function normalise(string $name): string
    {
        return strtolower(rtrim(trim($name), '.'));
    }

    public static function valid(string $name): bool
    {
        return strlen($name) <= 253 && (bool) preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*\.[a-z]{2,63}$/', $name);
    }

    public function add(SesConnection $c, string $name): Domain
    {
        $ses = $this->aws->ses($c);
        try {
            $ses->createEmailIdentity([
                'EmailIdentity' => $name,
                'ConfigurationSetName' => SesConnection::CONFIG_SET,
                'DkimSigningAttributes' => ['NextSigningKeyLength' => 'RSA_2048_BIT'],
            ]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() !== 'AlreadyExistsException') {
                throw SesErrors::from($e, 'ses:CreateEmailIdentity');
            }
        }
        try {
            $ses->putEmailIdentityMailFromAttributes(['EmailIdentity' => $name, 'MailFromDomain' => 'bounce.'.$name, 'BehaviorOnMxFailure' => 'USE_DEFAULT_VALUE']);
            $mailFrom = 'bounce.'.$name;
        } catch (AwsException) {
            $mailFrom = null; // DKIM alone still lets them send; the page shows MAIL FROM as not set up
        }

        $domain = Domain::firstOrNew(['workspace_id' => $c->workspace_id, 'name' => $name]);
        $domain->fill(['region' => $c->region, 'imported' => false, 'mail_from_domain' => $mailFrom ?? $domain->mail_from_domain]);
        $domain->route53_zone_id = $this->findZone($c, $name);
        $domain->dns_provider = $domain->route53_zone_id ? 'route53' : null;
        $domain->save();
        $this->detectCloudflare($domain);

        return $this->check($c, $domain);
    }

    /** Pull every domain identity already in this SES account into MailVesper. */
    public function importAll(SesConnection $c): int
    {
        $ses = $this->aws->ses($c);
        $names = [];
        $token = null;
        do {
            $page = $ses->listEmailIdentities(array_filter(['PageSize' => 100, 'NextToken' => $token]));
            foreach ($page['EmailIdentities'] ?? [] as $id) {
                if (($id['IdentityType'] ?? '') === 'DOMAIN') {
                    $names[] = self::normalise((string) $id['IdentityName']);
                }
            }
            $token = $page['NextToken'] ?? null;
        } while ($token && count($names) < 500);

        foreach (array_slice($names, 0, 100) as $name) {
            $domain = Domain::firstOrNew(['workspace_id' => $c->workspace_id, 'name' => $name]);
            if (! $domain->exists) {
                $domain->fill(['region' => $c->region, 'imported' => true]);
                $domain->route53_zone_id = $this->findZone($c, $name);
                $domain->dns_provider = $domain->route53_zone_id ? 'route53' : null;
                $domain->save();
                $this->detectCloudflare($domain);
            }
            try {
                $this->check($c, $domain);
            } catch (ApiException) {
                // keep importing the rest; this one shows as not checked
            }
        }

        return count($names);
    }

    public function check(SesConnection $c, Domain $domain): Domain
    {
        try {
            $id = $this->aws->ses($c)->getEmailIdentity(['EmailIdentity' => $domain->name]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'NotFoundException') {
                $domain->forceFill(['status' => 'failed', 'dkim_status' => 'NOT_FOUND', 'last_checked_at' => now()])->save();

                return $domain;
            }
            throw SesErrors::from($e, 'ses:GetEmailIdentity');
        }

        $dkim = $id['DkimAttributes'] ?? [];
        $mail = $id['MailFromAttributes'] ?? [];
        $domain->dkim_tokens = array_values($dkim['Tokens'] ?? $domain->dkim_tokens ?? []);
        $domain->dkim_status = $dkim['Status'] ?? null;
        if (! empty($mail['MailFromDomain'])) {
            $domain->mail_from_domain = self::normalise((string) $mail['MailFromDomain']);
        }
        $domain->mail_from_status = $mail['MailFromDomainStatus'] ?? null;

        $live = [];
        foreach ($domain->records() as $r) {
            $live[$r['key']] = $this->dns->check($r);
        }
        $domain->dns_check = $live;

        $verified = (bool) ($id['VerifiedForSendingStatus'] ?? false);
        $domain->status = $verified ? 'verified' : (in_array($domain->dkim_status, ['FAILED'], true) ? 'failed' : 'pending');
        if ($verified && ! $domain->verified_at) {
            $domain->verified_at = now();
        }
        $domain->last_checked_at = now();
        $domain->save();

        return $domain;
    }

    /** Finds the public Route 53 hosted zone that serves $name, or null (no zone, or no permission). */
    public function findZone(SesConnection $c, string $name): ?string
    {
        $labels = explode('.', $name);
        try {
            $r53 = $this->aws->route53($c);
            for ($i = 0; $i < count($labels) - 1; $i++) {
                $candidate = implode('.', array_slice($labels, $i));
                $zones = $r53->listHostedZonesByName(['DNSName' => $candidate, 'MaxItems' => '1'])['HostedZones'] ?? [];
                $z = $zones[0] ?? null;
                if ($z && rtrim((string) $z['Name'], '.') === $candidate && empty($z['Config']['PrivateZone'])) {
                    return str_replace('/hostedzone/', '', (string) $z['Id']);
                }
            }
        } catch (AwsException) {
            return null;
        }

        return null;
    }

    /** Writes the missing records to Route 53. Never overwrites an existing DMARC policy. Returns how many it wrote. */
    public function applyRoute53(SesConnection $c, Domain $domain): int
    {
        if (! $domain->route53_zone_id) {
            throw new ApiException('route53_not_found', 'We couldn’t find this domain in your Route 53 hosted zones. Add the records at your DNS provider instead.', 422);
        }
        $changes = [];
        foreach ($domain->records() as $r) {
            if ($r['key'] === 'dmarc' && ($domain->dns_check['dmarc'] ?? '') === 'found') {
                continue;
            }
            $changes[] = ['Action' => 'UPSERT', 'ResourceRecordSet' => [
                'Name' => $r['host'], 'Type' => $r['type'], 'TTL' => 300,
                'ResourceRecords' => [['Value' => $r['type'] === 'TXT' ? '"'.$r['value'].'"' : $r['value']]],
            ]];
        }
        try {
            $this->aws->route53($c)->changeResourceRecordSets(['HostedZoneId' => $domain->route53_zone_id, 'ChangeBatch' => ['Comment' => Brand::name().': SES records for '.$domain->name, 'Changes' => $changes]]);
        } catch (AwsException $e) {
            throw SesErrors::from($e, 'route53:ChangeResourceRecordSets');
        }

        return count($changes);
    }

    /** One TXT record through Route 53 or Cloudflare (used by Copilot's one-click DMARC fix). */
    public function upsertTxt(SesConnection $c, Domain $domain, string $host, string $value): void
    {
        if ($domain->cloudflare_zone_id && ($token = self::cloudflareToken($domain->workspace_id))) {
            $this->cloudflare->apply($token, $domain->cloudflare_zone_id, [['type' => 'TXT', 'host' => $host, 'value' => $value, 'replace_prefix' => explode(';', $value)[0]]]);

            return;
        }
        if (! $domain->route53_zone_id) {
            throw new ApiException('route53_not_found', 'This domain isn’t in your Route 53 hosted zones. Publish the record at your DNS provider instead.', 422);
        }
        try {
            $this->aws->route53($c)->changeResourceRecordSets(['HostedZoneId' => $domain->route53_zone_id, 'ChangeBatch' => ['Comment' => Brand::name().' Copilot: '.$host, 'Changes' => [
                ['Action' => 'UPSERT', 'ResourceRecordSet' => ['Name' => $host, 'Type' => 'TXT', 'TTL' => 300, 'ResourceRecords' => [['Value' => '"'.$value.'"']]]],
            ]]]);
        } catch (AwsException $e) {
            throw SesErrors::from($e, 'route53:ChangeResourceRecordSets');
        }
    }
}
