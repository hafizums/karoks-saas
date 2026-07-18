<?php

use App\Contracts\KaraokeProcessor;
use App\Enums\KaraokeProcessingStage;
use App\Enums\KaraokeProjectStatus;
use App\Exceptions\UnsupportedKaroksProcessingDriverException;
use App\Jobs\ProcessKaraokeProject;
use App\Models\KaraokeProject;
use App\Models\User;
use App\Support\Karaoke\Processors\MockKaraokeProcessor;
use App\Support\Karaoke\Processors\MockKaraokeSyntheticTranscript;
use App\Support\KaraokeProcessingStateService;
use App\Support\KaraokeProcessorManager;
use App\Support\KaraokeThemeParser;
use App\Support\KaraokeTranscriptParser;
use DevDojo\Themes\Models\Theme;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\KaraokeTestTheme;

uses(DatabaseTransactions::class);

function processingUser(): User
{
    return User::factory()->create(['verified' => 1]);
}

function createUploadedProcessingProject(User $user, array $attributes = []): KaraokeProject
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
        'status' => KaraokeProjectStatus::Uploaded,
        'transcript' => null,
        'theme' => null,
    ], $attributes));
}

function runProcessingJob(KaraokeProject $project): void
{
    $project->refresh();

    (new ProcessKaraokeProject($project->id, (string) $project->processing_run_id))->handle(
        app(KaraokeProcessor::class),
        app(KaraokeProcessingStateService::class),
    );
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
    Queue::fake();
    Config::set('karoks.processing.driver', 'mock');
    Config::set('karoks.processing.mock_stage_delay_ms', 0);
});

it('redirects guests from processing routes', function () {
    $project = createUploadedProcessingProject(processingUser());

    $this->post(route('karaoke.projects.process', $project))->assertRedirect(route('login'));
    $this->post(route('karaoke.projects.cancel', $project))->assertRedirect(route('login'));
    $this->post(route('karaoke.projects.retry', $project))->assertRedirect(route('login'));
    $this->get(route('karaoke.projects.status', $project))->assertRedirect(route('login'));
});

it('forbids another user from starting processing', function () {
    $owner = processingUser();
    $other = processingUser();
    $project = createUploadedProcessingProject($owner);

    $this->actingAs($other)
        ->post(route('karaoke.projects.process', $project))
        ->assertForbidden();
});

it('forbids another user from reading status', function () {
    $owner = processingUser();
    $other = processingUser();
    $project = createUploadedProcessingProject($owner);

    $this->actingAs($other)
        ->get(route('karaoke.projects.status', $project))
        ->assertForbidden();
});

it('forbids another user from cancelling or retrying', function () {
    $owner = processingUser();
    $other = processingUser();
    $project = createUploadedProcessingProject($owner, ['status' => KaraokeProjectStatus::Queued]);

    $this->actingAs($other)
        ->post(route('karaoke.projects.cancel', $project))
        ->assertForbidden();

    $failed = createUploadedProcessingProject($owner, [
        'status' => KaraokeProjectStatus::Failed,
        'error_code' => 'processing_failed',
        'error_message' => 'Processing could not be completed. Please try again.',
    ]);

    $this->actingAs($other)
        ->post(route('karaoke.projects.retry', $failed))
        ->assertForbidden();
});

it('queues an uploaded project for processing', function () {
    Bus::fake();

    $user = processingUser();
    $project = createUploadedProcessingProject($user);

    $this->actingAs($user)
        ->post(route('karaoke.projects.process', $project))
        ->assertRedirect(route('karaoke.projects.show', $project));

    $project->refresh();

    expect($project->status)->toBe(KaraokeProjectStatus::Queued)
        ->and($project->processing_run_id)->not->toBeNull();

    Bus::assertDispatched(ProcessKaraokeProject::class);
});

it('dispatches processing after commit', function () {
    Queue::fake();

    $user = processingUser();
    $project = createUploadedProcessingProject($user);

    app(KaraokeProcessingStateService::class)->queueForProcessing($project);

    Queue::assertPushed(ProcessKaraokeProject::class, 1);
});

it('does not dispatch duplicate starts', function () {
    Queue::fake();

    $user = processingUser();
    $project = createUploadedProcessingProject($user);

    $this->actingAs($user)->post(route('karaoke.projects.process', $project));
    $this->actingAs($user)->post(route('karaoke.projects.process', $project->fresh()));

    Queue::assertPushed(ProcessKaraokeProject::class, 1);
});

it('assigns a new run uuid for accepted attempts', function () {
    $user = processingUser();
    $project = createUploadedProcessingProject($user);

    $first = app(KaraokeProcessingStateService::class)->queueForProcessing($project);
    $project->refresh();
    $firstRun = $project->processing_run_id;

    app(KaraokeProcessingStateService::class)->cancelProcessing($project->fresh());
    $project->refresh();

    app(KaraokeProcessingStateService::class)->queueForProcessing($project->fresh());
    $project->refresh();

    expect($first['run_id'])->toBe($firstRun)
        ->and($project->processing_run_id)->not->toBe($firstRun);
});

it('increments processing attempts exactly once per accepted attempt', function () {
    $user = processingUser();
    $project = createUploadedProcessingProject($user);

    app(KaraokeProcessingStateService::class)->queueForProcessing($project);
    $project->refresh();
    expect($project->processing_attempts)->toBe(1);

    app(KaraokeProcessingStateService::class)->queueForProcessing($project->fresh());
    $project->refresh();
    expect($project->processing_attempts)->toBe(1);

    app(KaraokeProcessingStateService::class)->cancelProcessing($project->fresh());
    app(KaraokeProcessingStateService::class)->queueForProcessing($project->fresh());
    $project->refresh();
    expect($project->processing_attempts)->toBe(2);
});

it('completes mock processing with valid output', function () {
    Http::fake();

    $user = processingUser();
    $project = createUploadedProcessingProject($user);

    app(KaraokeProcessingStateService::class)->queueForProcessing($project);
    runProcessingJob($project->fresh());

    $project->refresh();

    expect($project->status)->toBe(KaraokeProjectStatus::Completed)
        ->and($project->processing_stage)->toBe(KaraokeProcessingStage::Completed->value)
        ->and($project->progress)->toBe(100)
        ->and(KaraokeTranscriptParser::parse($project->transcript))->not->toBeNull()
        ->and(KaraokeThemeParser::parse($project->theme))->not->toBeNull()
        ->and($project->instrumental_path)->toStartWith('karaoke/'.$user->id.'/')
        ->and(str_contains($project->instrumental_path, '/instrumental.'))->toBeTrue()
        ->and(Storage::disk('local')->exists($project->instrumental_path))->toBeTrue()
        ->and($project->error_message)->toBe(MockKaraokeSyntheticTranscript::DISCLOSURE);

    Http::assertNothingSent();
});

it('never moves stage or progress backward during processing', function () {
    $user = processingUser();
    $project = createUploadedProcessingProject($user);
    $state = app(KaraokeProcessingStateService::class);

    $state->queueForProcessing($project);
    $project->refresh();
    $runId = (string) $project->processing_run_id;

    $state->beginProcessingRun($project->fresh(), $runId);
    $state->recordProgress($project->fresh(), $runId, KaraokeProcessingStage::Separating, 30);
    $project->refresh();
    expect($project->progress)->toBe(30);

    $state->recordProgress($project->fresh(), $runId, KaraokeProcessingStage::Preparing, 10);
    $project->refresh();
    expect($project->progress)->toBe(30);
});

it('preserves the original source file after processing', function () {
    $user = processingUser();
    $project = createUploadedProcessingProject($user);
    $original = Storage::disk('local')->get($project->source_path);

    app(KaraokeProcessingStateService::class)->queueForProcessing($project);
    runProcessingJob($project->fresh());

    expect(Storage::disk('local')->get($project->fresh()->source_path))->toBe($original);
});

it('streams instrumental output after completion', function () {
    $user = processingUser();
    $project = createUploadedProcessingProject($user);

    app(KaraokeProcessingStateService::class)->queueForProcessing($project);
    runProcessingJob($project->fresh());
    $project->refresh();

    Storage::disk('local')->put($project->instrumental_path, 'instrumental-stream-marker');

    $response = $this->actingAs($user)->get(route('karaoke.projects.audio', $project));

    $response->assertOk();
    expect($response->streamedContent())->toBe('instrumental-stream-marker');
});

it('continues serving source downloads from the source route', function () {
    $user = processingUser();
    $project = createUploadedProcessingProject($user);

    app(KaraokeProcessingStateService::class)->queueForProcessing($project);
    runProcessingJob($project->fresh());
    $project->refresh();

    $response = $this->actingAs($user)->get(route('karaoke.projects.source', $project));

    $response->assertOk();
    expect($response->streamedContent())->toBe(Storage::disk('local')->get($project->source_path));
});

it('does not leak ids paths or run uuids in status json', function () {
    $user = processingUser();
    $project = createUploadedProcessingProject($user, [
        'status' => KaraokeProjectStatus::Processing,
        'processing_run_id' => (string) Str::uuid(),
        'processing_stage' => KaraokeProcessingStage::Separating->value,
        'progress' => 30,
    ]);

    $response = $this->actingAs($user)->get(route('karaoke.projects.status', $project));

    $response->assertOk();
    $json = $response->json();

    expect($json)->toHaveKeys(['status', 'stage', 'progress', 'retryable', 'capabilities', 'routes'])
        ->and($json)->not->toHaveKey('id')
        ->and($json)->not->toHaveKey('user_id')
        ->and($json)->not->toHaveKey('processing_run_id')
        ->and($json)->not->toHaveKey('source_path')
        ->and($json)->not->toHaveKey('instrumental_path');

    $encoded = json_encode($json);
    expect($encoded)->not->toContain($project->source_path)
        ->and($encoded)->not->toContain($project->processing_run_id)
        ->and($encoded)->not->toContain('"user_id"')
        ->and($encoded)->not->toContain('"id"');
});

it('prevents a cancelled run from completing later', function () {
    $user = processingUser();
    $project = createUploadedProcessingProject($user);
    $state = app(KaraokeProcessingStateService::class);

    $state->queueForProcessing($project);
    $project->refresh();
    $runId = (string) $project->processing_run_id;

    $state->beginProcessingRun($project->fresh(), $runId);
    $state->cancelProcessing($project->fresh());

    $result = app(MockKaraokeProcessor::class)->process(
        $project->fresh(),
        $runId,
        fn () => null,
    );

    expect($state->markCompleted($project->fresh(), $runId, $result))->toBeFalse();
    $project->refresh();
    expect($project->status)->toBe(KaraokeProjectStatus::Cancelled);
});

it('prevents run a from overwriting run b', function () {
    $user = processingUser();
    $project = createUploadedProcessingProject($user);
    $state = app(KaraokeProcessingStateService::class);

    $state->queueForProcessing($project);
    $project->refresh();
    $runA = (string) $project->processing_run_id;

    $state->markFailed($project->fresh(), $runA, 'processing_failed', 'Processing could not be completed. Please try again.');
    $state->retryProcessing($project->fresh());
    $project->refresh();
    $runB = (string) $project->processing_run_id;

    $result = app(MockKaraokeProcessor::class)->process(
        $project->fresh(),
        $runA,
        fn () => null,
    );

    expect($state->markCompleted($project->fresh(), $runA, $result))->toBeFalse();
    expect($project->fresh()->processing_run_id)->toBe($runB);
});

it('clears previous errors and uses a new run on retry', function () {
    $user = processingUser();
    $project = createUploadedProcessingProject($user, [
        'status' => KaraokeProjectStatus::Failed,
        'error_code' => 'processing_failed',
        'error_message' => 'Processing could not be completed. Please try again.',
        'processing_run_id' => (string) Str::uuid(),
        'processing_attempts' => 1,
    ]);

    $oldRun = $project->processing_run_id;
    app(KaraokeProcessingStateService::class)->retryProcessing($project);
    $project->refresh();

    expect($project->status)->toBe(KaraokeProjectStatus::Queued)
        ->and($project->error_code)->toBeNull()
        ->and($project->error_message)->toBeNull()
        ->and($project->processing_run_id)->not->toBe($oldRun);
});

it('blocks retry for non retryable failures', function () {
    $user = processingUser();
    $project = createUploadedProcessingProject($user, [
        'status' => KaraokeProjectStatus::Failed,
        'error_code' => 'unsupported_audio',
        'error_message' => 'This audio format is not supported.',
    ]);

    $this->actingAs($user)
        ->post(route('karaoke.projects.retry', $project))
        ->assertStatus(422);
});

it('removes partial instrumental output on failure', function () {
    $user = processingUser();
    $project = createUploadedProcessingProject($user);
    $state = app(KaraokeProcessingStateService::class);

    $state->queueForProcessing($project);
    $project->refresh();
    $runId = (string) $project->processing_run_id;
    $state->beginProcessingRun($project->fresh(), $runId);

    $partialPath = $project->storageDirectory().'/instrumental.wav';
    Storage::disk('local')->put($partialPath, 'partial');

    $state->markFailed($project->fresh(), $runId, 'processing_failed', 'Processing could not be completed. Please try again.');

    expect(Storage::disk('local')->exists($partialPath))->toBeFalse()
        ->and(Storage::disk('local')->exists($project->source_path))->toBeTrue();
});

it('exits safely when the project was deleted before the job runs', function () {
    $user = processingUser();
    $project = createUploadedProcessingProject($user);

    app(KaraokeProcessingStateService::class)->queueForProcessing($project);
    $project->refresh();
    $runId = (string) $project->processing_run_id;
    $projectId = $project->id;
    $project->delete();

    (new ProcessKaraokeProject($projectId, $runId))->handle(
        app(KaraokeProcessor::class),
        app(KaraokeProcessingStateService::class),
    );

    expect(KaraokeProject::query()->find($projectId))->toBeNull();
});

it('removes all output when a project is deleted', function () {
    $user = processingUser();
    $project = createUploadedProcessingProject($user);

    app(KaraokeProcessingStateService::class)->queueForProcessing($project);
    runProcessingJob($project->fresh());
    $project->refresh();

    $directory = $project->storageDirectory();

    $this->actingAs($user)->delete(route('karaoke.projects.destroy', $project));

    expect(Storage::disk('local')->exists($directory))->toBeFalse();
});

it('removes all karaoke output when a user is force deleted', function () {
    $user = processingUser();
    $project = createUploadedProcessingProject($user);

    app(KaraokeProcessingStateService::class)->queueForProcessing($project);
    runProcessingJob($project->fresh());

    $directory = 'karaoke/'.$user->id;
    $user->forceDelete();

    expect(Storage::disk('local')->exists($directory))->toBeFalse();
});

it('fails closed for unsupported processing drivers', function () {
    Config::set('karoks.processing.driver', 'wavespeed');

    expect(fn () => app(KaraokeProcessorManager::class)->driver())
        ->toThrow(UnsupportedKaroksProcessingDriverException::class);
});

it('uses a mock processor that makes no outbound http requests', function () {
    Http::fake();

    $user = processingUser();
    $project = createUploadedProcessingProject($user);

    app(MockKaraokeProcessor::class)->process(
        $project,
        (string) Str::uuid(),
        fn () => null,
    );

    Http::assertNothingSent();
});
