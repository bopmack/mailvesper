<?php

use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    [$this->ws] = sesWorkspace($this, ['member']);
});

function png(int $w, int $h): UploadedFile
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 91, 91, 247));
    $path = tempnam(sys_get_temp_dir(), 'img').'.png';
    imagepng($img, $path);

    return new UploadedFile($path, 'banner.png', 'image/png', null, true);
}

it('stores images, scales wide ones down for email, and serves them from a public URL', function () {
    $r = $this->post("/v1/workspaces/{$this->ws->id}/media", ['file' => png(2400, 600)], ['Accept' => 'application/json'])->assertCreated();
    $m = Media::first();
    expect($r->json('media'))->toMatchArray(['width' => 1600, 'height' => 400, 'mime' => 'image/png'])
        ->and($m->url)->toBe("https://send.test/media/{$this->ws->id}/{$m->id}.png");
    Storage::disk('public')->assertExists($m->path);
    expect(getimagesizefromstring(Storage::disk('public')->get($m->path))[0])->toBe(1600);
    $this->getJson("/v1/workspaces/{$this->ws->id}/media")->assertJsonCount(1, 'media');
    $this->deleteJson("/v1/workspaces/{$this->ws->id}/media/{$m->id}")->assertOk();
    Storage::disk('public')->assertMissing($m->path);
});

it('refuses files that are not images', function () {
    $fake = UploadedFile::fake()->createWithContent('evil.png', '<?php system($_GET["c"]); ?>');
    $this->post("/v1/workspaces/{$this->ws->id}/media", ['file' => $fake], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('error.code', 'not_an_image');
    expect(Media::count())->toBe(0);
});

it('keeps a brand kit that only owners and admins change', function () {
    $url = "/v1/workspaces/{$this->ws->id}/brand";
    $this->putJson($url, ['colors' => ['button' => 'red']])->assertStatus(422)->assertJsonPath('error.fields', ['colors.button' => 'Use a hex colour like #5B5BF7.']);
    $this->putJson($url, ['logo_url' => 'http://insecure.example/logo.png'])->assertStatus(422);
    $this->putJson($url, ['logo_url' => 'https://send.test/media/x.png', 'colors' => ['button' => '#db2777', 'link' => '#db2777'], 'font' => 'Georgia, Times, serif',
        'company' => 'Acme Inc', 'address' => '12 Marina Road, Lagos', 'socials' => ['instagram' => 'https://instagram.com/acme']])->assertOk();
    $this->getJson($url)->assertJsonPath('brand.colors.button', '#DB2777')->assertJsonPath('brand.font', 'Georgia, Times, serif')->assertJsonCount(5, 'fonts');
    switchTo($this, 'member@example.com');
    $this->putJson($url, ['company' => 'x'])->assertForbidden();
});
