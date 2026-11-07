<?php

namespace App\Services\Ses;

use App\Exceptions\ApiException;
use App\Models\SesConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Platform\Brand;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * One-command connect. The customer pastes one line into AWS CloudShell (already signed in to their account).
 * The script creates an IAM user with MailVesper's policy, finds the region they already use SES in, and posts
 * the new key back here with a single-use token that expires in 30 minutes.
 */
class SesHandoff
{
    public const TTL = 1800;

    /** Seconds to wait between retries while a brand-new IAM key propagates. Tests set 0. */
    public static int $retryDelay = 3;

    public function __construct(private SesSetup $setup) {}

    /** @return array{id: string, token: string, expires_at: string} */
    public function start(Workspace $workspace, User $user): array
    {
        $token = Str::random(48);
        $id = (string) Str::ulid();
        $state = ['id' => $id, 'workspace_id' => $workspace->id, 'user_id' => $user->id, 'status' => 'waiting', 'message' => null, 'expires' => now()->addSeconds(self::TTL)->timestamp];
        Cache::put($this->key($token), $state, self::TTL);
        Cache::put("ses-handoff-id:$id", $this->hash($token), self::TTL);

        return ['id' => $id, 'token' => $token, 'expires_at' => now()->addSeconds(self::TTL)->toIso8601String()];
    }

    /** @return array{id: string, workspace_id: string, user_id: string, status: string, message: string|null, expires: int}|null */
    public function find(string $token): ?array
    {
        return strlen($token) === 48 ? Cache::get($this->key($token)) : null;
    }

    /** @return array{status: string, message: string|null}|null */
    public function status(Workspace $workspace, string $id): ?array
    {
        $hash = Cache::get("ses-handoff-id:$id");
        $state = $hash ? Cache::get("ses-handoff:$hash") : null;

        return $state && $state['workspace_id'] === $workspace->id ? ['status' => $state['status'], 'message' => $state['message']] : null;
    }

    /** Called by the script with the new key. Returns the connection; throws ApiException with a plain message. */
    public function complete(string $token, string $key, string $secret, string $region): SesConnection
    {
        $state = $this->find($token) ?? throw new ApiException('handoff_expired', 'This setup command has expired. Go back to '.Brand::name().' and copy a fresh one.', 410);
        if ($state['status'] === 'connected') {
            throw new ApiException('handoff_used', 'This setup command was already used. Go back to '.Brand::name().'.', 409);
        }
        $workspace = Workspace::find($state['workspace_id']);
        $user = User::find($state['user_id']);
        if (! $workspace || ! $user || $user->roleIn($workspace)?->canManageTeam() !== true) {
            throw new ApiException('handoff_expired', 'This setup command is no longer valid. Go back to '.Brand::name().' and copy a fresh one.', 410);
        }
        $this->save($token, $state, 'connecting', null);

        // A key created seconds ago can be rejected until it reaches every AWS endpoint.
        for ($attempt = 1; ; $attempt++) {
            try {
                $conn = $this->setup->connect($workspace, $user, $key, $secret, $region);
                break;
            } catch (ApiException $e) {
                if ($e->errorCode === 'aws_keys_invalid' && $attempt < 4) {
                    sleep(self::$retryDelay);

                    continue;
                }
                if ($e->errorCode === 'aws_keys_invalid') {
                    // The script just created this key, so a refusal means AWS hasn't activated it yet.
                    $e = new ApiException('aws_key_not_ready', 'AWS hasn’t activated the new key yet. Wait a minute, then run the same command again.', 422);
                }
                $this->save($token, $state, 'failed', $e->getMessage());
                throw $e;
            }
        }
        $this->save($token, $state, 'connected', null);

        return $conn;
    }

    /** The bash script CloudShell runs. Everything it does is printed as it happens. */
    public function script(string $token): string
    {
        $endpoint = rtrim((string) config('app.frontend_url'), '/').'/api/v1/ses/handoff/'.$token;
        $policy = (string) json_encode(SesCatalog::policy(), JSON_UNESCAPED_SLASHES);
        $brand = Brand::name(); // letters, digits, spaces and . , & ' ( ) ! + : _ - only, so it is safe in bash
        $regions = 'us-east-1 us-east-2 us-west-2 eu-west-1 eu-west-2 eu-central-1 eu-north-1 ap-south-1 ap-southeast-1 ap-southeast-2 ap-northeast-1 ca-central-1 sa-east-1 af-south-1';

        return <<<BASH
#!/usr/bin/env bash
# {$brand}: connect this AWS account to {$brand}.
# What it does: creates an IAM user "sendline-…" that can only use SES (plus SNS topics named sendline-*,
# CloudWatch reads and optional Route 53 DNS changes), creates an access key for it, and sends that key to
# {$brand} over HTTPS. You can delete the user in IAM at any time to cut {$brand} off.
set -uo pipefail
say() { printf '%s\\n' "\$*"; }
fail() { say ""; say "✗ \$*"; exit 1; }  # one blank line, then the reason
command -v aws >/dev/null 2>&1 || fail "Run this in AWS CloudShell (the >_ icon at the top of the AWS console)."

say "→ Checking your AWS account…"
ACCOUNT=\$(aws sts get-caller-identity --query Account --output text 2>/dev/null) || fail "AWS CLI isn't signed in. Open CloudShell from the AWS console and try again."
say "  Account \$ACCOUNT"

say "→ Looking for the region where you already use SES…"
BEST=""; BEST_SCORE=0
for r in $regions; do
  prod=\$(aws sesv2 get-account --region "\$r" --query ProductionAccessEnabled --output text 2>/dev/null) || continue
  ids=\$(aws sesv2 list-email-identities --region "\$r" --query 'length(EmailIdentities)' --output text 2>/dev/null || echo 0)
  case "\$ids" in ''|*[!0-9]*) ids=0;; esac
  score=\$ids; [ "\$prod" = "True" ] && score=\$((score + 1000))
  if [ "\$score" -gt "\$BEST_SCORE" ]; then BEST=\$r; BEST_SCORE=\$score; fi
done
REGION=\${BEST:-\${AWS_REGION:-us-east-1}}
if [ -n "\$BEST" ]; then say "  Using \$REGION (your SES is set up there)"; else say "  No SES setup found yet. Using \$REGION"; fi

USER_NAME="sendline-\$(date +%y%m%d%H%M%S)"
say "→ Creating IAM user \$USER_NAME with {$brand}'s permissions…"
aws iam create-user --user-name "\$USER_NAME" >/dev/null 2>&1 || fail "Your AWS user isn't allowed to create IAM users. Ask your AWS admin to run this command, or use 'Paste keys instead' in {$brand}."
aws iam put-user-policy --user-name "\$USER_NAME" --policy-name MailVesperPolicy --policy-document '$policy' >/dev/null || fail "Couldn't attach {$brand}'s policy to \$USER_NAME."
read -r AK SK < <(aws iam create-access-key --user-name "\$USER_NAME" --query 'AccessKey.[AccessKeyId,SecretAccessKey]' --output text) || fail "Couldn't create an access key for \$USER_NAME."
[ -n "\${SK:-}" ] || fail "Couldn't create an access key for \$USER_NAME."

say "→ Waiting a few seconds for the new key to work everywhere in AWS…"
sleep 10
say "→ Sending the key to {$brand} and setting up event tracking…"
BODY=\$(python3 -c 'import json,sys; print(json.dumps(dict(zip(["access_key_id","secret_access_key","region"], sys.argv[1:]))))' "\$AK" "\$SK" "\$REGION")
OUT=\$(curl -sS --max-time 120 -w '\\n%{http_code}' -X POST '$endpoint' -H 'Content-Type: application/json' -H 'Accept: text/plain' --data "\$BODY") || fail "Couldn't reach {$brand}. Check your connection and run the command again."
CODE=\$(printf '%s' "\$OUT" | tail -n1); MSG=\$(printf '%s' "\$OUT" | sed '\$d')
[ "\$CODE" = "200" ] && { say ""; say "\$MSG"; exit 0; }
fail "\$MSG"
BASH;
    }

    /** @param array<string, mixed> $state */
    private function save(string $token, array $state, string $status, ?string $message): void
    {
        $state['status'] = $status;
        $state['message'] = $message;
        Cache::put($this->key($token), $state, max(60, (int) $state['expires'] - now()->timestamp));
    }

    private function hash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    private function key(string $token): string
    {
        return 'ses-handoff:'.$this->hash($token);
    }
}
