<?php

use App\Enums\KaraokeProjectStatus;
use App\Jobs\ProcessKaraokeProject;
use App\Models\KaraokeProject;
use App\Models\KaraokeUsageRecord;
use App\Models\User;
use App\Notifications\KaraokeProcessingNotification;
use App\Support\KaraokeProcessingResult;
use App\Support\KaraokeProcessingStateService;
use App\Support\KaraokeThemeParser;
use DevDojo\Themes\Models\Theme;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\KaraokeTestTheme;

use function Tests\Support\runKaraokeProcessingJob;

uses(DatabaseTransactions::class);

function recoveryUser(): User
{
    return User::factory()->create(['verified' => 1]);
}

function createRecoveryProject(User $user, array $attributes = []): KaraokeProject
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
    ], $attributes));
}

function queueProjectForRecovery(User $user): KaraokeProject
{
    $project = createRecoveryProject($user);
    app(KaraokeProcessingStateService::class)->queueForProcessing($project);

    return $project->fresh();
}

function consumedUsageCount(User $user): int
{
    return KaraokeUsageRecord::query()
        ->where('user_id', $user->id)
        ->where('state', KaraokeUsageRecord::STATE_CONSUMED)
        ->count();
}

beforeEach(function () {
    Http::preventStrayRequests();

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
    Config::set('karoks.processing.enabled', true);
    Config::set('karoks.usage.default_monthly_limit', 100);
    Config::set('karoks.processing.recovery_queued_minutes', 10);
    Config::set('karoks.processing.recovery_processing_minutes', 15);
});

describe('karoks:recover-stalled-processing', function () {
    it('performs no writes or dispatches during dry-run', function () {
        $user = recoveryUser();
        $project = queueProjectForRecovery($user);
        $originalRunId = $project->processing_run_id;
        $originalAttempts = $project->processing_attempts;

        $project->forceFill(['processing_heartbeat_at' => now()->subMinutes(20)])->save();
        $originalHeartbeat = $project->fresh()->processing_heartbeat_at?->toIso8601String();

        Queue::fake();

        Artisan::call('karoks:recover-stalled-processing', ['--dry-run' => true]);

        $project->refresh();
        expect($project->processing_run_id)->toBe($originalRunId)
            ->and($project->processing_heartbeat_at?->toIso8601String())->toBe($originalHeartbeat)
            ->and($project->processing_attempts)->toBe($originalAttempts)
            ->and($project->status)->toBe(KaraokeProjectStatus::Queued);

        Queue::assertNothingPushed();
    });

    it('re-dispatches stale queued projects with the same run id', function () {
        Queue::fake();

        $user = recoveryUser();
        $project = queueProjectForRecovery($user);
        $runId = (string) $project->processing_run_id;

        $project->forceFill(['processing_heartbeat_at' => now()->subMinutes(20)])->save();

        Artisan::call('karoks:recover-stalled-processing');

        Queue::assertPushed(ProcessKaraokeProject::class, function (ProcessKaraokeProject $job) use ($project, $runId): bool {
            return $job->karaokeProjectId === $project->id
                && $job->processingRunId === $runId;
        });

        expect($project->fresh()->processing_run_id)->toBe($runId);
    });

    it('does not reserve or consume additional usage during queued recovery', function () {
        Queue::fake();

        $user = recoveryUser();
        $project = queueProjectForRecovery($user);

        expect(KaraokeUsageRecord::query()->where('user_id', $user->id)->where('state', KaraokeUsageRecord::STATE_RESERVED)->count())->toBe(1);

        $project->forceFill(['processing_heartbeat_at' => now()->subMinutes(20)])->save();

        Artisan::call('karoks:recover-stalled-processing');

        expect(KaraokeUsageRecord::query()->where('user_id', $user->id)->where('state', KaraokeUsageRecord::STATE_RESERVED)->count())->toBe(1)
            ->and(KaraokeUsageRecord::query()->where('user_id', $user->id)->count())->toBe(1)
            ->and($project->fresh()->processing_attempts)->toBe(1);
    });

    it('marks stale processing projects as manually retryable processing_stalled failures', function () {
        $user = recoveryUser();
        $project = queueProjectForRecovery($user);
        $runId = (string) $project->processing_run_id;
        $state = app(KaraokeProcessingStateService::class);

        $state->beginProcessingRun($project, $runId);
        $project->refresh();

        expect(consumedUsageCount($user))->toBe(1);

        $project->forceFill([
            'processing_heartbeat_at' => now()->subMinutes(30),
            'progress' => 40,
        ])->save();

        Artisan::call('karoks:recover-stalled-processing');

        $project->refresh();
        expect($project->status)->toBe(KaraokeProjectStatus::Failed)
            ->and($project->error_code)->toBe('processing_stalled')
            ->and($project->processing_retryable)->toBeTrue()
            ->and($state->isRetryable($project))->toBeTrue()
            ->and($project->processing_heartbeat_at)->toBeNull();
    });

    it('keeps consumed usage after stalled recovery', function () {
        $user = recoveryUser();
        $project = queueProjectForRecovery($user);
        $runId = (string) $project->processing_run_id;

        app(KaraokeProcessingStateService::class)->beginProcessingRun($project, $runId);
        expect(consumedUsageCount($user))->toBe(1);

        $project->forceFill(['processing_heartbeat_at' => now()->subMinutes(30)])->save();

        Artisan::call('karoks:recover-stalled-processing');

        expect(consumedUsageCount($user))->toBe(1);
    });

    it('ignores projects with fresh heartbeats', function () {
        $user = recoveryUser();
        $project = queueProjectForRecovery($user);
        $project->forceFill(['processing_heartbeat_at' => now()])->save();

        Queue::fake();

        Artisan::call('karoks:recover-stalled-processing');

        Queue::assertNothingPushed();
        expect($project->fresh()->status)->toBe(KaraokeProjectStatus::Queued);
    });

    it('ignores terminal and superseded projects', function () {
        Queue::fake();

        $user = recoveryUser();
        $completed = createRecoveryProject($user, [
            'status' => KaraokeProjectStatus::Completed,
            'processing_run_id' => (string) Str::uuid(),
            'processing_heartbeat_at' => now()->subMinutes(30),
        ]);
        $failed = createRecoveryProject($user, [
            'status' => KaraokeProjectStatus::Failed,
            'processing_run_id' => (string) Str::uuid(),
            'processing_heartbeat_at' => now()->subMinutes(30),
            'error_code' => 'processing_failed',
        ]);
        $uploaded = createRecoveryProject($user, [
            'status' => KaraokeProjectStatus::Uploaded,
            'processing_heartbeat_at' => now()->subMinutes(30),
        ]);

        Artisan::call('karoks:recover-stalled-processing');

        Queue::assertNothingPushed();
        expect($completed->fresh()->status)->toBe(KaraokeProjectStatus::Completed)
            ->and($failed->fresh()->status)->toBe(KaraokeProjectStatus::Failed)
            ->and($uploaded->fresh()->status)->toBe(KaraokeProjectStatus::Uploaded);
    });

    it('is idempotent across repeated recovery runs', function () {
        $user = recoveryUser();
        $project = queueProjectForRecovery($user);
        $project->forceFill(['processing_heartbeat_at' => now()->subMinutes(20)])->save();

        Queue::fake();

        Artisan::call('karoks:recover-stalled-processing');
        Artisan::call('karoks:recover-stalled-processing');

        Queue::assertPushed(ProcessKaraokeProject::class, 1);
    });

    it('creates exactly one stalled notification and remains idempotent', function () {
        $user = recoveryUser();
        $project = queueProjectForRecovery($user);
        $runId = (string) $project->processing_run_id;

        app(KaraokeProcessingStateService::class)->beginProcessingRun($project, $runId);
        $project->forceFill(['processing_heartbeat_at' => now()->subMinutes(30)])->save();

        Artisan::call('karoks:recover-stalled-processing');
        Artisan::call('karoks:recover-stalled-processing');

        expect($user->notifications()->where('type', KaraokeProcessingNotification::class)->count())->toBe(1);
    });

    it('prevents an old worker from completing after stalled recovery', function () {
        $user = recoveryUser();
        $project = queueProjectForRecovery($user);
        $runId = (string) $project->processing_run_id;
        $state = app(KaraokeProcessingStateService::class);

        $state->beginProcessingRun($project, $runId);
        $project->forceFill(['processing_heartbeat_at' => now()->subMinutes(30)])->save();

        Artisan::call('karoks:recover-stalled-processing');
        expect($project->fresh()->status)->toBe(KaraokeProjectStatus::Failed);

        $instrumentalPath = $project->storageDirectory().'/instrumental.wav';
        Storage::disk('local')->put($instrumentalPath, file_get_contents(base_path('tests/fixtures/sample.wav')));

        $result = new KaraokeProcessingResult(
            instrumentalPath: $instrumentalPath,
            instrumentalMimeType: 'audio/wav',
            transcript: json_decode((string) file_get_contents(database_path('fixtures/karoks-demo-transcript.json')), true),
            theme: KaraokeThemeParser::parse([]),
            disclosure: 'Simulated processing completed.',
        );

        expect($state->markCompleted($project->fresh(), $runId, $result))->toBeFalse();
        expect($project->fresh()->status)->toBe(KaraokeProjectStatus::Failed);

        runKaraokeProcessingJob($project->fresh());
        expect($project->fresh()->status)->toBe(KaraokeProjectStatus::Failed);
    });

    it('still reconciles stalled projects when the processing kill switch is enabled', function () {
        $user = recoveryUser();
        $project = queueProjectForRecovery($user);
        $runId = (string) $project->processing_run_id;

        app(KaraokeProcessingStateService::class)->beginProcessingRun($project, $runId);
        $project->forceFill(['processing_heartbeat_at' => now()->subMinutes(30)])->save();

        Config::set('karoks.processing.enabled', false);

        Artisan::call('karoks:recover-stalled-processing');

        expect($project->fresh()->status)->toBe(KaraokeProjectStatus::Failed)
            ->and($project->fresh()->error_code)->toBe('processing_stalled');
    });
});
