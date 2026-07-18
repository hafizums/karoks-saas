<?php

use App\Models\KaraokeProject;
use App\Models\User;
use App\Support\KaraokeProjectExporter;
use App\Support\KaraokeProjectImporter;
use App\Support\KaraokeTranscriptEditor;
use App\Support\KaraokeTranscriptParser;
use DevDojo\Themes\Models\Theme;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\KaraokeTestTheme;

uses(DatabaseTransactions::class);

function editorDemoTranscript(): array
{
    return json_decode(
        (string) file_get_contents(database_path('fixtures/karoks-demo-transcript.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

function createEditorUser(): User
{
    return User::factory()->create(['verified' => 1]);
}

function createEditableProject(User $user, array $attributes = []): KaraokeProject
{
    $publicId = (string) Str::uuid();
    $path = 'karaoke/'.$user->id.'/'.$publicId.'/source.wav';

    Storage::disk('local')->put($path, file_get_contents(base_path('tests/fixtures/sample.wav')));

    return KaraokeProject::factory()->create(array_merge([
        'user_id' => $user->id,
        'public_id' => $publicId,
        'source_path' => $path,
        'mime_type' => 'audio/wav',
        'size_bytes' => strlen(Storage::disk('local')->get($path)),
        'transcript' => editorDemoTranscript(),
        'theme' => [
            'backgroundPreset' => 'noir-gold',
            'lyricSize' => 'medium',
            'baseColor' => '#f4f0e6',
            'highlightColor' => '#f0c14b',
        ],
        'editor_revision' => 1,
    ], $attributes));
}

function editorUpdatePayload(KaraokeProject $project, array $overrides = []): array
{
    return array_merge([
        'revision' => $project->editor_revision,
    ], $overrides);
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

it('redirects guests from editor routes', function () {
    $project = createEditableProject(createEditorUser());

    $this->get(route('karaoke.projects.edit', $project))->assertRedirect(route('login'));
    $this->patch(route('karaoke.projects.update', $project))->assertRedirect(route('login'));
    $this->get(route('karaoke.projects.export', $project))->assertRedirect(route('login'));
    $this->post(route('karaoke.projects.import', $project))->assertRedirect(route('login'));
});

it('allows owners to access the editor', function () {
    $user = createEditorUser();
    $project = createEditableProject($user);

    $this->actingAs($user)
        ->get(route('karaoke.projects.edit', $project))
        ->assertOk()
        ->assertSee('Edit karaoke project')
        ->assertSee('x-data="karoksEditor', false);
});

it('forbids other users from viewing or updating the editor', function () {
    $owner = createEditorUser();
    $other = createEditorUser();
    $project = createEditableProject($owner);

    $this->actingAs($other)->get(route('karaoke.projects.edit', $project))->assertForbidden();
    $this->actingAs($other)->patchJson(route('karaoke.projects.update', $project), editorUpdatePayload($project))->assertForbidden();
});

it('shows unavailable state when transcript is missing', function () {
    $user = createEditorUser();
    $project = createEditableProject($user, ['transcript' => null]);

    $this->actingAs($user)
        ->get(route('karaoke.projects.edit', $project))
        ->assertOk()
        ->assertSee('Lyrics are not ready for editing')
        ->assertDontSee('karoksEditor', false);
});

it('persists metadata updates', function () {
    $user = createEditorUser();
    $project = createEditableProject($user);

    $this->actingAs($user)->patchJson(route('karaoke.projects.update', $project), editorUpdatePayload($project, [
        'title' => 'Updated Title',
        'artist' => 'Updated Artist',
    ]))->assertOk()->assertJsonPath('title', 'Updated Title');

    $project->refresh();
    expect($project->title)->toBe('Updated Title')
        ->and($project->artist)->toBe('Updated Artist');
});

it('persists word text updates without changing timing', function () {
    $user = createEditorUser();
    $project = createEditableProject($user);
    $before = $project->parsedTranscript();

    $this->actingAs($user)->patchJson(route('karaoke.projects.update', $project), editorUpdatePayload($project, [
        'words' => ['word-1' => 'Changed'],
    ]))->assertOk();

    $project->refresh();
    $after = $project->parsedTranscript();

    expect($after['lines'][0]['words'][0]['text'])->toBe('Changed');
    expect($after['lines'][0]['words'][0]['start'])->toBe($before['lines'][0]['words'][0]['start']);
    expect($after['lines'][0]['words'][0]['end'])->toBe($before['lines'][0]['words'][0]['end']);
    expect($after['lines'][0]['words'][0]['id'])->toBe('word-1');
});

it('rejects unknown word ids', function () {
    $user = createEditorUser();
    $project = createEditableProject($user);

    $this->actingAs($user)->patchJson(route('karaoke.projects.update', $project), editorUpdatePayload($project, [
        'words' => ['missing-word' => 'Nope'],
    ]))->assertStatus(422);
});

it('rejects invalid themes', function () {
    $user = createEditorUser();
    $project = createEditableProject($user);

    $this->actingAs($user)->patchJson(route('karaoke.projects.update', $project), editorUpdatePayload($project, [
        'theme' => [
            'backgroundPreset' => 'invalid',
            'lyricSize' => 'medium',
            'baseColor' => '#ffffff',
            'highlightColor' => '#000000',
        ],
    ]))->assertStatus(422);
});

it('persists valid theme changes', function () {
    $user = createEditorUser();
    $project = createEditableProject($user);

    $this->actingAs($user)->patchJson(route('karaoke.projects.update', $project), editorUpdatePayload($project, [
        'theme' => [
            'backgroundPreset' => 'midnight-blue',
            'lyricSize' => 'large',
            'baseColor' => '#ffffff',
            'highlightColor' => '#336699',
        ],
    ]))->assertOk()->assertJsonPath('theme.backgroundPreset', 'midnight-blue');

    $project->refresh();
    expect($project->theme['backgroundPreset'])->toBe('midnight-blue');
});

it('increments revision after save', function () {
    $user = createEditorUser();
    $project = createEditableProject($user);

    $this->actingAs($user)->patchJson(route('karaoke.projects.update', $project), editorUpdatePayload($project, [
        'title' => 'Revision Test',
    ]))->assertJsonPath('revision', 2);

    $project->refresh();
    expect($project->editor_revision)->toBe(2);
});

it('returns 409 for stale revisions and does not overwrite current data', function () {
    $user = createEditorUser();
    $project = createEditableProject($user, ['title' => 'Current Title', 'editor_revision' => 2]);

    $response = $this->actingAs($user)->patchJson(route('karaoke.projects.update', $project), [
        'revision' => 1,
        'title' => 'Stale Title',
    ]);

    $response->assertStatus(409)->assertJsonPath('conflict', true)->assertJsonPath('state.title', 'Current Title');

    $project->refresh();
    expect($project->title)->toBe('Current Title');
});

it('requires ownership for export', function () {
    $owner = createEditorUser();
    $other = createEditorUser();
    $project = createEditableProject($owner);

    $this->actingAs($other)->get(route('karaoke.projects.export', $project))->assertForbidden();
});

it('exports safe json without private fields', function () {
    $user = createEditorUser();
    $project = createEditableProject($user);

    $response = $this->actingAs($user)->get(route('karaoke.projects.export', $project));
    $response->assertOk();

    $json = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($json['schema'])->toBe('karoks-project')
        ->and($json['project']['title'])->toBe($project->title)
        ->and(json_encode($json))->not->toContain('user_id')
        ->and(json_encode($json))->not->toContain($project->source_path)
        ->and(json_encode($json))->not->toContain('audioUrl');
});

it('round trips export and import successfully', function () {
    $user = createEditorUser();
    $project = createEditableProject($user, ['title' => 'Round Trip']);

    $export = KaraokeProjectExporter::buildPayload($project);
    $export['project']['title'] = 'Imported Title';
    $export['project']['transcript']['lines'][0]['words'][0]['text'] = 'Imported';

    $file = UploadedFile::fake()->createWithContent('project.json', json_encode($export));

    $this->actingAs($user)->post(route('karaoke.projects.import', $project), [
        'revision' => $project->editor_revision,
        'import' => $file,
    ])->assertOk()->assertJsonPath('title', 'Imported Title');

    $project->refresh();
    expect($project->title)->toBe('Imported Title')
        ->and($project->parsedTranscript()['lines'][0]['words'][0]['text'])->toBe('Imported');
});

it('rejects malformed json import', function () {
    $user = createEditorUser();
    $project = createEditableProject($user, ['title' => 'Keep Me']);
    $file = UploadedFile::fake()->createWithContent('broken.json', '{not-json');

    $this->actingAs($user)->post(route('karaoke.projects.import', $project), [
        'revision' => $project->editor_revision,
        'import' => $file,
    ])->assertStatus(422);

    $project->refresh();
    expect($project->title)->toBe('Keep Me');
});

it('rejects wrong schema import', function () {
    $user = createEditorUser();
    $project = createEditableProject($user);
    $payload = ['schema' => 'other', 'version' => 1, 'project' => []];
    $file = UploadedFile::fake()->createWithContent('bad.json', json_encode($payload));

    $this->actingAs($user)->post(route('karaoke.projects.import', $project), [
        'revision' => $project->editor_revision,
        'import' => $file,
    ])->assertStatus(422);
});

it('rejects mismatched timing skeleton on import', function () {
    $user = createEditorUser();
    $project = createEditableProject($user);
    $export = KaraokeProjectExporter::buildPayload($project);
    $export['project']['transcript']['lines'][0]['start'] = 99.0;
    $file = UploadedFile::fake()->createWithContent('mismatch.json', json_encode($export));

    $this->actingAs($user)->post(route('karaoke.projects.import', $project), [
        'revision' => $project->editor_revision,
        'import' => $file,
    ])->assertStatus(422);
});

it('leaves database unchanged when import validation fails', function () {
    $user = createEditorUser();
    $project = createEditableProject($user, ['title' => 'Stable']);
    $beforeRevision = $project->editor_revision;
    $file = UploadedFile::fake()->createWithContent('bad.json', json_encode(['schema' => 'nope']));

    $this->actingAs($user)->post(route('karaoke.projects.import', $project), [
        'revision' => $project->editor_revision,
        'import' => $file,
    ])->assertStatus(422);

    $project->refresh();
    expect($project->title)->toBe('Stable')
        ->and($project->editor_revision)->toBe($beforeRevision);
});

it('renders html-like lyric text safely in the editor', function () {
    $user = createEditorUser();
    $transcript = editorDemoTranscript();
    $transcript['lines'][0]['words'][0]['text'] = '<script>alert(1)</script>';
    $project = createEditableProject($user, ['transcript' => $transcript]);

    $this->actingAs($user)
        ->get(route('karaoke.projects.edit', $project))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('x-text="word.text"', false);
});

it('enforces transcript resource limits', function () {
    $lines = [];
    for ($i = 0; $i < KaraokeTranscriptParser::MAX_LINES + 1; $i++) {
        $lines[] = [
            'id' => 'line-'.$i,
            'start' => $i,
            'end' => $i + 1,
            'words' => [
                ['id' => 'word-'.$i, 'text' => 'x', 'start' => $i, 'end' => $i + 1],
            ],
        ];
    }

    expect(KaraokeTranscriptParser::parse([
        'version' => 1,
        'lines' => $lines,
    ]))->toBeNull();
});

it('rejects empty word text on update', function () {
    $user = createEditorUser();
    $project = createEditableProject($user);

    $this->actingAs($user)->patchJson(route('karaoke.projects.update', $project), editorUpdatePayload($project, [
        'words' => ['word-1' => '   '],
    ]))->assertStatus(422);
});

it('blocks update when transcript is unavailable', function () {
    $user = createEditorUser();
    $project = createEditableProject($user, ['transcript' => null]);

    $this->actingAs($user)->patchJson(route('karaoke.projects.update', $project), editorUpdatePayload($project, [
        'title' => 'Nope',
    ]))->assertStatus(422);
});

it('preserves word ids and line ids through editor updates', function () {
    $user = createEditorUser();
    $project = createEditableProject($user);
    $before = KaraokeTranscriptEditor::wordIdIndex($project->parsedTranscript());

    $this->actingAs($user)->patchJson(route('karaoke.projects.update', $project), editorUpdatePayload($project, [
        'words' => ['word-2' => 'Updated word'],
    ]))->assertOk();

    $after = KaraokeTranscriptEditor::wordIdIndex($project->fresh()->parsedTranscript());
    expect(array_keys($after))->toEqual(array_keys($before));
});

it('validates import payload helper rejects malformed structures', function () {
    $project = createEditableProject(createEditorUser());

    expect(fn () => KaraokeProjectImporter::parseImportPayload(['schema' => 'bad'], $project))
        ->toThrow(InvalidArgumentException::class);
});
