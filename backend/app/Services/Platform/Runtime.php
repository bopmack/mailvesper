<?php

namespace App\Services\Platform;

use Throwable;

/**
 * Puts the admin panel's settings into effect when the app starts: the brand name, the server for this
 * installation's own email, and the sign-in providers' keys. Anything not set in the panel keeps its .env value,
 * and nothing changes if the database can't be read (a fresh install, `config:cache`).
 */
class Runtime
{
    public static function apply(): void
    {
        try {
            $brand = Brand::name();
            config(['app.name' => $brand]);
            if (Settings::get('mail.custom') && Settings::get('mail.host')) {
                $enc = (string) (Settings::get('mail.encryption') ?: 'tls');
                config([
                    'mail.default' => 'smtp',
                    'mail.mailers.smtp.host' => Settings::get('mail.host'),
                    'mail.mailers.smtp.port' => Settings::get('mail.port') ?: ($enc === 'ssl' ? 465 : 587),
                    'mail.mailers.smtp.scheme' => $enc === 'ssl' ? 'smtps' : 'smtp', // smtp upgrades with STARTTLS when the server offers it
                    'mail.mailers.smtp.username' => Settings::get('mail.username'),
                    'mail.mailers.smtp.password' => Settings::get('mail.password'),
                ]);
            }
            // From name: the panel's, else the brand once the installation is rebranded, else MAIL_FROM_NAME.
            $fromName = Settings::get('mail.from_name') ?: ($brand !== 'MailVesper' ? $brand : (config('mail.from.name') ?: $brand));
            config(['mail.from.address' => Settings::get('mail.from_address') ?: config('mail.from.address'), 'mail.from.name' => $fromName]);
            foreach (['google' => ['google_id', 'google_secret'], 'microsoft' => ['microsoft_id', 'microsoft_secret'], 'github' => ['github_id', 'github_secret']] as $p => [$id, $secret]) {
                config(["services.$p.client_id" => Settings::get("oauth.$id"), "services.$p.client_secret" => Settings::get("oauth.$secret")]);
            }
            config(['services.microsoft.tenant' => Settings::get('oauth.microsoft_tenant') ?: 'common',
                'services.apple.client_id' => Settings::get('oauth.apple_id'), 'services.apple.team_id' => Settings::get('oauth.apple_team'),
                'services.apple.key_id' => Settings::get('oauth.apple_key_id'), 'services.apple.private_key' => Settings::get('oauth.apple_private_key')]);
        } catch (Throwable) {
            // No database yet: .env as it is.
        }
    }
}
