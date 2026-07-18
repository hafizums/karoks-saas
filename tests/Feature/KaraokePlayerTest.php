<?php

use App\Models\KaraokeProject;
use App\Models\User;
use App\Support\KaraokeTranscriptParser;
use App\Support\KaroksDemoAudio;
use DevDojo\Themes\Models\Theme;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\KaraokeTestTheme;

uses(DatabaseTransactions::class);

function demoKaraokeTranscript(): array
{
    return json_decode(
        (string) file_get_contents(database_path('fixtures/karoks-demo-transcript.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

function createKaraokePlayerUser(): User
{
    return User::factory()->create(['verified' => 1]);
}

function createPlayerProject(User $user, array $attributes = []): KaraokeProject
{
    $publicId = (string) Str::uuid();
    $path = 'karaoke/'.$user->id.'/'.$publicId.'/source.wav';
    $audioBytes = file_get_contents(base_path('tests/fixtures/sample.wav'));

    Storage::disk('local')->put($path, $audioBytes);

    return KaraokeProject::factory()->create(array_merge([
        'user_id' => $user->id,
        'public_id' => $publicId,
        'source_path' => $path,
        'mime_type' => 'audio/wav',
        'size_bytes' => strlen($audioBytes),
        'transcript' => demoKaraokeTranscript(),
    ], $attributes));
}

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
});

it('redirects guests from player and audio routes', function () {
    $project = createPlayerProject(createKaraokePlayerUser());

    $this->get(route('karaoke.projects.player', $project))->assertRedirect(route('login'));
    $this->get(route('karaoke.projects.audio', $project))->assertRedirect(route('login'));
});

it('allows owners to access their player', function () {
    $user = createKaraokePlayerUser();
    $project = createPlayerProject($user);

    $this->actingAs($user)
        ->get(route('karaoke.projects.player', $project))
        ->assertOk()
        ->assertSee('Karoks')
        ->assertSee($project->title)
        ->assertSee('x-data="karoksPlayer', false)
        ->assertDontSee('x-init="init()"', false);
});

it('blocks other users from the player', function () {
    $owner = createKaraokePlayerUser();
    $other = createKaraokePlayerUser();
    $project = createPlayerProject($owner);

    $this->actingAs($other)
        ->get(route('karaoke.projects.player', $project))
        ->assertForbidden();
});

it('blocks other users from streaming audio', function () {
    $owner = createKaraokePlayerUser();
    $other = createKaraokePlayerUser();
    $project = createPlayerProject($owner);

    $this->actingAs($other)
        ->get(route('karaoke.projects.audio', $project))
        ->assertForbidden();
});

it('shows a safe unavailable state when transcript is missing', function () {
    $user = createKaraokePlayerUser();
    $project = createPlayerProject($user, ['transcript' => null]);

    $this->actingAs($user)
        ->get(route('karaoke.projects.player', $project))
        ->assertOk()
        ->assertSee('Lyrics are not ready')
        ->assertDontSee('InvalidArgumentException', false);
});

it('shows a safe unavailable state when transcript is malformed', function () {
    $user = createKaraokePlayerUser();
    $project = createPlayerProject($user, ['transcript' => ['version' => 99, 'lines' => []]]);

    $this->actingAs($user)
        ->get(route('karaoke.projects.player', $project))
        ->assertOk()
        ->assertSee('Lyrics are not ready');
});

it('passes valid transcript data safely to the player', function () {
    $user = createKaraokePlayerUser();
    $project = createPlayerProject($user);

    $response = $this->actingAs($user)->get(route('karaoke.projects.player', $project));

    $response->assertOk();
    $response->assertSee('Sing', false);
    $response->assertSee('audioUrl', false);
    $response->assertSee($project->public_id, false);
    $response->assertDontSee('database/fixtures', false);
    $response->assertDontSee($project->source_path, false);
});

it('escapes html in lyric strings for safe text rendering', function () {
    $user = createKaraokePlayerUser();
    $transcript = demoKaraokeTranscript();
    $transcript['lines'][0]['words'][0]['text'] = '<img src=x onerror=alert(1)>';
    $project = createPlayerProject($user, ['transcript' => $transcript]);

    $response = $this->actingAs($user)->get(route('karaoke.projects.player', $project));

    $response->assertOk();
    $response->assertDontSee('<img src=x onerror=alert(1)>', false);
    $response->assertSee('x-text="word.text"', false);
    $response->assertSee('class="sr-only"', false);
});

it('rejects duplicate word ids across lines', function () {
    $transcript = demoKaraokeTranscript();
    $transcript['lines'][1]['words'][0]['id'] = $transcript['lines'][0]['words'][0]['id'];

    expect(KaraokeTranscriptParser::parse($transcript))->toBeNull();
});

it('provides a playable demo audio fixture for local testing', function () {
    expect(KaroksDemoAudio::ensureFixtureExists())->toBeTrue();

    $path = KaroksDemoAudio::fixturePath();
    $bytes = file_get_contents($path);

    expect(strlen($bytes))->toBeGreaterThan(1000);
    expect(substr($bytes, 0, 4))->toBe('RIFF');
    expect(substr($bytes, 8, 4))->toBe('WAVE');

    $sampleRate = unpack('V', substr($bytes, 24, 4))[1];
    $dataSize = unpack('V', substr($bytes, 40, 4))[1];
    $duration = $dataSize / ($sampleRate * 2);

    expect($duration)->toBeGreaterThan(26.0);
    expect($duration)->toBeLessThan(28.0);
});

it('returns full audio with correct headers and content length', function () {
    $user = createKaraokePlayerUser();
    $project = createPlayerProject($user);
    $expected = Storage::disk('local')->get($project->source_path);

    $response = $this->actingAs($user)->get(route('karaoke.projects.audio', $project));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'audio/wav');
    $response->assertHeader('Content-Disposition', 'inline');
    $response->assertHeader('Accept-Ranges', 'bytes');
    $response->assertHeader('Content-Length', (string) strlen($expected));
    expect(str_contains($response->headers->get('Cache-Control'), 'no-store'))->toBeTrue();
    expect(str_contains($response->headers->get('Cache-Control'), 'private'))->toBeTrue();
    expect($response->streamedContent())->toBe($expected);
});

it('returns partial content for a valid range request', function () {
    $user = createKaraokePlayerUser();
    $project = createPlayerProject($user);
    $full = Storage::disk('local')->get($project->source_path);

    $response = $this->actingAs($user)
        ->withHeader('Range', 'bytes=0-7')
        ->get(route('karaoke.projects.audio', $project));

    $response->assertStatus(206);
    $response->assertHeader('Content-Range', 'bytes 0-7/'.strlen($full));
    $response->assertHeader('Content-Length', '8');
    expect($response->streamedContent())->toBe(substr($full, 0, 8));
});

it('returns 416 for an invalid range request', function () {
    $user = createKaraokePlayerUser();
    $project = createPlayerProject($user);
    $size = strlen(Storage::disk('local')->get($project->source_path));

    $this->actingAs($user)
        ->withHeader('Range', 'bytes='.$size.'-'.($size + 100))
        ->get(route('karaoke.projects.audio', $project))
        ->assertStatus(416)
        ->assertHeader('Content-Range', 'bytes */'.$size);
});

it('returns headers without a body for head requests', function () {
    $user = createKaraokePlayerUser();
    $project = createPlayerProject($user);
    $size = strlen(Storage::disk('local')->get($project->source_path));

    $response = $this->actingAs($user)->call('HEAD', route('karaoke.projects.audio', $project));

    $response->assertOk();
    $response->assertHeader('Content-Length', (string) $size);
    expect($response->getContent())->toBe('');
});

it('returns 404 when private audio is missing', function () {
    $user = createKaraokePlayerUser();
    $project = createPlayerProject($user);
    Storage::disk('local')->delete($project->source_path);

    $this->actingAs($user)
        ->get(route('karaoke.projects.audio', $project))
        ->assertNotFound();
});

it('shows player link on project details only when transcript is valid', function () {
    $user = createKaraokePlayerUser();
    $ready = createPlayerProject($user, ['title' => 'Ready Track']);
    $pending = createPlayerProject($user, [
        'title' => 'Pending Track',
        'transcript' => null,
    ]);

    $this->actingAs($user)
        ->get(route('karaoke.projects.show', $ready))
        ->assertOk()
        ->assertSee('Open karaoke player')
        ->assertSee(route('karaoke.projects.player', $ready), false);

    $this->actingAs($user)
        ->get(route('karaoke.projects.show', $pending))
        ->assertOk()
        ->assertSee('Karaoke player unavailable')
        ->assertDontSee(route('karaoke.projects.player', $pending), false);
});

it('parses and rejects malformed transcript data safely', function () {
    expect(KaraokeTranscriptParser::parse(null))->toBeNull();
    expect(KaraokeTranscriptParser::parse(['version' => 2, 'lines' => []]))->toBeNull();
    expect(KaraokeTranscriptParser::parse(['version' => 1]))->toBeNull();
    expect(KaraokeTranscriptParser::parse(demoKaraokeTranscript()))->not->toBeNull();
});
