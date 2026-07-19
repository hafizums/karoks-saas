<?php

use App\Enums\KaraokeProjectStatus;
use App\Models\KaraokeProjectShare;
use DevDojo\Themes\Models\Theme;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\KaraokeShareTestHelpers;
use Tests\Support\KaraokeTestTheme;

uses(DatabaseTransactions::class);
uses(KaraokeShareTestHelpers::class);

beforeEach(function () {
    if (! Theme::query()->where('folder', 'anchor')->exists()) {
        Theme::query()->create([
            'name' => 'Anchor Theme',
            'folder' => 'anchor',
            'active' => true,
            'version' => 1.0,
        ]);
    }

    KaraokeTestTheme::register();
    Storage::fake('local');
    Http::fake();
});

it('renders a valid guest link without authentication', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);

    $this->get(route('karaoke.shared.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))
        ->assertOk()
        ->assertSee('Karoks')
        ->assertSee('x-data="karoksPlayer', false);
});

it('shows title artist lyrics and theme on the public page', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner, [
        'title' => 'Public Title',
        'artist' => 'Public Artist',
    ]);
    $shareData = $this->createShareForProject($project, $owner);

    $response = $this->get(route('karaoke.shared.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]));

    $response->assertOk();
    $response->assertSee('Public Title');
    $response->assertSee('Public Artist');
    $response->assertSee('Sing', false);
    $response->assertSee('data-bg=', false);
});

it('does not expose owner identity or account navigation on the public page', function () {
    $owner = $this->createShareUser(['name' => 'Owner Person', 'username' => 'ownerperson']);
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);

    $response = $this->get(route('karaoke.shared.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]));

    $response->assertOk();
    $response->assertDontSee('Owner Person');
    $response->assertDontSee('ownerperson');
    $response->assertDontSee('x-app.sidebar', false);
    $response->assertDontSee('Open karaoke player');
    $response->assertDontSee('Back to project');
    $response->assertDontSee('Download source audio');
});

it('returns 404 for an invalid public id', function () {
    $this->get(route('karaoke.shared.show', [
        'share' => (string) Str::uuid(),
        'token' => 'invalid-token-value',
    ]))->assertNotFound();
});

it('returns 404 for an invalid token', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);

    $this->get(route('karaoke.shared.show', [
        'share' => $shareData['share']->public_id,
        'token' => str_repeat('a', 43),
    ]))->assertNotFound();
});

it('returns 404 for revoked expired missing-audio and unready shares', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);

    KaraokeProjectShare::query()->whereKey($shareData['share']->id)->update(['revoked_at' => now()]);
    $this->get(route('karaoke.shared.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))->assertNotFound();

    KaraokeProjectShare::query()->whereKey($shareData['share']->id)->update([
        'revoked_at' => null,
        'expires_at' => now()->subMinute(),
    ]);
    $this->get(route('karaoke.shared.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))->assertNotFound();

    KaraokeProjectShare::query()->whereKey($shareData['share']->id)->update([
        'expires_at' => now()->addDay(),
    ]);
    Storage::disk('local')->delete($project->instrumental_path);
    $this->get(route('karaoke.shared.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))->assertNotFound();

    $project->forceFill(['status' => KaraokeProjectStatus::Uploaded])->save();
    Storage::disk('local')->put($project->instrumental_path, 'audio');
    $this->get(route('karaoke.shared.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))->assertNotFound();
});

it('escapes public lyrics against script and html injection', function () {
    $owner = $this->createShareUser();
    $transcript = json_decode(
        (string) file_get_contents(database_path('fixtures/karoks-demo-transcript.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $transcript['lines'][0]['words'][0]['text'] = '<img src=x onerror=alert(1)>';
    $project = $this->createShareReadyProject($owner, ['transcript' => $transcript]);
    $shareData = $this->createShareForProject($project, $owner);

    $response = $this->get(route('karaoke.shared.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]));

    $response->assertOk();
    $response->assertDontSee('<img src=x onerror=alert(1)>', false);
    $response->assertSee('x-text="word.text"', false);
});

it('shows simulated result badge for mock processing driver', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner, ['processing_driver' => 'mock']);
    $shareData = $this->createShareForProject($project, $owner);

    $this->get(route('karaoke.shared.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))
        ->assertOk()
        ->assertSee('Simulated result');
});

it('does not show simulated result badge for real processing driver', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner, ['processing_driver' => 'real']);
    $shareData = $this->createShareForProject($project, $owner);

    $this->get(route('karaoke.shared.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))
        ->assertOk()
        ->assertDontSee('Simulated result');
});

it('includes privacy headers on the public player response', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);

    $response = $this->get(route('karaoke.shared.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]));

    $response->assertOk();
    $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    $response->assertHeader('Referrer-Policy', 'no-referrer');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    expect(str_contains($response->headers->get('Cache-Control'), 'no-store'))->toBeTrue();
    $response->assertSee('noindex, nofollow, noarchive', false);
});

it('streams instrumental audio for a valid guest link', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);
    $expected = Storage::disk('local')->get($project->instrumental_path);

    $response = $this->get(route('karaoke.shared.audio', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'audio/wav');
    expect($response->streamedContent())->toBe($expected);
});

it('supports head requests for public audio', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);
    $size = strlen(Storage::disk('local')->get($project->instrumental_path));

    $response = $this->call('HEAD', route('karaoke.shared.audio', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]));

    $response->assertOk();
    $response->assertHeader('Content-Length', (string) $size);
    expect($response->getContent())->toBe('');
});

it('returns 206 for valid byte range requests on public audio', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);
    $full = Storage::disk('local')->get($project->instrumental_path);

    $response = $this->withHeader('Range', 'bytes=0-7')->get(route('karaoke.shared.audio', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]));

    $response->assertStatus(206);
    $response->assertHeader('Content-Range', 'bytes 0-7/'.strlen($full));
    expect($response->streamedContent())->toBe(substr($full, 0, 8));
});

it('returns 416 for invalid public audio ranges', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);
    $size = strlen(Storage::disk('local')->get($project->instrumental_path));

    $this->withHeader('Range', 'bytes='.$size.'-'.($size + 100))
        ->get(route('karaoke.shared.audio', [
            'share' => $shareData['share']->public_id,
            'token' => $shareData['token'],
        ]))
        ->assertStatus(416)
        ->assertHeader('Content-Range', 'bytes */'.$size);
});

it('blocks revoked and expired tokens on public audio', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);

    KaraokeProjectShare::query()->whereKey($shareData['share']->id)->update(['revoked_at' => now()]);
    $this->get(route('karaoke.shared.audio', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))->assertNotFound();

    KaraokeProjectShare::query()->whereKey($shareData['share']->id)->update([
        'revoked_at' => null,
        'expires_at' => now()->subMinute(),
    ]);
    $this->get(route('karaoke.shared.audio', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))->assertNotFound();
});

it('never serves source audio on the public route', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);

    Storage::disk('local')->put($project->instrumental_path, 'instrumental-bytes');
    Storage::disk('local')->put($project->source_path, 'source-bytes');

    $response = $this->get(route('karaoke.shared.audio', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]));

    expect($response->streamedContent())->toBe('instrumental-bytes');
    expect($response->streamedContent())->not->toBe('source-bytes');
});

it('does not disclose the original source filename in public audio headers', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner, ['original_filename' => 'secret-source.wav']);
    $shareData = $this->createShareForProject($project, $owner);

    $response = $this->get(route('karaoke.shared.audio', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]));

    $response->assertHeader('Content-Disposition', 'inline');
    expect($response->headers->get('Content-Disposition'))->not->toContain('secret-source.wav');
});

it('sets private no-store cache headers on public audio', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);

    $response = $this->get(route('karaoke.shared.audio', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]));

    expect(str_contains($response->headers->get('Cache-Control'), 'private'))->toBeTrue();
    expect(str_contains($response->headers->get('Cache-Control'), 'no-store'))->toBeTrue();
    expect(str_contains($response->headers->get('Pragma') ?? '', 'no-cache'))->toBeTrue();
});

it('does not leak tokens in logs or public output', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);

    $response = $this->get(route('karaoke.shared.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]));

    $response->assertOk();
    $response->assertDontSee('id="karoks-share-url"', false);
    $response->assertDontSee($project->source_path, false);
    $response->assertDontSee($project->public_id, false);

    $shareRecord = KaraokeProjectShare::query()->firstOrFail();
    expect($shareRecord->token_hash)->not->toBe($shareData['token']);
    expect($shareRecord->token_ciphertext)->not->toContain($shareData['token']);
    expect(substr_count($response->getContent(), $shareData['token']))->toBe(1);
});

it('does not make live http requests during public playback', function () {
    Http::fake();

    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);

    $this->get(route('karaoke.shared.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]));

    $this->get(route('karaoke.shared.audio', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]));

    Http::assertNothingSent();
});

it('does not expose provider checkpoints or internal details on the public page', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner, [
        'wavespeed_prediction_id' => 'pred_secret_123',
        'provider_transcript_checkpoint' => ['stage' => 'secret'],
    ]);
    $shareData = $this->createShareForProject($project, $owner);

    $response = $this->get(route('karaoke.shared.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]));

    $response->assertOk();
    $response->assertDontSee('pred_secret_123');
    $response->assertDontSee('provider_transcript_checkpoint', false);
    $response->assertDontSee('wavespeed', false);
});

it('does not load third-party assets on the shared page', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);

    $response = $this->get(route('karaoke.shared.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]));

    $response->assertOk();
    $response->assertDontSee('fonts.googleapis.com', false);
    $response->assertDontSee('googletagmanager.com', false);
    $response->assertDontSee('cdn.tailwindcss.com', false);
});

it('does not expose whether the owner is authenticated on the public page', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);

    $this->actingAs($owner)
        ->get(route('karaoke.shared.show', [
            'share' => $shareData['share']->public_id,
            'token' => $shareData['token'],
        ]))
        ->assertOk()
        ->assertDontSee('x-app.sidebar', false)
        ->assertDontSee('Logout', false);
});
