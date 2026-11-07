<?php

// MAILVESPER_* settings; the older SENDLINE_* names still work.
$env = fn (string $key, mixed $default = null): mixed => env('MAILVESPER_'.$key, env('SENDLINE_'.$key, $default));

return [
    // MailVesper Pro: a private package, installed as a folder next to the app. Without it (or without a valid
    // license key) the app runs as the free Community edition.
    // Without a cron job, scheduled tasks and the queue run after page visits (App\Support\WebCron). It stands down by
    // itself when a cron job runs; false turns it off.
    'web_cron' => (bool) $env('WEB_CRON', true),
    'pro_path' => $env('PRO_PATH') ?: base_path('pro'),
    'license_key' => $env('LICENSE_KEY'),
    // The installation's admins (user ids, comma-separated). Empty: the first account. Set with: php artisan platform:admin you@example.com
    'platform_admins' => $env('PLATFORM_ADMINS'),
    // The operator's own links on the login and sign-up pages (Platform → Settings → Your site overrides these).
    'site' => ['privacy_url' => $env('PRIVACY_URL'), 'terms_url' => $env('TERMS_URL'), 'support_email' => $env('SUPPORT_EMAIL')],
    // The operator's brand (Platform → Branding overrides all of it): name, company and address for email footers,
    // accent colour; logo, dark logo and icon are uploaded in the admin panel.
    'brand' => ['name' => $env('BRAND_NAME', 'MailVesper'), 'company' => $env('BRAND_COMPANY'), 'address' => $env('BRAND_ADDRESS'),
        'accent' => $env('BRAND_ACCENT'), 'logo' => null, 'logo_dark' => null, 'icon' => null],
    // Platform → System email: off means the MAIL_* settings above are used as they are.
    'mail_custom' => false,
    'mail_encryption' => 'tls',
    'mail_from_name' => null,
    // Where "Upgrade to Pro" links go.
    'pro_url' => $env('PRO_URL', 'mailto:akintomide852@gmail.com?subject=MailVesper%20Pro'),

    // Operations: slow-query threshold, daily backups (MySQL/MariaDB) and the restore-drill database (must not be the live one).
    'slow_query_ms' => (int) env('SLOW_QUERY_MS', 500),
    'backup_path' => env('BACKUP_PATH'),
    'backup_drill' => [
        'host' => env('BACKUP_DRILL_HOST'),
        'port' => env('BACKUP_DRILL_PORT'),
        'database' => env('BACKUP_DRILL_DB'),
        'username' => env('BACKUP_DRILL_USER'),
        'password' => env('BACKUP_DRILL_PASSWORD'),
    ],

    // AI defaults. The platform admin panel overrides these (platform_settings); env is only the starting point.
    // One OpenAI-compatible client: OpenRouter by default, OpenAI as the alternative.
    'ai' => [
        'enabled' => (bool) env('AI_ENABLED', true),
        'provider' => env('AI_PROVIDER', 'openrouter'), // openrouter | openai
        'model' => env('AI_MODEL', 'anthropic/claude-sonnet-5.5'),
        'openrouter_key' => env('OPENROUTER_API_KEY'),
        'openai_key' => env('OPENAI_API_KEY'),
        'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 1200),
        'chat_per_hour' => (int) env('AI_CHAT_PER_HOUR', 30), // per workspace
        'designs_per_hour' => (int) env('AI_DESIGNS_PER_HOUR', 20), // "Design with AI", per workspace
        'models' => env('AI_MODELS', ''), // extra models AI agents may choose (comma-separated); the default model is always allowed
        'journey_steps_per_day' => (int) env('AI_JOURNEY_STEPS_PER_DAY', 1000), // AI steps in journeys (and their tests), per workspace
    ],

    'ops' => ['alert_emails' => env('OPS_ALERT_EMAILS')],
    'smtp_allow_private_hosts' => (bool) env('SMTP_ALLOW_PRIVATE_HOSTS', false),
    'tracking_cname_target' => env('TRACKING_CNAME_TARGET'),
    'checks' => ['address' => env('CHECKS_ADDRESS'), 'imap_host' => env('CHECKS_IMAP_HOST'), 'imap_port' => (int) env('CHECKS_IMAP_PORT', 993),
        'imap_username' => env('CHECKS_IMAP_USERNAME'), 'imap_password' => env('CHECKS_IMAP_PASSWORD')],
    'offsite' => ['endpoint' => env('BACKUP_OFFSITE_ENDPOINT'), 'region' => env('BACKUP_OFFSITE_REGION'), 'bucket' => env('BACKUP_OFFSITE_BUCKET'),
        'prefix' => env('BACKUP_OFFSITE_PREFIX'), 'key' => env('BACKUP_OFFSITE_KEY'), 'secret' => env('BACKUP_OFFSITE_SECRET')],
    'retention' => ['body_days' => env('RETENTION_BODY_DAYS'), 'raw_event_days' => env('RETENTION_RAW_EVENT_DAYS')],

    // Load balancers or CDNs in front of this app whose X-Forwarded-* headers to believe (comma-separated IPs or
    // CIDR ranges). Empty: nobody's, so a client can't fake its IP past rate limits. LiteSpeed/Apache serving
    // directly needs nothing here.
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
];
