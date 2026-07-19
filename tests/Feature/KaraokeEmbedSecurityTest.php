<?php

use App\Models\KaraokeProject;
use App\Models\User;
use DevDojo\Themes\Models\Theme;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
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

/**
 * @return array<string, string>
 */
function embedFormPayload(string $origins = "https://example.com\nhttps://portal.example.org:8443"): array
{
    return [
        'embedding_confirmation' => '1',
        'embed_allowed_origins' => $origins,
    ];
}

function enableProjectEmbedding(
    mixed $test,
    KaraokeProject $project,
    User $user,
    ?string $origins = null,
): TestResponse {
    $payload = embedFormPayload();

    if ($origins !== null) {
        $payload['embed_allowed_origins'] = $origins;
    }

    return $test->actingAs($user)
        ->patch(route('karaoke.projects.share.embed.update', $project), $payload);
}

it('sets deny framing headers on the normal public share player', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);

    $response = $this->get(route('karaoke.shared.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]));

    $response->assertOk();
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
});

it('does not set x-frame-options deny on the embed player response', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);

    enableProjectEmbedding($this, $project, $owner);

    $response = $this->get(route('karaoke.embed.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]));

    $response->assertOk();
    expect($response->headers->get('X-Frame-Options'))->not->toBe('DENY');
});

it('sets embed csp frame-ancestors to the normalized allowed origins', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);

    enableProjectEmbedding($this, $project, $owner);

    $response = $this->get(route('karaoke.embed.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]));

    $response->assertOk();
    $response->assertHeader(
        'Content-Security-Policy',
        'frame-ancestors https://example.com https://portal.example.org:8443',
    );
});

it('rejects wildcard origins and never emits asterisks in embed csp', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);

    $this->actingAs($owner)
        ->from(route('karaoke.projects.show', $project))
        ->patch(route('karaoke.projects.share.embed.update', $project), embedFormPayload('https://*.example.com'))
        ->assertRedirect(route('karaoke.projects.show', $project))
        ->assertSessionHasErrors('embed_allowed_origins');

    enableProjectEmbedding($this, $project, $owner);

    $response = $this->get(route('karaoke.embed.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]));

    $response->assertOk();
    expect($response->headers->get('Content-Security-Policy'))->not->toContain('*');
});

it('includes privacy headers on the embed player response', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);

    enableProjectEmbedding($this, $project, $owner);

    $response = $this->get(route('karaoke.embed.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]));

    $response->assertOk();
    $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    $response->assertHeader('Referrer-Policy', 'no-referrer');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    expect(str_contains($response->headers->get('Cache-Control'), 'no-store'))->toBeTrue();
});

it('includes a restrictive permissions-policy header on the embed player response', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);

    enableProjectEmbedding($this, $project, $owner);

    $response = $this->get(route('karaoke.embed.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]));

    $response->assertOk();
    $response->assertHeader(
        'Permissions-Policy',
        'camera=(), microphone=(), geolocation=(), payment=(), usb=(), bluetooth=(), midi=(), magnetometer=(), gyroscope=(), accelerometer=()',
    );

    $policy = (string) $response->headers->get('Permissions-Policy');
    expect($policy)->toContain('camera=()');
    expect($policy)->toContain('microphone=()');
    expect($policy)->toContain('geolocation=()');
});

it('does not place iframe markup or tokens in session when enabling embedding', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);

    $response = enableProjectEmbedding($this, $project, $owner);

    $sessionPayload = json_encode($response->getSession()->all());

    $response->assertRedirect(route('karaoke.projects.show', $project));
    expect($sessionPayload)->not->toContain('<iframe');
    expect($sessionPayload)->not->toContain('/karaoke/embed/');
    expect($sessionPayload)->not->toContain($shareData['token']);
    expect($sessionPayload)->not->toMatch('/"token"/');
});

it('does not place iframe markup or tokens in session when updating embedding', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);

    enableProjectEmbedding($this, $project, $owner);

    $response = enableProjectEmbedding(
        $this,
        $project,
        $owner,
        "https://example.com\nhttps://updated.example.org",
    );

    $sessionPayload = json_encode($response->getSession()->all());

    $response->assertRedirect(route('karaoke.projects.show', $project));
    expect($sessionPayload)->not->toContain('<iframe');
    expect($sessionPayload)->not->toContain('/karaoke/embed/');
    expect($sessionPayload)->not->toContain($shareData['token']);
    expect($sessionPayload)->not->toMatch('/"token"/');
});

it('escapes special characters in project titles inside iframe markup on the show page', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner, [
        'title' => '"Evil" onclick=alert(1)',
    ]);
    $shareData = $this->createShareForProject($project, $owner);

    enableProjectEmbedding($this, $project, $owner);

    $response = $this->actingAs($owner)
        ->get(route('karaoke.projects.show', $project));

    $response->assertOk();
    $response->assertSee('&quot;Evil&quot; onclick=alert(1)', false);
    $response->assertDontSee('title=""Evil" onclick=alert(1)"', false);
    $response->assertDontSee('<iframe title=""Evil"', false);
    expect($response->getContent())->not->toContain('title=""Evil" onclick=alert(1)"');
});

it('still allows owners to access their private player route', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $this->createShareForProject($project, $owner);

    enableProjectEmbedding($this, $project, $owner);

    $this->actingAs($owner)
        ->get(route('karaoke.projects.player', $project))
        ->assertOk()
        ->assertSee('Karoks')
        ->assertSee($project->title)
        ->assertSee('x-data="karoksPlayer', false);
});

it('still renders a valid guest public share after embedding is enabled', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);

    enableProjectEmbedding($this, $project, $owner);

    $this->get(route('karaoke.shared.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))
        ->assertOk()
        ->assertSee('Karoks')
        ->assertSee('x-data="karoksPlayer', false);
});

it('keeps phase seven notification coverage available and avoids outbound http during embed flows', function () {
    expect(file_exists(base_path('tests/Feature/KaraokeNotificationTest.php')))->toBeTrue();

    Http::fake();

    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);

    enableProjectEmbedding($this, $project, $owner);

    $this->get(route('karaoke.embed.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))->assertOk();

    Http::assertNothingSent();
});

it('does not make live http requests during embed enable or embed playback', function () {
    Http::fake();

    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);

    enableProjectEmbedding($this, $project, $owner);

    $this->get(route('karaoke.embed.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]));

    Http::assertNothingSent();
});

it('does not modify wave core since the embedding baseline commit', function () {
    $repoRoot = base_path();
    $command = 'git diff cb49afa...HEAD -- wave/ 2>&1';
    $previousDirectory = getcwd();

    chdir($repoRoot);

    try {
        $output = shell_exec($command);
    } finally {
        if (is_string($previousDirectory)) {
            chdir($previousDirectory);
        }
    }

    expect(trim((string) $output))->toBe('');
});
