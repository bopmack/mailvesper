<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Platform-wide settings, edited from the platform admin panel. Values live in platform_settings (secrets
 * encrypted); anything not set falls back to config/sendline.php (env). Read through this class only.
 */
class Settings
{
    /** key → [config fallback, secret?, type] */
    public const KEYS = [
        'ai.enabled' => ['sendline.ai.enabled', false, 'bool'],
        'ai.provider' => ['sendline.ai.provider', false, 'string'],
        'ai.model' => ['sendline.ai.model', false, 'string'],
        'ai.openrouter_key' => ['sendline.ai.openrouter_key', true, 'string'],
        'ai.openai_key' => ['sendline.ai.openai_key', true, 'string'],
        'ai.max_output_tokens' => ['sendline.ai.max_output_tokens', false, 'int'],
        'ai.chat_per_hour' => ['sendline.ai.chat_per_hour', false, 'int'],
        'ai.designs_per_hour' => ['sendline.ai.designs_per_hour', false, 'int'],
        'ai.journey_steps_per_day' => ['sendline.ai.journey_steps_per_day', false, 'int'],
        'ai.models' => ['sendline.ai.models', false, 'string'],
        'license.key' => ['sendline.license_key', true, 'string'],
        'platform.admins' => ['sendline.platform_admins', false, 'string'], // user ids, comma-separated; empty = the first account
        'ops.alert_emails' => ['sendline.ops.alert_emails', false, 'string'],
        'retention.body_days' => ['sendline.retention.body_days', false, 'int'],
        'retention.raw_event_days' => ['sendline.retention.raw_event_days', false, 'int'],
        'smtp.allow_private_hosts' => ['sendline.smtp_allow_private_hosts', false, 'bool'],
        'tracking.cname_target' => ['sendline.tracking_cname_target', false, 'string'],
        'checks.address' => ['sendline.checks.address', false, 'string'],
        'checks.imap_host' => ['sendline.checks.imap_host', false, 'string'],
        'checks.imap_port' => ['sendline.checks.imap_port', false, 'int'],
        'checks.imap_username' => ['sendline.checks.imap_username', false, 'string'],
        'checks.imap_password' => ['sendline.checks.imap_password', true, 'string'],
        'fbl.address' => ['sendline.fbl.address', false, 'string'],
        'fbl.imap_host' => ['sendline.fbl.imap_host', false, 'string'],
        'fbl.imap_port' => ['sendline.fbl.imap_port', false, 'int'],
        'fbl.imap_username' => ['sendline.fbl.imap_username', false, 'string'],
        'fbl.imap_password' => ['sendline.fbl.imap_password', true, 'string'],
        'backup.offsite_endpoint' => ['sendline.offsite.endpoint', false, 'string'],
        'backup.offsite_region' => ['sendline.offsite.region', false, 'string'],
        'backup.offsite_bucket' => ['sendline.offsite.bucket', false, 'string'],
        'backup.offsite_prefix' => ['sendline.offsite.prefix', false, 'string'],
        'backup.offsite_key' => ['sendline.offsite.key', false, 'string'],
        'backup.offsite_secret' => ['sendline.offsite.secret', true, 'string'],
        'site.privacy_url' => ['sendline.site.privacy_url', false, 'string'],
        'site.terms_url' => ['sendline.site.terms_url', false, 'string'],
        'site.support_email' => ['sendline.site.support_email', false, 'string'],
        // Branding (logo, dark logo and icon are set by upload, never typed).
        'brand.name' => ['sendline.brand.name', false, 'string'],
        'brand.company' => ['sendline.brand.company', false, 'string'],
        'brand.address' => ['sendline.brand.address', false, 'text'],
        'brand.accent' => ['sendline.brand.accent', false, 'string'],
        'brand.logo' => ['sendline.brand.logo', false, 'string'],
        'brand.logo_dark' => ['sendline.brand.logo_dark', false, 'string'],
        'brand.icon' => ['sendline.brand.icon', false, 'string'],
        // The server that sends this installation's own email (sign-in codes, invites, alerts). Off: .env (MAIL_*).
        'mail.custom' => ['sendline.mail_custom', false, 'bool'],
        'mail.host' => ['mail.mailers.smtp.host', false, 'string'],
        'mail.port' => ['mail.mailers.smtp.port', false, 'int'],
        'mail.encryption' => ['sendline.mail_encryption', false, 'string'],
        'mail.username' => ['mail.mailers.smtp.username', false, 'string'],
        'mail.password' => ['mail.mailers.smtp.password', true, 'string'],
        'mail.from_address' => ['mail.from.address', false, 'string'],
        'mail.from_name' => ['sendline.mail_from_name', false, 'string'], // empty: the brand name (or MAIL_FROM_NAME when not rebranded)
        // Sign in with Google, Microsoft, GitHub or Apple: the app's keys from each provider's developer console.
        'oauth.google_id' => ['services.google.client_id', false, 'string'],
        'oauth.google_secret' => ['services.google.client_secret', true, 'string'],
        'oauth.microsoft_id' => ['services.microsoft.client_id', false, 'string'],
        'oauth.microsoft_secret' => ['services.microsoft.client_secret', true, 'string'],
        'oauth.microsoft_tenant' => ['services.microsoft.tenant', false, 'string'],
        'oauth.github_id' => ['services.github.client_id', false, 'string'],
        'oauth.github_secret' => ['services.github.client_secret', true, 'string'],
        'oauth.apple_id' => ['services.apple.client_id', false, 'string'],
        'oauth.apple_team' => ['services.apple.team_id', false, 'string'],
        'oauth.apple_key_id' => ['services.apple.key_id', false, 'string'],
        'oauth.apple_private_key' => ['services.apple.private_key', true, 'text'],
    ];

    /** Settings chosen from a list: key → allowed values (the first is the default). */
    public const OPTIONS = [
        'mail.encryption' => ['tls', 'ssl', 'none'],
        'ai.provider' => ['openrouter', 'openai'],
        'signup.mode' => ['open', 'invite'], // Pro: who can create an account
    ];

    /** Labels for the admin panel: key → [group, label, help]. Pro adds its own with describe(). */
    public const META = [
        'ai.enabled' => ['AI', 'AI features on', 'Design with AI, Copilot, the agent and AI steps in journeys.'],
        'ai.provider' => ['AI', 'Provider', 'openrouter or openai.'],
        'ai.model' => ['AI', 'Model', 'For OpenRouter, e.g. anthropic/claude-sonnet-5.5.'],
        'ai.openrouter_key' => ['AI', 'OpenRouter API key', 'openrouter.ai → Keys.'],
        'ai.openai_key' => ['AI', 'OpenAI API key', 'Used when the provider is openai.'],
        'ai.max_output_tokens' => ['AI', 'Longest answer (tokens)', null],
        'ai.chat_per_hour' => ['AI', 'Copilot questions per workspace per hour', null],
        'ai.designs_per_hour' => ['AI', '“Design with AI” per workspace per hour', null],
        'ai.models' => ['AI', 'More models AI agents can choose', 'Comma-separated model ids, e.g. anthropic/claude-haiku-5.5, openai/gpt-5-mini. The default model above is always on the list. Empty: only the default.'],
        'ai.journey_steps_per_day' => ['AI', 'AI journey steps per workspace per day', 'Each AI step a contact passes through (and each test of one) counts once; an agent counts once however many tools it uses. Over the limit, the step fails and its “On error” setting applies. 0: AI steps are off.'],
        'ops.alert_emails' => ['Operations', 'Send alerts to', 'Comma-separated emails. Empty: the platform admins.'],
        'retention.body_days' => ['Data retention', 'Keep email bodies for (days)', 'After this, an email’s HTML and text are cleared; reports, activity and suppressions stay. Empty: keep.'],
        'retention.raw_event_days' => ['Data retention', 'Keep raw SES notifications for (days)', 'The parsed activity stays. Empty: keep.'],
        'checks.address' => ['Setup checks', 'Check mailbox address', 'Where “Check my setup” sends its test email. A mailbox on another domain than your customers’ (it only reads the test emails it sent). Empty: setup checks are off.'],
        'checks.imap_host' => ['Setup checks', 'IMAP server', 'The check mailbox’s IMAP server (SSL on 993).'],
        'checks.imap_port' => ['Setup checks', 'IMAP port', '993 (SSL) or 143 (STARTTLS).'],
        'checks.imap_username' => ['Setup checks', 'IMAP username', 'Usually the address itself.'],
        'checks.imap_password' => ['Setup checks', 'IMAP password', 'Stored encrypted.'],
        'fbl.address' => ['Complaint feedback loops', 'Complaint mailbox address', 'Customers who send over SMTP give this address to Yahoo’s Complaint Feedback Loop (and Microsoft JMRP, for their own IPs). Spam complaints that arrive here are matched to the email and the address is suppressed. Only reports should go here. Empty: off.'],
        'fbl.imap_host' => ['Complaint feedback loops', 'IMAP server', 'The complaint mailbox’s IMAP server (SSL on 993).'],
        'fbl.imap_port' => ['Complaint feedback loops', 'IMAP port', '993 (SSL) or 143 (STARTTLS).'],
        'fbl.imap_username' => ['Complaint feedback loops', 'IMAP username', 'Usually the address itself.'],
        'fbl.imap_password' => ['Complaint feedback loops', 'IMAP password', 'Stored encrypted.'],
        'tracking.cname_target' => ['Sending', 'Tracking domains point to', 'The host customers point their own link-tracking domain at (a CNAME). Empty: this installation’s own host. This web server must answer for those domains with a certificate (cPanel AutoSSL, certbot), unless they come through Cloudflare’s proxy.'],
        'mail.custom' => ['System email', 'Send this installation’s own email through the server below', 'Sign-in codes, invitations, password resets and alerts. Off: the server set in .env on the server (MAIL_*).'],
        'mail.host' => ['System email', 'SMTP server', 'e.g. smtp.sendgrid.net, email-smtp.eu-west-1.amazonaws.com, mail.yourdomain.com.'],
        'mail.port' => ['System email', 'Port', '587 (STARTTLS) or 465 (SSL).'],
        'mail.encryption' => ['System email', 'Encryption', 'tls for port 587, ssl for 465.'],
        'mail.username' => ['System email', 'Username', null],
        'mail.password' => ['System email', 'Password', 'Stored encrypted.'],
        'mail.from_address' => ['System email', 'From address', 'e.g. no-reply@yourdomain.com. The server must be allowed to send as it.'],
        'mail.from_name' => ['System email', 'From name', 'Empty: your brand name.'],
        'oauth.google_id' => ['Sign in with Google', 'Client ID', 'Google Cloud console → APIs & Services → Credentials → OAuth client (Web). Redirect URI: shown above.'],
        'oauth.google_secret' => ['Sign in with Google', 'Client secret', 'Stored encrypted.'],
        'oauth.microsoft_id' => ['Sign in with Microsoft', 'Application (client) ID', 'Microsoft Entra → App registrations.'],
        'oauth.microsoft_secret' => ['Sign in with Microsoft', 'Client secret', 'Stored encrypted.'],
        'oauth.microsoft_tenant' => ['Sign in with Microsoft', 'Tenant', 'common (any account), organizations, consumers, or your tenant ID.'],
        'oauth.github_id' => ['Sign in with GitHub', 'Client ID', 'GitHub → Settings → Developer settings → OAuth Apps.'],
        'oauth.github_secret' => ['Sign in with GitHub', 'Client secret', 'Stored encrypted.'],
        'oauth.apple_id' => ['Sign in with Apple', 'Services ID', 'Apple Developer → Identifiers → Services IDs (e.g. com.yourdomain.signin).'],
        'oauth.apple_team' => ['Sign in with Apple', 'Team ID', null],
        'oauth.apple_key_id' => ['Sign in with Apple', 'Key ID', null],
        'oauth.apple_private_key' => ['Sign in with Apple', 'Private key (.p8 contents)', 'Paste the whole key, including the BEGIN and END lines. Stored encrypted.'],
        'smtp.allow_private_hosts' => ['Sending', 'Allow SMTP servers on private addresses', 'Only for a self-hosted installation that relays through a server on its own network. Leave off when customers add their own SMTP servers: otherwise they could relay through this server.'],
        'backup.offsite_bucket' => ['Off-site backups', 'Bucket', 'Each daily backup is copied here. Add a lifecycle rule on the bucket to delete copies after, say, 30 days. Empty: off.'],
        'backup.offsite_prefix' => ['Off-site backups', 'Folder in the bucket', 'e.g. sendline (optional).'],
        'backup.offsite_endpoint' => ['Off-site backups', 'Endpoint', 'Empty for AWS S3. Others: https://s3.us-west-004.backblazeb2.com, https://<account>.r2.cloudflarestorage.com, https://s3.wasabisys.com.'],
        'backup.offsite_region' => ['Off-site backups', 'Region', 'e.g. eu-west-1 (R2: auto). Empty: us-east-1.'],
        'backup.offsite_key' => ['Off-site backups', 'Access key ID', 'A key that can only PutObject and GetObject in this bucket.'],
        'backup.offsite_secret' => ['Off-site backups', 'Secret access key', null],
        'brand.name' => ['Branding', 'Product name', 'Shown everywhere instead of “MailVesper”: the login pages, the app, browser tabs and the emails this installation sends. Empty: MailVesper.'],
        'brand.accent' => ['Branding', 'Accent colour', 'Buttons and highlights, as #RRGGBB. Empty: the built-in indigo.'],
        'brand.company' => ['Branding', 'Company name', 'In the footer of the emails this installation sends.'],
        'brand.address' => ['Branding', 'Postal address', 'In the footer of those emails (some countries require it in email).'],
        'site.privacy_url' => ['Your site', 'Privacy policy link', 'Shown on the login and sign-up pages, e.g. https://example.com/privacy. Empty: not shown.'],
        'site.terms_url' => ['Your site', 'Terms link', 'Shown on the login and sign-up pages. Empty: not shown.'],
        'site.support_email' => ['Your site', 'Support email', 'The “Help” link on the login and sign-up pages. Empty: not shown.'],
    ];

    /** @var array<string, array{0: string, 1: string, 2: string|null}> */
    private static array $meta = [];

    /** @param array<string, array{0: string, 1: string, 2: string|null}> $meta */
    public static function describe(array $meta): void
    {
        self::$meta = $meta + self::$meta;
    }

    /**
     * Settings the admin panel shows, grouped, secrets masked.
     *
     * @return array<string, list<array{key: string, label: string, help: string|null, type: string, secret: bool, value: mixed}>>
     */
    public static function catalog(): array
    {
        $out = [];
        $all = self::all();
        foreach (self::META + self::$meta as $key => [$group, $label, $help]) {
            if (! isset(self::keys()[$key])) {
                continue;
            }
            $def = self::keys()[$key];
            $out[$group][] = ['key' => $key, 'label' => $label, 'help' => $help, 'type' => $def[2], 'secret' => $def[1], 'value' => $all[$key] ?? null]
                + (isset(self::OPTIONS[$key]) ? ['options' => self::OPTIONS[$key]] : []);
        }

        return $out;
    }

    /** @var array<string, array{0: string, 1: bool, 2: string}> keys added by the Pro package */
    private static array $extra = [];

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /** @param array<string, array{0: string, 1: bool, 2: string}> $keys key → [config fallback, secret?, type] */
    public static function extend(array $keys): void
    {
        self::$extra = $keys + self::$extra;
    }

    /** @return array<string, array{0: string, 1: bool, 2: string}> */
    public static function keys(): array
    {
        return self::KEYS + self::$extra;
    }

    /** The app instance the cache belongs to (a fresh app — next test, next worker job boot — starts clean). */
    private static ?int $owner = null;

    public static function get(string $key): mixed
    {
        $def = self::keys()[$key] ?? throw new \InvalidArgumentException("Unknown setting $key");
        if (self::$owner !== spl_object_id(app())) {
            self::$cache = null;
            self::$owner = spl_object_id(app());
        }
        self::$cache ??= DB::table('platform_settings')->get()->keyBy('key')->map(fn ($r) => $r->value === null ? null : ($r->secret ? Crypt::decryptString($r->value) : $r->value))->all();
        $raw = array_key_exists($key, self::$cache) ? self::$cache[$key] : config($def[0]);

        return match ($def[2]) {
            'bool' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $raw,
            default => $raw === null || $raw === '' ? null : (string) $raw,
        };
    }

    public static function set(string $key, mixed $value, ?string $userId = null): void
    {
        $def = self::keys()[$key] ?? throw new \InvalidArgumentException("Unknown setting $key");
        $str = $value === null ? null : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        DB::table('platform_settings')->updateOrInsert(['key' => $key], [
            'value' => $str !== null && $def[1] ? Crypt::encryptString($str) : $str, 'secret' => $def[1], 'updated_by' => $userId, 'updated_at' => now(), 'created_at' => now(),
        ]);
        self::$cache = null;
    }

    /** @return array<string, mixed> every setting, secrets masked (for the admin panel and the CLI). */
    public static function all(): array
    {
        $out = [];
        foreach (self::keys() as $k => $def) {
            $v = self::get($k);
            $out[$k] = $def[1] ? ($v ? '••••'.substr((string) $v, -4) : null) : $v;
        }

        return $out;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
