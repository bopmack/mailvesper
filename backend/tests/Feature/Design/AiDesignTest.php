<?php

use App\Models\EmailTemplate;
use App\Services\Platform\Settings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    [$this->ws, $this->people] = sesWorkspace($this, ['viewer']);
    $this->url = "/v1/workspaces/{$this->ws->id}/templates/ai-design";
});

function aiDesignReply(array|string $spec): array
{
    return ['model' => 'anthropic/claude-sonnet-5.5', 'choices' => [['message' => ['content' => is_string($spec) ? $spec : json_encode($spec)]]], 'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 600, 'cost' => 0.012]];
}

const AI_SPEC = ['name' => 'Black Friday', 'subject' => 'Black Friday: 30% off everything', 'preheader' => 'Three days only.',
    'sections' => [
        ['type' => 'header'],
        ['type' => 'hero', 'image' => 'sparkler', 'heading' => 'Black Friday is here', 'text' => 'Hi {{ first_name | there }}, **30% off** everything until [date].', 'button_label' => 'Shop the sale', 'button_url' => 'https://evil.example/phish'],
        ['type' => 'products', 'items' => [['name' => 'Scarf', 'price' => '[price]', 'image' => 'scarf', 'url' => 'javascript:alert(1)', 'button_label' => 'Buy'], ['name' => 'Shoes', 'price' => '[price]', 'image' => 'shoes', 'button_label' => 'Buy']]],
        ['type' => 'coupon', 'code' => 'BF30', 'note' => 'Use at checkout'],
        ['type' => 'button', 'label' => 'See everything', 'url' => 'http://not-https.example'],
    ]];

it('turns a brief into an editable, on-brand template whose links all go where the sender said', function () {
    Settings::set('ai.openrouter_key', 'sk-or-test-123');
    $this->getJson($this->url)->assertOk()->assertJson(['available' => true, 'tones' => ['friendly', 'professional', 'bold', 'minimal', 'playful']]);
    Http::fake(['openrouter.ai/*' => Http::response(aiDesignReply(AI_SPEC))]);

    $r = $this->postJson($this->url, ['brief' => 'Black Friday sale, 30% off everything for three days', 'link' => 'https://acme.com/sale', 'tone' => 'bold'])->assertCreated();
    $t = EmailTemplate::findOrFail($r->json('template.id'));
    expect($t->category)->toBe('AI')->and($t->name)->toBe('Black Friday')->and($t->subject)->toBe('Black Friday: 30% off everything')
        ->and($t->doc['settings']['preheader'])->toBe('Three days only.');
    $links = [];
    $doc = $t->doc;
    array_walk_recursive($doc, function ($v, $k) use (&$links) {
        if ($k === 'href' && $v !== '' && ! str_starts_with((string) $v, '{{')) {
            $links[] = $v;
        }
    });
    expect(array_unique($links))->toBe(['https://acme.com/sale'])
        ->and($t->html)->toContain('30% off')->toContain('[date]')->not->toContain('evil.example')->not->toContain('javascript:')
        ->and(collect($t->doc['rows'])->last()['cells'][0][0]['type'])->toBe('footer'); // the compliant footer is always there
    expect(DB::table('ai_usage')->where('feature', 'template_design')->value('workspace_id'))->toBe($this->ws->id);
    Http::assertSent(fn (Request $req) => $req['response_format'] === ['type' => 'json_object'] && str_contains($req['messages'][0]['content'], 'bold and energetic')
        && str_contains($req['messages'][0]['content'], 'https://acme.com/sale') && $req['messages'][1]['content'] === 'Black Friday sale, 30% off everything for three days');
    $this->getJson("/v1/workspaces/{$this->ws->id}/audit-logs")->assertJsonPath('events.0.meta.via', 'ai');
});

it('asks again once when the reply is unusable', function () {
    Settings::set('ai.openrouter_key', 'sk-or-test-123');
    Http::fake(['openrouter.ai/*' => Http::sequence()->push(aiDesignReply('Sure! Here is your email: <html>'))->push(aiDesignReply("```json\n".json_encode(AI_SPEC)."\n```"))]);
    $this->postJson($this->url, ['brief' => 'A short welcome email for new subscribers', 'link' => 'https://acme.com'])->assertCreated();
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $req) => count($req['messages']) === 4 && str_contains((string) collect($req['messages'])->last()['content'], 'corrected JSON'));

});

it('gives up with a clear message when both replies are unusable', function () {
    Settings::set('ai.openrouter_key', 'sk-or-test-123');
    Http::fake(['openrouter.ai/*' => Http::response(aiDesignReply(['name' => 'x', 'subject' => 'y', 'sections' => [['type' => 'marquee']]]))]);
    $this->postJson($this->url, ['brief' => 'A short welcome email for new subscribers', 'link' => 'https://acme.com'])
        ->assertStatus(502)->assertJsonPath('error.code', 'ai_design_failed');
    Http::assertSentCount(2);
    expect(EmailTemplate::count())->toBe(0);
});

it('works through OpenAI too, and limits designs per workspace per hour', function () {
    Settings::set('ai.provider', 'openai');
    Settings::set('ai.model', 'gpt-5.6-terra');
    Settings::set('ai.openai_key', 'sk-openai-1');
    Settings::set('ai.designs_per_hour', '2');
    Http::fake(['api.openai.com/*' => Http::response(aiDesignReply(AI_SPEC))]);
    foreach ([201, 201, 429] as $status) {
        $this->postJson($this->url, ['brief' => 'Monthly newsletter with three updates', 'link' => 'https://acme.com/blog'])->assertStatus($status);
    }
    Http::assertSent(fn (Request $req) => str_starts_with($req->url(), 'https://api.openai.com/v1/chat/completions') && $req['model'] === 'gpt-5.6-terra' && $req->hasHeader('Authorization', 'Bearer sk-openai-1'));
});

it('checks the brief and link, keeps viewers out, and says plainly when AI isn’t set up', function () {
    $this->getJson($this->url)->assertOk()->assertJsonPath('available', false);
    $this->postJson($this->url, ['brief' => 'Welcome email for new subscribers', 'link' => 'https://acme.com'])->assertStatus(503)->assertJsonPath('error.code', 'ai_not_configured');
    $this->postJson($this->url, ['brief' => 'hi', 'link' => 'acme.com', 'tone' => 'angry'])->assertStatus(422)
        ->assertJsonPath('error.fields.brief', 'Tell the AI a little more about the email (at least a sentence).')
        ->assertJsonPath('error.fields.link', 'Use a full https:// link, like https://yourshop.com/sale.')
        ->assertJsonStructure(['error' => ['fields' => ['tone']]]);
    Settings::set('ai.openrouter_key', 'sk-or-test-123');
    Http::fake();
    app('auth')->forgetGuards();
    $this->postJson('/v1/auth/logout');
    app('auth')->forgetGuards();
    $this->postJson('/v1/auth/login', ['email' => 'viewer@example.com', 'password' => STRONG_PASSWORD])->assertOk();
    $this->postJson($this->url, ['brief' => 'Welcome email for new subscribers', 'link' => 'https://acme.com'])->assertForbidden();
    Http::assertNothingSent();
});
