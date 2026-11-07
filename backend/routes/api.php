<?php

use App\Http\Controllers\Api\V1\Account\AccountController;
use App\Http\Controllers\Api\V1\Account\PasskeyController;
use App\Http\Controllers\Api\V1\Account\TwoFactorController;
use App\Http\Controllers\Api\V1\Activity\ActivityController;
use App\Http\Controllers\Api\V1\Activity\StatsController;
use App\Http\Controllers\Api\V1\Activity\UsageController;
use App\Http\Controllers\Api\V1\Activity\WebhookApiController;
use App\Http\Controllers\Api\V1\Activity\WebhookController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\Auth\CodeLoginController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LookupController;
use App\Http\Controllers\Api\V1\Auth\OAuthController;
use App\Http\Controllers\Api\V1\Auth\PasskeyLoginController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Auth\SignupController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Api\V1\Campaigns\CampaignController;
use App\Http\Controllers\Api\V1\Campaigns\SharedReportController;
use App\Http\Controllers\Api\V1\Connect\ConnectController;
use App\Http\Controllers\Api\V1\Contacts\AudienceController;
use App\Http\Controllers\Api\V1\Contacts\ContactApiController;
use App\Http\Controllers\Api\V1\Contacts\ContactController;
use App\Http\Controllers\Api\V1\Contacts\ImportController;
use App\Http\Controllers\Api\V1\Contacts\SegmentController;
use App\Http\Controllers\Api\V1\Copilot\CopilotController;
use App\Http\Controllers\Api\V1\Deliverability\DeliverabilityController;
use App\Http\Controllers\Api\V1\Design\AiDesignController;
use App\Http\Controllers\Api\V1\Design\BrandController;
use App\Http\Controllers\Api\V1\Design\MediaController;
use App\Http\Controllers\Api\V1\Design\TemplateController;
use App\Http\Controllers\Api\V1\Dns\DnsConnectionController;
use App\Http\Controllers\Api\V1\Forms\FormController;
use App\Http\Controllers\Api\V1\Forms\PublicFormController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Integrations\IntegrationController;
use App\Http\Controllers\Api\V1\Integrations\IntegrationWebhookController;
use App\Http\Controllers\Api\V1\Journeys\ApprovalController;
use App\Http\Controllers\Api\V1\Journeys\JourneyController;
use App\Http\Controllers\Api\V1\Journeys\JourneyTestController;
use App\Http\Controllers\Api\V1\Journeys\TrackController;
use App\Http\Controllers\Api\V1\LicenseController;
use App\Http\Controllers\Api\V1\Mailbox\MailboxController;
use App\Http\Controllers\Api\V1\Migration\MigrationController;
use App\Http\Controllers\Api\V1\PlatformController;
use App\Http\Controllers\Api\V1\Sending\ApiKeyController;
use App\Http\Controllers\Api\V1\Sending\EmailController;
use App\Http\Controllers\Api\V1\Sending\MessageController;
use App\Http\Controllers\Api\V1\Sending\SuppressionController;
use App\Http\Controllers\Api\V1\Sending\UnsubscribeController;
use App\Http\Controllers\Api\V1\Sending\WebViewController;
use App\Http\Controllers\Api\V1\Ses\DomainController;
use App\Http\Controllers\Api\V1\Ses\SesController;
use App\Http\Controllers\Api\V1\Ses\SesHandoffController;
use App\Http\Controllers\Api\V1\Ses\SesWebhookController;
use App\Http\Controllers\Api\V1\Smtp\DeliveryReportsController;
use App\Http\Controllers\Api\V1\Smtp\RelayWebhookController;
use App\Http\Controllers\Api\V1\Smtp\SmtpController;
use App\Http\Controllers\Api\V1\Smtp\TrackingController;
use App\Http\Controllers\Api\V1\Tracking\TrackingDomainController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use App\Http\Middleware\EnsureEmailVerified;
use App\Http\Middleware\EnsureWorkspaceMember;
use App\Http\Middleware\PublicCors;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('health', HealthController::class);
    // "Connect to MailVesper" from a website: what this installation is, and the one-time code exchange.
    Route::get('meta', [ConnectController::class, 'meta'])->middleware(PublicCors::class);
    Route::post('connect/token', [ConnectController::class, 'token'])->middleware('throttle:auth');
    Route::get('ses/setup/{token}', [SesHandoffController::class, 'script'])->middleware('throttle:auth')->where('token', '[A-Za-z0-9]{48}');
    Route::post('ses/handoff/{token}', [SesHandoffController::class, 'complete'])->middleware('throttle:auth')->where('token', '[A-Za-z0-9]{48}');
    // Public send API (API keys).
    Route::middleware(['api.key:send', 'throttle:api-key'])->group(function () {
        Route::post('emails', [EmailController::class, 'store']);
        Route::post('emails/batch', [EmailController::class, 'batch']);
        Route::get('emails/{id}', [EmailController::class, 'show']);
    });
    Route::middleware(['api.key:full', 'throttle:api-key'])->group(function () {
        Route::get('connect/status', [ConnectController::class, 'status']);
        Route::post('connect/disconnect', [ConnectController::class, 'disconnect']);
        Route::post('connect/preset', [ConnectController::class, 'preset']);
        Route::post('connect/audience-hook', [ConnectController::class, 'audienceHook']);
        Route::post('events', [TrackController::class, 'track']);
        Route::post('contacts', [ContactApiController::class, 'upsert']);
        Route::post('contacts/batch', [ContactApiController::class, 'batch']);
        Route::get('lists', [ContactApiController::class, 'lists']);
        Route::post('lists', [ContactApiController::class, 'storeList']);
        Route::post('events/batch', [TrackController::class, 'batch']);
        Route::post('products', [TrackController::class, 'product']);
        Route::get('webhooks', [WebhookApiController::class, 'index']);
        Route::post('webhooks', [WebhookApiController::class, 'store']);
        Route::delete('webhooks/{id}', [WebhookApiController::class, 'destroy']);
        Route::get('contacts/{email}', [ContactApiController::class, 'show']);
        Route::delete('contacts/{email}', [ContactApiController::class, 'destroy']);
    });

    // A journey's own webhook trigger URL (Zapier, n8n, any app). The token is the credential.
    Route::post('hooks/j/{token}', [TrackController::class, 'hook'])->middleware('throttle:hooks')->where('token', '[A-Za-z0-9]{32}');

    // Stripe and Shopify webhooks (signed by the provider).
    Route::middleware('throttle:integrations')->where(['token' => '[a-z0-9]{40}'])->group(function () {
        Route::post('integrations/stripe/{token}', [IntegrationWebhookController::class, 'stripe']);
        Route::post('integrations/shopify/{token}', [IntegrationWebhookController::class, 'shopify']);
    });

    // Signup forms (no login): the hosted page, the embed script and plain HTML forms on any site.
    Route::middleware([PublicCors::class, 'throttle:forms'])->where(['key' => '[a-z0-9]{20}'])->group(function () {
        Route::get('f/{key}', [PublicFormController::class, 'show']);
        Route::match(['post', 'options'], 'f/{key}', [PublicFormController::class, 'submit']);
    });
    Route::post('f/confirm/{token}', [PublicFormController::class, 'confirm'])->middleware('throttle:forms')->where('token', '[A-Za-z0-9]{48}');

    // Unsubscribe links in emails (no login). POST is the RFC 8058 one-click target.
    Route::middleware('throttle:unsubscribe')->where(['token' => '[A-Za-z0-9]{40}'])->group(function () {
        Route::get('unsubscribe/{token}', [UnsubscribeController::class, 'show']);
        Route::post('unsubscribe/{token}', [UnsubscribeController::class, 'unsubscribe']);
        Route::post('unsubscribe/{token}/resubscribe', [UnsubscribeController::class, 'resubscribe']);
        Route::post('unsubscribe/{token}/preferences', [UnsubscribeController::class, 'preferences']);
    });

    // Human approval links in a journey's team email (no login): the page shows the request, a button answers it.
    Route::middleware('throttle:unsubscribe')->where(['token' => '[A-Za-z0-9_-]{8,80}\.[a-f0-9]{32}'])->group(function () {
        Route::get('approve/{token}', [ApprovalController::class, 'show']);
        Route::post('approve/{token}', [ApprovalController::class, 'answer']);
    });

    Route::get('t/ping', [TrackingDomainController::class, 'ping'])->middleware('throttle:unsubscribe');

    // Opens and clicks for SMTP sending (SES tracks its own).
    Route::middleware('throttle:unsubscribe')->where(['id' => '[0-9a-z]{26}', 'sig' => '[0-9a-f]{24}'])->group(function () {
        Route::get('t/o/{id}/{sig}.gif', [TrackingController::class, 'open']);
        Route::get('t/c/{id}/{sig}', [TrackingController::class, 'click']);
    });
    Route::get('view/{id}/{sig}', WebViewController::class)->middleware('throttle:unsubscribe')->where(['id' => '[0-9a-z]{26}', 'sig' => '[0-9a-f]{20}']);
    Route::get('reports/{token}', SharedReportController::class)->middleware('throttle:unsubscribe')->where('token', '[A-Za-z0-9]{32}');
    Route::post('webhooks/ses/{token}', SesWebhookController::class)->middleware('throttle:sns')->where('token', '[A-Za-z0-9]{48}');
    Route::post('hooks/delivery/{token}', RelayWebhookController::class)->middleware('throttle:relay')->where('token', '[A-Za-z0-9]{48}');

    Route::prefix('auth')->group(function () {
        Route::middleware('throttle:auth')->group(function () {
            Route::get('providers', [OAuthController::class, 'providers']);
            Route::get('setup', [SessionController::class, 'setup']);
            Route::get('cron', [PlatformController::class, 'cronStatus']); // the installer's last step
            Route::post('lookup', LookupController::class);
            Route::post('login', LoginController::class);
            Route::post('code/verify', [CodeLoginController::class, 'verify']);
            Route::post('password/reset', [PasswordResetController::class, 'reset']);
            Route::post('two-factor', TwoFactorChallengeController::class);
            Route::post('passkey/options', [PasskeyLoginController::class, 'options']);
            Route::post('passkey', [PasskeyLoginController::class, 'verify']);
        });
        Route::middleware('throttle:auth-strict')->group(function () {
            Route::post('signup', SignupController::class);
            Route::post('code/send', [CodeLoginController::class, 'send']);
            Route::post('password/forgot', [PasswordResetController::class, 'forgot']);
        });
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('email/verify', [EmailVerificationController::class, 'verify'])->middleware('throttle:auth');
            Route::post('email/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:auth-strict');
            Route::post('logout', [SessionController::class, 'logout']);
        });
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [SessionController::class, 'me']);
        Route::get('license', [LicenseController::class, 'show']);
        Route::get('platform/settings', [PlatformController::class, 'settings']);
        Route::get('platform/cron', [PlatformController::class, 'cron']);
        Route::put('platform/settings', [PlatformController::class, 'saveSettings']);
        Route::post('platform/ai/test', [PlatformController::class, 'testAi'])->middleware('throttle:auth-strict');
        Route::post('platform/brand/{kind}', [PlatformController::class, 'uploadBrand'])->whereIn('kind', ['logo', 'logo_dark', 'icon'])->middleware('throttle:auth-strict');
        Route::delete('platform/brand/{kind}', [PlatformController::class, 'removeBrand'])->whereIn('kind', ['logo', 'logo_dark', 'icon']);
        Route::post('platform/mail/test', [PlatformController::class, 'testMail'])->middleware('throttle:auth-strict');
        Route::get('platform/admins', [PlatformController::class, 'admins']);
        Route::put('platform/admins', [PlatformController::class, 'saveAdmins']);
        Route::get('platform/system', [PlatformController::class, 'system']);
        Route::post('platform/errors/{id}/resolve', [PlatformController::class, 'resolveError'])->whereNumber('id');
        Route::post('platform/backups', [PlatformController::class, 'backup'])->middleware('throttle:auth-strict');
        Route::post('platform/backups/offsite-test', [PlatformController::class, 'testOffsite'])->middleware('throttle:auth-strict');
        Route::post('platform/alerts/test', [PlatformController::class, 'testAlert'])->middleware('throttle:auth-strict');
        Route::put('license', [LicenseController::class, 'update'])->middleware('throttle:auth-strict');
        Route::get('ses/catalog', [SesController::class, 'catalog']);
        Route::get('connect/check', [ConnectController::class, 'check']);
        Route::middleware(EnsureEmailVerified::class)->group(function () {
            Route::get('workspaces/slug-check', [WorkspaceController::class, 'slugCheck']);
            Route::post('workspaces', [WorkspaceController::class, 'store']);

            Route::prefix('workspaces/{workspace}')->middleware(EnsureWorkspaceMember::class)->group(function () {
                Route::post('switch', [WorkspaceController::class, 'switch']);
                Route::get('connect/readiness', [ConnectController::class, 'readiness']);
                Route::post('connect/approve', [ConnectController::class, 'approve'])->middleware('throttle:auth');
                Route::get('connections', [ConnectController::class, 'index']);
                Route::get('connections/{id}/people', [ConnectController::class, 'people'])->middleware('throttle:120,1');
                Route::delete('connections/{id}', [ConnectController::class, 'destroy']);
                Route::patch('/', [WorkspaceController::class, 'update']);
                Route::delete('/', [WorkspaceController::class, 'destroy'])->middleware('throttle:auth');
                Route::post('delete-code', [WorkspaceController::class, 'deleteCode'])->middleware('throttle:auth');
                Route::get('audit-logs', AuditLogController::class);

                Route::get('smtp', [SmtpController::class, 'show']);
                Route::get('senders', [SmtpController::class, 'senders']);
                Route::put('senders/default', [SmtpController::class, 'setDefault']);
                Route::middleware('throttle:smtp')->group(function () {
                    Route::put('smtp', [SmtpController::class, 'save']);
                    Route::post('smtp/test', [SmtpController::class, 'test']);
                    Route::post('smtp/senders', [SmtpController::class, 'addSender']);
                    Route::post('smtp/senders/{id}/check', [SmtpController::class, 'checkSender']);
                    Route::post('smtp/senders/{id}/confirm', [SmtpController::class, 'confirmSender']);
                    Route::post('smtp/senders/{id}/resend', [SmtpController::class, 'resendCode']);
                });
                Route::post('sending/method', [SmtpController::class, 'use']);
                Route::delete('smtp', [SmtpController::class, 'destroy']);
                Route::delete('smtp/senders/{id}', [SmtpController::class, 'removeSender']);

                Route::get('ses', [SesController::class, 'show']);
                Route::get('ses/handoff/{id}', [SesHandoffController::class, 'status']);
                Route::middleware('throttle:aws')->group(function () {
                    Route::post('ses/connect', [SesController::class, 'connect']);
                    Route::post('ses/handoff', [SesHandoffController::class, 'start']);
                    Route::post('ses/retry', [SesController::class, 'retry']);
                    Route::post('ses/sync', [SesController::class, 'sync']);
                    Route::post('ses/production-access', [SesController::class, 'productionAccess']);
                    Route::delete('ses', [SesController::class, 'disconnect']);
                    Route::post('domains', [DomainController::class, 'store']);
                    Route::post('domains/{domain}/check', [DomainController::class, 'check']);
                    Route::post('domains/{domain}/route53', [DomainController::class, 'route53']);
                    Route::post('domains/{domain}/cloudflare', [DomainController::class, 'cloudflare']);
                    Route::put('dns/cloudflare', [DnsConnectionController::class, 'save']);
                });
                Route::get('dns/cloudflare', [DnsConnectionController::class, 'show']);
                Route::get('tracking-domain', [TrackingDomainController::class, 'show']);
                Route::put('tracking-domain', [TrackingDomainController::class, 'save'])->middleware('throttle:aws');
                Route::post('tracking-domain/check', [TrackingDomainController::class, 'check'])->middleware('throttle:aws');
                Route::delete('tracking-domain', [TrackingDomainController::class, 'destroy']);
                Route::delete('dns/cloudflare', [DnsConnectionController::class, 'destroy']);
                Route::get('domains', [DomainController::class, 'index']);

                Route::get('api-keys', [ApiKeyController::class, 'index']);
                Route::post('api-keys', [ApiKeyController::class, 'store']);
                Route::delete('api-keys/{id}', [ApiKeyController::class, 'destroy']);

                Route::get('suppressions', [SuppressionController::class, 'index']);
                Route::get('suppressions/export', [SuppressionController::class, 'export']);
                Route::post('suppressions', [SuppressionController::class, 'store']);
                Route::delete('suppressions/{id}', [SuppressionController::class, 'destroy'])->whereNumber('id');

                Route::get('activity', [ActivityController::class, 'index']);
                Route::get('activity/export', [ActivityController::class, 'export']);
                Route::get('activity/{id}/report', [DeliverabilityController::class, 'report']);
                Route::get('deliverability/checks', [DeliverabilityController::class, 'index']);
                Route::post('deliverability/checks', [DeliverabilityController::class, 'start'])->middleware('throttle:aws');
                Route::get('deliverability/checks/{id}', [DeliverabilityController::class, 'show']);
                Route::get('activity/{id}', [ActivityController::class, 'show']);
                Route::get('activity/{id}/raw', [ActivityController::class, 'raw']);
                Route::get('stats', StatsController::class);
                Route::get('usage', UsageController::class);
                Route::get('messages', [MessageController::class, 'index']);
                Route::get('messages/{id}', [MessageController::class, 'show']);

                Route::get('contacts', [ContactController::class, 'index']);
                Route::get('contacts/summary', [ContactController::class, 'summary']);
                Route::get('contacts/export', [ContactController::class, 'export']);
                Route::post('contacts', [ContactController::class, 'store']);
                Route::get('contacts/{id}', [ContactController::class, 'show']);
                Route::patch('contacts/{id}', [ContactController::class, 'update']);
                Route::delete('contacts/{id}', [ContactController::class, 'destroy']);
                Route::get('contacts/{id}/export', [ContactController::class, 'exportOne']);

                Route::get('lists', [AudienceController::class, 'lists']);
                Route::post('lists', [AudienceController::class, 'storeList']);
                Route::patch('lists/{id}', [AudienceController::class, 'updateList']);
                Route::delete('lists/{id}', [AudienceController::class, 'destroyList']);
                Route::get('fields', [AudienceController::class, 'fields']);
                Route::post('fields', [AudienceController::class, 'storeField']);
                Route::delete('fields/{id}', [AudienceController::class, 'destroyField'])->whereNumber('id');
                Route::get('topics', [AudienceController::class, 'topics']);
                Route::post('topics', [AudienceController::class, 'storeTopic']);
                Route::patch('topics/{id}', [AudienceController::class, 'updateTopic']);
                Route::delete('topics/{id}', [AudienceController::class, 'destroyTopic']);

                Route::get('delivery-reports', [DeliveryReportsController::class, 'show']);
                Route::put('delivery-reports', [DeliveryReportsController::class, 'save']);
                Route::post('delivery-reports/rotate', [DeliveryReportsController::class, 'rotate']);
                Route::delete('delivery-reports', [DeliveryReportsController::class, 'destroy']);

                Route::get('mailbox', [MailboxController::class, 'show']);
                Route::put('mailbox', [MailboxController::class, 'save'])->middleware('throttle:smtp');
                Route::post('mailbox/check', [MailboxController::class, 'check'])->middleware('throttle:smtp');
                Route::delete('mailbox', [MailboxController::class, 'destroy']);

                Route::get('integrations', [IntegrationController::class, 'index']);
                Route::put('integrations/{provider}', [IntegrationController::class, 'save'])->whereIn('provider', ['stripe', 'shopify']);
                Route::delete('integrations/{provider}', [IntegrationController::class, 'destroy'])->whereIn('provider', ['stripe', 'shopify']);

                Route::get('forms', [FormController::class, 'index']);
                Route::post('forms', [FormController::class, 'store']);
                Route::get('forms/{id}', [FormController::class, 'show']);
                Route::patch('forms/{id}', [FormController::class, 'update']);
                Route::post('forms/{id}/publish', [FormController::class, 'publish']);
                Route::post('forms/{id}/discard', [FormController::class, 'discard']);
                Route::delete('forms/{id}', [FormController::class, 'destroy']);

                Route::get('segments', [SegmentController::class, 'index']);
                Route::get('segments/catalog', [SegmentController::class, 'catalog']);
                Route::post('segments/preview', [SegmentController::class, 'preview']);
                Route::post('segments', [SegmentController::class, 'store']);
                Route::patch('segments/{id}', [SegmentController::class, 'update']);
                Route::delete('segments/{id}', [SegmentController::class, 'destroy']);

                Route::get('imports', [ImportController::class, 'index']);
                Route::post('imports', [ImportController::class, 'store'])->middleware('throttle:aws');
                Route::get('imports/{id}', [ImportController::class, 'show']);
                Route::post('imports/{id}/start', [ImportController::class, 'start']);
                Route::get('imports/{id}/skipped', [ImportController::class, 'skipped']);

                Route::get('migrations', [MigrationController::class, 'index']);
                Route::post('migrations', [MigrationController::class, 'connect'])->middleware('throttle:aws');
                Route::get('migrations/{id}', [MigrationController::class, 'show']);
                Route::post('migrations/{id}/start', [MigrationController::class, 'start']);
                Route::post('migrations/{id}/cancel', [MigrationController::class, 'cancel']);
                Route::put('migrations/savings', [MigrationController::class, 'savings']);
                Route::get('copilot', [CopilotController::class, 'show']);
                Route::post('copilot/rescan', [CopilotController::class, 'rescan']);
                Route::post('copilot/fix', [CopilotController::class, 'fix'])->middleware('throttle:aws');
                Route::get('copilot/dmarc', [CopilotController::class, 'dmarc']);
                Route::post('copilot/dmarc', [CopilotController::class, 'dmarcUpload']);
                Route::get('journeys', [JourneyController::class, 'index']);
                Route::post('journeys', [JourneyController::class, 'store']);
                Route::get('journeys/{id}', [JourneyController::class, 'show']);
                Route::patch('journeys/{id}', [JourneyController::class, 'update']);
                Route::delete('journeys/{id}', [JourneyController::class, 'destroy']);
                Route::post('journeys/{id}/duplicate', [JourneyController::class, 'duplicate']);
                Route::get('journeys/{id}/secret', [JourneyController::class, 'secret']);
                Route::post('journeys/{id}/publish', [JourneyController::class, 'publish']);
                Route::post('journeys/{id}/{action}', [JourneyController::class, 'action'])->whereIn('action', ['pause', 'resume', 'archive']);
                Route::get('journeys/{id}/stats', [JourneyController::class, 'stats']);
                Route::get('journeys/{id}/runs', [JourneyController::class, 'runs']);
                Route::get('journeys/{id}/runs/{runId}', [JourneyController::class, 'run']);
                Route::post('journeys/{id}/runs/{runId}/exit', [JourneyController::class, 'exitRun']);
                Route::post('journeys/{id}/test', [JourneyController::class, 'test']);
                Route::get('journeys/{id}/test-data', [JourneyTestController::class, 'show']);
                Route::put('journeys/{id}/test-data', [JourneyTestController::class, 'pin']);
                Route::post('journeys/{id}/test/trigger', [JourneyTestController::class, 'trigger']);
                Route::post('journeys/{id}/test/listen', [JourneyTestController::class, 'listen']);
                Route::post('journeys/{id}/test/step', [JourneyTestController::class, 'step'])->middleware('throttle:aws'); // a webhook step calls out
                Route::get('journeys/{id}/emails/{node}', [JourneyController::class, 'email'])->where('node', '[A-Za-z0-9_-]{1,24}');
                Route::put('journeys/{id}/emails/{node}', [JourneyController::class, 'saveEmail'])->where('node', '[A-Za-z0-9_-]{1,24}');
                Route::post('journeys/{id}/emails/{node}/test', [JourneyController::class, 'testEmail'])->where('node', '[A-Za-z0-9_-]{1,24}')->middleware('throttle:aws');
                Route::get('campaigns', [CampaignController::class, 'index']);
                Route::get('campaigns/audiences', [CampaignController::class, 'audiences']);
                Route::post('campaigns', [CampaignController::class, 'store']);
                Route::get('campaigns/{id}', [CampaignController::class, 'show']);
                Route::patch('campaigns/{id}', [CampaignController::class, 'update']);
                Route::delete('campaigns/{id}', [CampaignController::class, 'destroy']);
                Route::post('campaigns/{id}/duplicate', [CampaignController::class, 'duplicate']);
                Route::post('campaigns/{id}/send', [CampaignController::class, 'send'])->middleware('throttle:aws');
                Route::post('campaigns/{id}/test', [CampaignController::class, 'test'])->middleware('throttle:aws');
                Route::post('campaigns/{id}/share', [CampaignController::class, 'share']);
                Route::post('campaigns/{id}/{action}', [CampaignController::class, 'action'])->whereIn('action', ['pause', 'resume', 'cancel', 'unschedule']);
                Route::get('campaigns/{id}/report', [CampaignController::class, 'report']);
                Route::get('campaigns/{id}/recipients', [CampaignController::class, 'recipients']);

                Route::get('templates', [TemplateController::class, 'index']);
                Route::post('templates', [TemplateController::class, 'store']);
                Route::post('templates/render', [TemplateController::class, 'render'])->middleware('throttle:api-key');
                Route::get('templates/ai-design', [AiDesignController::class, 'show']);
                Route::post('templates/ai-design', [AiDesignController::class, 'store'])->middleware('throttle:aws');
                Route::get('templates/{id}', [TemplateController::class, 'show']);
                Route::patch('templates/{id}', [TemplateController::class, 'update']);
                Route::post('templates/{id}/duplicate', [TemplateController::class, 'duplicate']);
                Route::delete('templates/{id}', [TemplateController::class, 'destroy']);
                Route::post('templates/{id}/test', [TemplateController::class, 'test'])->middleware('throttle:aws');
                Route::get('media', [MediaController::class, 'index']);
                Route::post('media', [MediaController::class, 'store'])->middleware('throttle:aws');
                Route::delete('media/{id}', [MediaController::class, 'destroy']);
                Route::get('brand', [BrandController::class, 'show']);
                Route::put('brand', [BrandController::class, 'update']);

                Route::get('webhooks', [WebhookController::class, 'index']);
                Route::post('webhooks', [WebhookController::class, 'store'])->middleware('throttle:aws');
                Route::patch('webhooks/{id}', [WebhookController::class, 'update'])->middleware('throttle:aws');
                Route::delete('webhooks/{id}', [WebhookController::class, 'destroy']);
                Route::get('webhooks/{id}/secret', [WebhookController::class, 'secret']);
                Route::post('webhooks/{id}/rotate', [WebhookController::class, 'rotate']);
                Route::post('webhooks/{id}/test', [WebhookController::class, 'test'])->middleware('throttle:aws');
                Route::get('webhooks/{id}/deliveries', [WebhookController::class, 'deliveries']);
                Route::get('webhook-deliveries/{deliveryId}', [WebhookController::class, 'delivery']);
                Route::post('webhook-deliveries/{deliveryId}/replay', [WebhookController::class, 'replay'])->middleware('throttle:aws');
                Route::post('emails/test', [MessageController::class, 'test'])->middleware('throttle:aws');
                Route::post('emails/quick', [MessageController::class, 'quick'])->middleware('throttle:aws');
                Route::get('domains/{domain}', [DomainController::class, 'show']);
                Route::delete('domains/{domain}', [DomainController::class, 'destroy']);
            });

            Route::patch('account', [AccountController::class, 'update']);
            Route::post('account/password', [AccountController::class, 'password'])->middleware('throttle:auth');

            Route::prefix('account/passkeys')->middleware('throttle:auth')->group(function () {
                Route::get('/', [PasskeyController::class, 'index']);
                Route::post('options', [PasskeyController::class, 'options']);
                Route::post('/', [PasskeyController::class, 'store']);
                Route::patch('{passkey}', [PasskeyController::class, 'update']);
                Route::delete('{passkey}', [PasskeyController::class, 'destroy']);
            });

            Route::prefix('account/two-factor')->middleware('throttle:auth')->group(function () {
                Route::post('setup', [TwoFactorController::class, 'setup']);
                Route::post('confirm', [TwoFactorController::class, 'confirm']);
                Route::post('recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes']);
                Route::post('disable', [TwoFactorController::class, 'disable']);
            });
        });
    });
});
