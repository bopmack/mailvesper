<?php

namespace App\Services\Journeys;

use App\Exceptions\ApiException;
use App\Models\Contact;
use App\Models\Journey;
use App\Models\Workspace;
use App\Services\Platform\Brand;
use App\Services\Sending\Mailer;
use App\Services\Sending\Senders;

/**
 * An email to the workspace's team from a journey (Notify, Human approval, the agent's Notify tool), from a
 * verified sender of the workspace.
 */
class TeamMail
{
    public function __construct(private Mailer $mailer) {}

    /**
     * Returns why it wasn't sent, or null when it went.
     *
     * @param  list<string>  $to
     */
    public function send(Journey $j, Contact $contact, array $to, string $subject, string $text, string $extraHtml = '', ?string $plain = null): ?string
    {
        $v = Senders::verified($j->workspace_id);
        $from = $v['domains'] ? 'notifications@'.$v['domains'][0] : ($v['emails'][0] ?? null);
        if (! $from) {
            return 'No verified sender to send the notification from';
        }
        $ws = Workspace::findOrFail($j->workspace_id);
        try {
            $html = '<div style="font-family:-apple-system,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#18181B">'.nl2br(e($text)).$extraHtml.
                '<p style="margin-top:18px;font-size:12px;color:#71717A">From the journey “'.e($j->name).'” · contact '.e($contact->email).'</p></div>';
            $p = $this->mailer->validate($ws, ['from' => Brand::name()." <$from>", 'to' => $to, 'subject' => '['.$j->name.'] '.$subject, 'html' => $html, 'text' => $plain ?? $text, 'type' => 'transactional']);
            $this->mailer->submit($ws, [$p], 'journey');
        } catch (ApiException $e) {
            return 'Not sent: '.$e->getMessage();
        }

        return null;
    }
}
