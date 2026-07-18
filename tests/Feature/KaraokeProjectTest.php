<?php

use App\Models\KaraokeProject;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function karaokeWavUpload(string $filename = 'sample.wav'): UploadedFile
{
    return new UploadedFile(
        base_path('tests/fixtures/sample.wav'),
        $filename,
        'audio/wav',
        null,
        true
    );
}

function createKaraokeUser(): User
{
    return User::factory()->create(['verified' => 1]);
}

function createStoredKaraokeProject(User $user, array $attributes = []): KaraokeProject
{
    $publicId = (string) Str::uuid();
    $path = 'karaoke/'.$user->id.'/'.$publicId.'/source.wav';

    Storage::disk('local')->put($path, file_get_contents(base_path('tests/fixtures/sample.wav')));

    return KaraokeProject::factory()->create(array_merge([
        'user_id' => $user->id,
        'public_id' => $publicId,
        'source_path' => $path,
    ], $attributes));
}

beforeEach(function () {
    Storage::fake('local');
    KaraokeProject::query()->delete();
});

it('redirects guests from karaoke routes', function () {
    $this->get(route('karaoke.projects.index'))->assertRedirect(route('login'));
    $this->get(route('karaoke.projects.create'))->assertRedirect(route('login'));
});

it('shows only the authenticated users projects', function () {
    $userA = createKaraokeUser();
    $userB = createKaraokeUser();

    $owned = createStoredKaraokeProject($userA, ['title' => 'My Track']);
    createStoredKaraokeProject($userB, ['title' => 'Other Track']);

    $this->actingAs($userA)
        ->get(route('karaoke.projects.index'))
        ->assertOk()
        ->assertSee('My Track')
        ->assertDontSee('Other Track');
});

it('creates a database record for a valid audio upload', function () {
    $user = createKaraokeUser();

    $response = $this->actingAs($user)->post(route('karaoke.projects.store'), [
        'title' => 'Demo Song',
        'artist' => 'Demo Artist',
        'audio' => karaokeWavUpload(),
        'rights_confirmed' => '1',
    ]);

    $response->assertRedirect();

    $project = KaraokeProject::first();

    expect($project)->not->toBeNull()
        ->and($project->title)->toBe('Demo Song')
        ->and($project->artist)->toBe('Demo Artist')
        ->and($project->user_id)->toBe($user->id)
        ->and($project->status->value)->toBe('uploaded');
});

it('stores uploaded audio on the private local disk', function () {
    $user = createKaraokeUser();

    $this->actingAs($user)->post(route('karaoke.projects.store'), [
        'title' => 'Private Track',
        'audio' => karaokeWavUpload(),
        'rights_confirmed' => '1',
    ]);

    $project = KaraokeProject::first();

    Storage::disk('local')->assertExists($project->source_path);
    expect($project->source_path)->toStartWith('karaoke/'.$user->id.'/');
    expect(str_contains($project->source_path, '/public/'))->toBeFalse();
});

it('requires rights confirmation', function () {
    $user = createKaraokeUser();

    $this->actingAs($user)->post(route('karaoke.projects.store'), [
        'title' => 'No Rights',
        'audio' => karaokeWavUpload(),
    ])->assertSessionHasErrors('rights_confirmed');

    expect(KaraokeProject::count())->toBe(0);
});

it('rejects invalid extensions', function () {
    $user = createKaraokeUser();

    $invalid = UploadedFile::fake()->createWithContent('track.exe', 'not audio');

    $this->actingAs($user)->post(route('karaoke.projects.store'), [
        'title' => 'Bad File',
        'audio' => $invalid,
        'rights_confirmed' => '1',
    ])->assertSessionHasErrors('audio');

    expect(KaraokeProject::count())->toBe(0);
});

it('rejects oversized files', function () {
    $user = createKaraokeUser();

    $oversized = UploadedFile::fake()->create('large.wav', 51201, 'audio/wav');

    $this->actingAs($user)->post(route('karaoke.projects.store'), [
        'title' => 'Too Large',
        'audio' => $oversized,
        'rights_confirmed' => '1',
    ])->assertSessionHasErrors('audio');

    expect(KaraokeProject::count())->toBe(0);
});

it('forbids viewing another users project', function () {
    $userA = createKaraokeUser();
    $userB = createKaraokeUser();
    $project = createStoredKaraokeProject($userB);

    $this->actingAs($userA)
        ->get(route('karaoke.projects.show', $project))
        ->assertForbidden();
});

it('forbids downloading another users source file', function () {
    $userA = createKaraokeUser();
    $userB = createKaraokeUser();
    $project = createStoredKaraokeProject($userB);

    $this->actingAs($userA)
        ->get(route('karaoke.projects.source', $project))
        ->assertForbidden();
});

it('forbids deleting another users project', function () {
    $userA = createKaraokeUser();
    $userB = createKaraokeUser();
    $project = createStoredKaraokeProject($userB);

    $this->actingAs($userA)
        ->delete(route('karaoke.projects.destroy', $project))
        ->assertForbidden();

    expect(KaraokeProject::count())->toBe(1);
});

it('deletes private stored files when a project is deleted', function () {
    $user = createKaraokeUser();
    $project = createStoredKaraokeProject($user);
    $path = $project->source_path;

    Storage::disk('local')->assertExists($path);

    $this->actingAs($user)
        ->delete(route('karaoke.projects.destroy', $project))
        ->assertRedirect(route('karaoke.projects.index'));

    Storage::disk('local')->assertMissing($path);
    expect(KaraokeProject::count())->toBe(0);
});

it('uses public ids in project urls', function () {
    $user = createKaraokeUser();
    $project = createStoredKaraokeProject($user);

    $this->actingAs($user)
        ->get(route('karaoke.projects.show', $project))
        ->assertOk();

    expect(route('karaoke.projects.show', $project))->toBe(url('/karaoke/'.$project->public_id));
    expect(parse_url(route('karaoke.projects.show', $project), PHP_URL_PATH))
        ->toBe('/karaoke/'.$project->public_id)
        ->not->toMatch('/\/karaoke\/\d+$/');
});
