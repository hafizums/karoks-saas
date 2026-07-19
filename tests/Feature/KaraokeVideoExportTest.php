<?php

use App\Enums\KaraokeProjectStatus;
use DevDojo\Themes\Models\Theme;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
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
});

it('redirects guests from the video export page', function () {
    $project = createPlayerProject(createKaraokePlayerUser());

    $this->get(route('karaoke.projects.export.video', $project))
        ->assertRedirect(route('login'));
});

it('allows owners to open the export page for ready projects', function () {
    $user = createKaraokePlayerUser();
    $project = createPlayerProject($user, ['title' => 'Export Ready Track']);

    $this->actingAs($user)
        ->get(route('karaoke.projects.export.video', $project))
        ->assertOk()
        ->assertSee('Local WebM export')
        ->assertSee('Export Ready Track')
        ->assertSee('x-data="karoksVideoExport', false)
        ->assertSee('x-init="init()"', false);
});

it('blocks other users from the video export page', function () {
    $owner = createKaraokePlayerUser();
    $other = createKaraokePlayerUser();
    $project = createPlayerProject($owner);

    $this->actingAs($other)
        ->get(route('karaoke.projects.export.video', $project))
        ->assertForbidden();
});

it('forbids export for incomplete projects', function () {
    $user = createKaraokePlayerUser();
    $project = createPlayerProject($user, [
        'transcript' => null,
        'status' => KaraokeProjectStatus::Uploaded,
    ]);

    $this->actingAs($user)
        ->get(route('karaoke.projects.export.video', $project))
        ->assertForbidden();
});

it('forbids export when instrumental audio is missing', function () {
    $user = createKaraokePlayerUser();
    $project = createPlayerProject($user);
    Storage::disk('local')->delete($project->instrumental_path);

    $this->actingAs($user)
        ->get(route('karaoke.projects.export.video', $project))
        ->assertForbidden();
});

it('forbids export when transcript is invalid', function () {
    $user = createKaraokePlayerUser();
    $project = createPlayerProject($user, [
        'transcript' => ['version' => 99, 'lines' => []],
        'status' => KaraokeProjectStatus::Uploaded,
    ]);

    $this->actingAs($user)
        ->get(route('karaoke.projects.export.video', $project))
        ->assertForbidden();
});

it('shows export action on project details when playback is ready', function () {
    $user = createKaraokePlayerUser();
    $ready = createPlayerProject($user, ['title' => 'Ready Export Track']);

    $this->actingAs($user)
        ->get(route('karaoke.projects.show', $ready))
        ->assertOk()
        ->assertSee('Export video')
        ->assertSee(route('karaoke.projects.export.video', $ready), false);
});

it('does not show export action on project details when playback is not ready', function () {
    $user = createKaraokePlayerUser();
    $pending = createPlayerProject($user, [
        'title' => 'Pending Export Track',
        'transcript' => null,
        'status' => KaraokeProjectStatus::Uploaded,
    ]);

    $this->actingAs($user)
        ->get(route('karaoke.projects.show', $pending))
        ->assertOk()
        ->assertSee('Karaoke player unavailable')
        ->assertDontSee('Export video')
        ->assertDontSee(route('karaoke.projects.export.video', $pending), false);
});

it('does not show export action on public share pages', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);

    $this->get(route('karaoke.shared.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))
        ->assertOk()
        ->assertDontSee('Export video')
        ->assertDontSee(route('karaoke.projects.export.video', $project), false)
        ->assertDontSee('/export/video', false);
});

it('does not show export action on public embed pages', function () {
    $owner = $this->createShareUser();
    $project = $this->createShareReadyProject($owner);
    $shareData = $this->createShareForProject($project, $owner);

    $this->actingAs($owner)->patch(route('karaoke.projects.share.embed.update', $project), [
        'embedding_confirmation' => '1',
        'embed_allowed_origins' => "https://example.com\n",
    ]);

    $this->get(route('karaoke.embed.show', [
        'share' => $shareData['share']->public_id,
        'token' => $shareData['token'],
    ]))
        ->assertOk()
        ->assertDontSee('Export video')
        ->assertDontSee(route('karaoke.projects.export.video', $project), false)
        ->assertDontSee('/export/video', false);
});

it('uses the authenticated instrumental audio route on the export page', function () {
    $user = createKaraokePlayerUser();
    $project = createPlayerProject($user);
    $expectedAudioUrl = route('karaoke.projects.audio', $project);

    $response = $this->actingAs($user)->get(route('karaoke.projects.export.video', $project));

    $response->assertOk();
    $response->assertSee('audioUrl', false);
    $response->assertSee($project->public_id, false);

    preg_match("/karoksVideoExport\\(JSON\\.parse\\('(.+?)'\\)\\)/s", $response->getContent(), $matches);
    expect($matches)->not->toBeEmpty();

    $configJson = json_decode(
        preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', static fn (array $match): string => mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE'), $matches[1]),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $audioUrl = str_replace('\\/', '/', $configJson['audioUrl']);

    expect($audioUrl)->toBe($expectedAudioUrl);
    expect($audioUrl)->not->toContain('/karaoke/shared/');
});

it('does not expose storage paths on the export page', function () {
    $user = createKaraokePlayerUser();
    $project = createPlayerProject($user);

    $response = $this->actingAs($user)->get(route('karaoke.projects.export.video', $project));

    $response->assertOk();
    $response->assertDontSee($project->source_path, false);
    $response->assertDontSee($project->instrumental_path, false);
    $response->assertDontSee('storage/app', false);
    $response->assertDontSee('database/fixtures', false);
});

it('uses the project public_id in export routes', function () {
    $user = createKaraokePlayerUser();
    $project = createPlayerProject($user);

    expect($project->public_id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');

    $exportUrl = route('karaoke.projects.export.video', $project);

    expect($exportUrl)->toContain($project->public_id);
    expect($exportUrl)->not->toContain('/'.$project->id.'/');

    $this->actingAs($user)
        ->get($exportUrl)
        ->assertOk();
});
