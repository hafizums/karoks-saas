<?php

use App\Enums\KaraokeProjectStatus;
use App\Models\KaraokeProjectShare;
use App\Support\Karaoke\Embed\KaraokeEmbedOriginParser;
use App\Support\Karaoke\KaraokeProjectShareService;
use DevDojo\Themes\Models\Theme;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
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

function enableEmbedForProject($project, $user, $origins = "https://example.com\n"): TestResponse
{
    return test()->actingAs($user)->patch(route('karaoke.projects.share.embed.update', $project), [
        'embedding_confirmation' => '1',
        'embed_allowed_origins' => $origins,
    ]);
}

function embedUrlForShare(KaraokeProjectShare $share, string $token): string
{
    return app(KaraokeProjectShareService::class)->buildEmbedUrl($share, $token);
}

function assertSessionHasNoEmbedSecrets(TestResponse $response): void
{
    $sessionPayload = json_encode($response->getSession()->all());

    $response->assertSessionMissing('embed_iframe');
    $response->assertSessionMissing('share_url');
    expect($sessionPayload)->not->toContain('/karaoke/embed/');
    expect($sessionPayload)->not->toContain('/karaoke/shared/');
    expect($sessionPayload)->not->toMatch('/"token"/');
}

it('defaults new shares to embedding disabled', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $shareData = $this->createShareForProject($project, $user);

    expect($shareData['share']->fresh()->embedding_enabled)->toBeFalse();
    expect($shareData['share']->fresh()->embed_allowed_origins)->toBeNull();
});

it('redirects guests from embed management routes', function () {
    $project = $this->createShareReadyProject($this->createShareUser());

    $this->patch(route('karaoke.projects.share.embed.update', $project))->assertRedirect(route('login'));
    $this->delete(route('karaoke.projects.share.embed.destroy', $project))->assertRedirect(route('login'));
});

it('forbids another user from managing embed settings', function () {
    $owner = $this->createShareUser();
    $other = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $this->createShareForProject($project, $owner);

    $this->actingAs($other)
        ->patch(route('karaoke.projects.share.embed.update', $project), [
            'embedding_confirmation' => '1',
            'embed_allowed_origins' => "https://example.com\n",
        ])
        ->assertForbidden();

    $this->actingAs($other)
        ->delete(route('karaoke.projects.share.embed.destroy', $project))
        ->assertForbidden();
});

it('allows the owner to enable embedding with confirmation and origins', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $shareData = $this->createShareForProject($project, $user);

    enableEmbedForProject($project, $user)
        ->assertRedirect(route('karaoke.projects.show', $project));

    $share = $shareData['share']->fresh();
    expect($share->embedding_enabled)->toBeTrue();
    expect($share->embed_allowed_origins)->toBe(['https://example.com']);
    expect($share->embedding_updated_at)->not->toBeNull();
});

it('requires explicit embedding confirmation before enabling embeds', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $this->createShareForProject($project, $user);

    $this->actingAs($user)
        ->from(route('karaoke.projects.show', $project))
        ->patch(route('karaoke.projects.share.embed.update', $project), [
            'embed_allowed_origins' => "https://example.com\n",
        ])
        ->assertRedirect(route('karaoke.projects.show', $project))
        ->assertSessionHasErrors('embedding_confirmation');
});

it('cannot enable embedding without an active public share', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);

    $this->actingAs($user)
        ->from(route('karaoke.projects.show', $project))
        ->patch(route('karaoke.projects.share.embed.update', $project), [
            'embedding_confirmation' => '1',
            'embed_allowed_origins' => "https://example.com\n",
        ])
        ->assertRedirect(route('karaoke.projects.show', $project))
        ->assertSessionHasErrors('embed');
});

it('cannot enable embedding after the public share is revoked', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $shareData = $this->createShareForProject($project, $user);

    $this->actingAs($user)->delete(route('karaoke.projects.share.destroy', $project));

    $this->actingAs($user)
        ->from(route('karaoke.projects.show', $project))
        ->patch(route('karaoke.projects.share.embed.update', $project), [
            'embedding_confirmation' => '1',
            'embed_allowed_origins' => "https://example.com\n",
        ])
        ->assertRedirect(route('karaoke.projects.show', $project))
        ->assertSessionHasErrors('embed');

    expect($shareData['share']->fresh()->embedding_enabled)->toBeFalse();
});

it('does not revoke the public share when embedding is disabled', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $shareData = $this->createShareForProject($project, $user);

    enableEmbedForProject($project, $user)->assertRedirect();

    $this->actingAs($user)
        ->delete(route('karaoke.projects.share.embed.destroy', $project))
        ->assertRedirect(route('karaoke.projects.show', $project));

    $share = $shareData['share']->fresh();
    expect($share->embedding_enabled)->toBeFalse();
    expect($share->isActive())->toBeTrue();

    $this->get(route('karaoke.shared.show', [
        'share' => $share->public_id,
        'token' => $shareData['token'],
    ]))->assertOk();
});

it('normalizes https origins by lowercasing scheme and host and trimming trailing slashes', function () {
    $parser = app(KaraokeEmbedOriginParser::class);

    expect($parser->parseOne('https://Example.com/'))->toBe('https://example.com');
});

it('removes the default https port from normalized origins', function () {
    $parser = app(KaraokeEmbedOriginParser::class);

    expect($parser->parseOne('https://example.com:443'))->toBe('https://example.com');
});

it('preserves non-default https ports in normalized origins', function () {
    $parser = app(KaraokeEmbedOriginParser::class);

    expect($parser->parseOne('https://example.com:8443'))->toBe('https://example.com:8443');
});

it('deduplicates normalized origins', function () {
    $parser = app(KaraokeEmbedOriginParser::class);

    expect($parser->parseMany([
        "https://example.com\nhttps://EXAMPLE.com/\nhttps://example.com:443",
    ]))->toBe(['https://example.com']);
});

it('rejects http origins in production', function () {
    $parser = app(KaraokeEmbedOriginParser::class);
    $previousEnv = app()->environment();
    $this->app['env'] = 'production';

    try {
        expect($parser->parseOne('http://127.0.0.1:9001'))->toBeNull();
    } finally {
        $this->app['env'] = $previousEnv;
    }
});

it('rejects wildcard origins', function () {
    $parser = app(KaraokeEmbedOriginParser::class);

    expect($parser->parseOne('https://*.example.com'))->toBeNull();
});

it('rejects origins with paths queries fragments or credentials', function () {
    $parser = app(KaraokeEmbedOriginParser::class);

    expect($parser->parseOne('https://example.com/path'))->toBeNull();
    expect($parser->parseOne('https://example.com?x=1'))->toBeNull();
    expect($parser->parseOne('https://example.com#frag'))->toBeNull();
    expect($parser->parseOne('https://user:pass@example.com'))->toBeNull();
});

it('rejects origins containing control characters', function () {
    $parser = app(KaraokeEmbedOriginParser::class);

    expect($parser->parseOne("https://ex\x07ample.com"))->toBeNull();
    expect($parser->parseOne("https://ex\tample.com"))->toBeNull();
});

it('rejects localhost and private ip origins in production', function () {
    $parser = app(KaraokeEmbedOriginParser::class);
    $previousEnv = app()->environment();
    $this->app['env'] = 'production';

    try {
        expect($parser->parseOne('https://localhost'))->toBeNull();
        expect($parser->parseOne('https://127.0.0.1'))->toBeNull();
        expect($parser->parseOne('https://192.168.1.10'))->toBeNull();
    } finally {
        $this->app['env'] = $previousEnv;
    }
});

it('allows local http origins in the testing environment', function () {
    $parser = app(KaraokeEmbedOriginParser::class);

    expect($parser->parseOne('http://127.0.0.1:9001'))->toBe('http://127.0.0.1:9001');
});

it('rejects more than ten allowed origins', function () {
    $parser = app(KaraokeEmbedOriginParser::class);
    $origins = collect(range(1, 11))
        ->map(fn (int $index): string => "https://site{$index}.example.com")
        ->join("\n");

    expect(fn () => $parser->parseMany([$origins]))
        ->toThrow(ValidationException::class);
});

it('stores only normalized origins in the database after enabling embedding', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $shareData = $this->createShareForProject($project, $user);

    enableEmbedForProject($project, $user, "https://Example.com/\nhttps://example.com:443\nhttps://other.com:8443\n");

    expect($shareData['share']->fresh()->embed_allowed_origins)->toBe([
        'https://example.com',
        'https://other.com:8443',
    ]);
});

it('returns 404 for disabled embed player routes', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $shareData = $this->createShareForProject($project, $user);

    $this->get(route('karaoke.embed.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))->assertNotFound();
});

it('renders enabled embed player routes for guests', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $shareData = $this->createShareForProject($project, $user);
    enableEmbedForProject($project, $user);

    $this->get(route('karaoke.embed.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))
        ->assertOk()
        ->assertSee('Karoks')
        ->assertSee('x-data="karoksPlayer', false);
});

it('returns 404 for invalid embed tokens', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $shareData = $this->createShareForProject($project, $user);
    enableEmbedForProject($project, $user);

    $this->get(route('karaoke.embed.show', [
        'share' => $shareData['share']->public_id,
        'token' => str_repeat('a', 43),
    ]))->assertNotFound();
});

it('returns 404 for revoked expired missing-audio and unready embed shares', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $shareData = $this->createShareForProject($project, $user);
    enableEmbedForProject($project, $user);

    KaraokeProjectShare::query()->whereKey($shareData['share']->id)->update(['revoked_at' => now()]);
    $this->get(route('karaoke.embed.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))->assertNotFound();

    KaraokeProjectShare::query()->whereKey($shareData['share']->id)->update([
        'revoked_at' => null,
        'expires_at' => now()->subMinute(),
    ]);
    $this->get(route('karaoke.embed.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))->assertNotFound();

    KaraokeProjectShare::query()->whereKey($shareData['share']->id)->update([
        'expires_at' => now()->addDay(),
    ]);
    Storage::disk('local')->delete($project->instrumental_path);
    $this->get(route('karaoke.embed.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))->assertNotFound();

    $project->forceFill(['status' => KaraokeProjectStatus::Uploaded])->save();
    Storage::disk('local')->put($project->instrumental_path, 'audio');
    $this->get(route('karaoke.embed.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))->assertNotFound();
});

it('keeps normal public share routes working when embedding is disabled', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $shareData = $this->createShareForProject($project, $user);

    $this->get(route('karaoke.shared.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))->assertOk();

    $this->get(route('karaoke.embed.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))->assertNotFound();
});

it('does not expose owner identity on the embed page', function () {
    $owner = $this->createShareUser(['name' => 'Owner Person', 'username' => 'ownerperson']);
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);
    enableEmbedForProject($project, $owner);

    $response = $this->get(route('karaoke.embed.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]));

    $response->assertOk();
    $response->assertDontSee('Owner Person');
    $response->assertDontSee('ownerperson');
    $response->assertDontSee('x-app.sidebar', false);
    $response->assertDontSee('Back to project');
});

it('escapes embed lyrics against script and html injection', function () {
    $owner = $this->createShareUser();
    $transcript = json_decode(
        (string) file_get_contents(database_path('fixtures/karoks-demo-transcript.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $transcript['lines'][0]['words'][0]['text'] = '<img src=x onerror=alert(1)>';
    $project = $this->createShareReadyProject($owner, ['transcript' => $transcript]);
    $shareData = $this->createShareForProject($project, $owner);
    enableEmbedForProject($project, $owner);

    $response = $this->get(route('karaoke.embed.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]));

    $response->assertOk();
    $response->assertDontSee('<img src=x onerror=alert(1)>', false);
    $response->assertSee('x-text="word.text"', false);
});

it('shows simulated result badge on embed pages for mock processing driver', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner, ['processing_driver' => 'mock']);
    $shareData = $this->createShareForProject($project, $owner);
    enableEmbedForProject($project, $owner);

    $this->get(route('karaoke.embed.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))
        ->assertOk()
        ->assertSee('Simulated result');
});

it('uses the public shared audio route in the embed player', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);
    enableEmbedForProject($project, $owner);

    $response = $this->get(route('karaoke.embed.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]));

    $response->assertOk();
    $response->assertSee('audioUrl', false);
    $response->assertSee($shareData['share']->public_id, false);
    $response->assertSee($shareData['token'], false);
    expect($response->getContent())->toMatch('#\/audio#');
});

it('invalidates old embed urls after share rotation and serves the new token', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $shareData = $this->createShareForProject($project, $user);
    enableEmbedForProject($project, $user);

    $this->get(route('karaoke.embed.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))->assertOk();

    $this->actingAs($user)
        ->post(route('karaoke.projects.share.rotate', $project), ['sharing_confirmation' => '1'])
        ->assertRedirect();

    $share = $shareData['share']->fresh();
    $newUrl = (string) app(KaraokeProjectShareService::class)->ownerShareUrl($share);
    $newToken = $this->extractTokenFromShareUrl($newUrl);

    $this->get(route('karaoke.embed.show', [
        'share' => $share->public_id,
        'token' => $shareData['token'],
    ]))->assertNotFound();

    $this->get(route('karaoke.embed.show', [
        'share' => $share->public_id,
        'token' => $newToken,
    ]))->assertOk();
});

it('preserves embedding settings when a share link is rotated', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $shareData = $this->createShareForProject($project, $user);
    enableEmbedForProject($project, $user, "https://example.com\nhttps://partner.test\n");

    $this->actingAs($user)
        ->post(route('karaoke.projects.share.rotate', $project), ['sharing_confirmation' => '1'])
        ->assertRedirect();

    $share = $shareData['share']->fresh();
    expect($share->embedding_enabled)->toBeTrue();
    expect($share->embed_allowed_origins)->toBe([
        'https://example.com',
        'https://partner.test',
    ]);
});

it('blocks embed routes after disabling embedding while public share and audio still work', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $shareData = $this->createShareForProject($project, $user);
    enableEmbedForProject($project, $user);

    $this->actingAs($user)
        ->delete(route('karaoke.projects.share.embed.destroy', $project))
        ->assertRedirect();

    $this->get(route('karaoke.embed.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))->assertNotFound();

    $this->get(route('karaoke.shared.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))->assertOk();

    $this->get(route('karaoke.shared.audio', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))->assertOk();
});

it('blocks embed and audio routes after share revocation', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $shareData = $this->createShareForProject($project, $user);
    enableEmbedForProject($project, $user);

    $this->actingAs($user)->delete(route('karaoke.projects.share.destroy', $project));

    $this->get(route('karaoke.embed.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))->assertNotFound();

    $this->get(route('karaoke.shared.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))->assertNotFound();

    $this->get(route('karaoke.shared.audio', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))->assertNotFound();
});

it('removes share and embed configuration when a project is deleted', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $shareData = $this->createShareForProject($project, $user);
    enableEmbedForProject($project, $user);
    $shareId = $shareData['share']->id;

    $this->actingAs($user)->delete(route('karaoke.projects.destroy', $project));

    expect(KaraokeProjectShare::query()->whereKey($shareId)->exists())->toBeFalse();
});

it('does not place embed urls tokens or iframe markup in session when enabling embedding', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $this->createShareForProject($project, $user);

    $response = enableEmbedForProject($project, $user);

    assertSessionHasNoEmbedSecrets($response);
});

it('does not place embed urls tokens or iframe markup in session when rotating a share with embedding enabled', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $this->createShareForProject($project, $user);
    enableEmbedForProject($project, $user);

    $response = $this->actingAs($user)
        ->post(route('karaoke.projects.share.rotate', $project), ['sharing_confirmation' => '1']);

    assertSessionHasNoEmbedSecrets($response);
});

it('rejects invalid origins through the embed management form', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $this->createShareForProject($project, $user);

    $this->actingAs($user)
        ->from(route('karaoke.projects.show', $project))
        ->patch(route('karaoke.projects.share.embed.update', $project), [
            'embedding_confirmation' => '1',
            'embed_allowed_origins' => "https://*.example.com\n",
        ])
        ->assertRedirect(route('karaoke.projects.show', $project))
        ->assertSessionHasErrors('embed_allowed_origins');
});

it('allows concurrent embed enable from two php processes', function () {
    config(['filesystems.disks.local.root' => storage_path('app/private')]);
    Storage::forgetDisk('local');

    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);
    $this->createShareForProject($project, $user);

    while (DB::transactionLevel() > 0) {
        DB::commit();
    }

    $project->refresh();
    expect($project->isReadyForPlayback())->toBeTrue();
    expect(Storage::disk('local')->exists($project->instrumental_path))->toBeTrue();

    $syncDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'karoks-embed-sync-'.Str::uuid();
    mkdir($syncDir);

    $phpBinary = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
    $workerScript = base_path('tests/Support/concurrent_share_embed_worker.php');
    $resultOne = $syncDir.DIRECTORY_SEPARATOR.'result-one.txt';
    $resultTwo = $syncDir.DIRECTORY_SEPARATOR.'result-two.txt';
    $origins = 'https://example.com';

    $processOne = new Process([
        $phpBinary,
        $workerScript,
        (string) $user->id,
        (string) $project->id,
        $origins,
        $syncDir,
        $resultOne,
        'worker-one',
    ], base_path());
    $processTwo = new Process([
        $phpBinary,
        $workerScript,
        (string) $user->id,
        (string) $project->id,
        $origins,
        $syncDir,
        $resultTwo,
        'worker-two',
    ], base_path());

    $processOne->start();
    $processTwo->start();

    $deadline = microtime(true) + 15;

    while (
        (! is_file($syncDir.DIRECTORY_SEPARATOR.'worker-one.ready') || ! is_file($syncDir.DIRECTORY_SEPARATOR.'worker-two.ready'))
        && microtime(true) < $deadline
    ) {
        usleep(5000);
    }

    expect(is_file($syncDir.DIRECTORY_SEPARATOR.'worker-one.ready'))->toBeTrue()
        ->and(is_file($syncDir.DIRECTORY_SEPARATOR.'worker-two.ready'))->toBeTrue();

    file_put_contents($syncDir.DIRECTORY_SEPARATOR.'go', '1');

    expect($processOne->wait())->toBe(0)
        ->and($processTwo->wait())->toBe(0);

    $outcomes = [
        trim((string) file_get_contents($resultOne)),
        trim((string) file_get_contents($resultTwo)),
    ];

    foreach ($outcomes as $outcome) {
        expect($outcome)->not->toStartWith('error:');
    }

    expect($outcomes)->toContain('enabled');

    $share = KaraokeProjectShare::query()->where('karaoke_project_id', $project->id)->firstOrFail();
    expect($share->embedding_enabled)->toBeTrue();
    expect($share->embed_allowed_origins)->toBe(['https://example.com']);

    foreach ([$resultOne, $resultTwo] as $resultPath) {
        $contents = (string) file_get_contents($resultPath);
        expect($contents)->not->toMatch('/^[A-Za-z0-9_-]{40,}$/');
        expect($contents)->not->toContain('/karaoke/embed/');
        expect($contents)->not->toContain('/karaoke/shared/');
    }

    KaraokeProjectShare::query()->where('karaoke_project_id', $project->id)->delete();
    Storage::disk('local')->deleteDirectory($project->storageDirectory());
    $project->delete();
    $user->forceDelete();

    @unlink($syncDir.DIRECTORY_SEPARATOR.'go');
    @unlink($syncDir.DIRECTORY_SEPARATOR.'worker-one.ready');
    @unlink($syncDir.DIRECTORY_SEPARATOR.'worker-two.ready');
    @unlink($resultOne);
    @unlink($resultTwo);
    @rmdir($syncDir);
});
