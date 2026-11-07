<?php

namespace App\Services\Ses;

/** Static facts: the IAM policy customers attach, SES regions, the event types we subscribe to. */
class SesCatalog
{
    public const EVENT_TYPES = ['SEND', 'REJECT', 'BOUNCE', 'COMPLAINT', 'DELIVERY', 'OPEN', 'CLICK', 'RENDERING_FAILURE', 'DELIVERY_DELAY', 'SUBSCRIPTION'];

    public const REGIONS = [
        'us-east-1' => 'US East (N. Virginia)', 'us-east-2' => 'US East (Ohio)', 'us-west-1' => 'US West (N. California)', 'us-west-2' => 'US West (Oregon)',
        'ca-central-1' => 'Canada (Central)', 'sa-east-1' => 'South America (São Paulo)',
        'eu-west-1' => 'Europe (Ireland)', 'eu-west-2' => 'Europe (London)', 'eu-west-3' => 'Europe (Paris)', 'eu-central-1' => 'Europe (Frankfurt)',
        'eu-central-2' => 'Europe (Zurich)', 'eu-north-1' => 'Europe (Stockholm)', 'eu-south-1' => 'Europe (Milan)',
        'af-south-1' => 'Africa (Cape Town)', 'me-south-1' => 'Middle East (Bahrain)', 'me-central-1' => 'Middle East (UAE)', 'il-central-1' => 'Israel (Tel Aviv)',
        'ap-south-1' => 'Asia Pacific (Mumbai)', 'ap-south-2' => 'Asia Pacific (Hyderabad)', 'ap-northeast-1' => 'Asia Pacific (Tokyo)', 'ap-northeast-2' => 'Asia Pacific (Seoul)',
        'ap-northeast-3' => 'Asia Pacific (Osaka)', 'ap-southeast-1' => 'Asia Pacific (Singapore)', 'ap-southeast-2' => 'Asia Pacific (Sydney)', 'ap-southeast-3' => 'Asia Pacific (Jakarta)',
    ];

    /** Least privilege: send, read/write SES settings, own sendline-* SNS topics, read reputation metrics, optional Route 53 DNS. */
    /** @return array<string, mixed> */
    public static function policy(): array
    {
        return [
            'Version' => '2012-10-17',
            'Statement' => [
                ['Sid' => 'MailVesperSes', 'Effect' => 'Allow', 'Resource' => '*', 'Action' => [
                    'ses:SendEmail', 'ses:SendRawEmail', 'ses:GetAccount', 'ses:PutAccountDetails',
                    'ses:ListEmailIdentities', 'ses:GetEmailIdentity', 'ses:CreateEmailIdentity', 'ses:DeleteEmailIdentity',
                    'ses:PutEmailIdentityMailFromAttributes', 'ses:PutEmailIdentityConfigurationSetAttributes',
                    'ses:CreateConfigurationSet', 'ses:GetConfigurationSet', 'ses:GetConfigurationSetEventDestinations',
                    'ses:CreateConfigurationSetEventDestination', 'ses:UpdateConfigurationSetEventDestination',
                    'ses:ListSuppressedDestinations', 'ses:GetSuppressedDestination', 'ses:PutSuppressedDestination', 'ses:DeleteSuppressedDestination',
                ]],
                ['Sid' => 'MailVesperEvents', 'Effect' => 'Allow', 'Resource' => 'arn:aws:sns:*:*:sendline-*', 'Action' => [
                    'sns:CreateTopic', 'sns:GetTopicAttributes', 'sns:SetTopicAttributes', 'sns:Subscribe', 'sns:ListSubscriptionsByTopic',
                ]],
                ['Sid' => 'MailVesperReputation', 'Effect' => 'Allow', 'Resource' => '*', 'Action' => ['cloudwatch:GetMetricData']],
                ['Sid' => 'MailVesperDnsOptional', 'Effect' => 'Allow', 'Resource' => '*', 'Action' => [
                    'route53:ListHostedZonesByName', 'route53:ChangeResourceRecordSets', 'route53:GetChange',
                ]],
            ],
        ];
    }
}
