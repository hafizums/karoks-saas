<?php

use App\Contracts\KaraokeProcessor;
use App\Enums\KaraokeProcessingNotificationEvent;
use App\Enums\KaraokeProcessingStage;
use App\Enums\KaraokeProjectStatus;
use App\Exceptions\KaraokeProcessingException;
use App\Exceptions\KaraokeProviderProcessingException;
use App\Jobs\ProcessKaraokeProject;
use App\Models\KaraokeProject;
use App\Models\KaroksProcessingNotificationDelivery;
use App\Models\User;
use App\Notifications\KaraokeProcessingNotification;
use App\Support\KaraokeProcessingResult;
use App\Support\KaraokeProcessingStateService;
use App\Support\KaraokeThemeParser;
use DevDojo\Themes\Models\Theme;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FlakyKaraokeProcessor;
use Tests\Support\KaraokeTestTheme;

use function Tests\Support\bindMockProcessingProcessor;
use function Tests\Support\runKaraokeProcessingJob;

uses(DatabaseTransactions::class);

function notificationUser(): User
{
    return User::factory()->create(['verified' => 1]);
}

function createNotificationProject(User $user, array $attributes = []): KaraokeProject
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

function karoksNotificationsFor(User $user)
{
    return $user->notifications()->where('type', KaraokeProcessingNotification::class);
}

function karoksNotificationPayload(User $user): array
{
    $notification = karoksNotificationsFor($user)->first();

    expect($notification)->not->toBeNull();

    return $notification->data;
}

function assertSafeKaroksNotificationPayload(array $payload, KaraokeProject $project): void
{
    $encoded = json_encode($payload);

    expect($payload)->toHaveKeys(['event_type', 'project_public_id', 'body', 'link'])
        ->and($payload['project_public_id'])->toBe($project->public_id)
        ->and($payload)->not->toHaveKeys([
            'id',
            'project_id',
            'user_id',
            'karaoke_project_id',
            'source_path',
            'instrumental_path',
            'prediction_id',
            'transcript',
        ])
        ->and($encoded)->not->toMatch('/"project_id"\s*:/')
        ->and($encoded)->not->toMatch('/"user_id"\s*:/')
        ->and($encoded)->not->toMatch('/"karaoke_project_id"\s*:/')
        ->and($encoded)->not->toMatch('/karaoke\/\d+\//')
        ->and($encoded)->not->toMatch('/https?:\/\//')
        ->and($encoded)->not->toContain('prediction')
        ->and($encoded)->not->toContain('api_key')
        ->and($encoded)->not->toContain('WAVESPEED')
        ->and($encoded)->not->toContain('ELEVENLABS');
}

function instrumentalPathFor(KaraokeProject $project): string
{
    return $project->storageDirectory().'/instrumental.wav';
}

function demoTranscript(): array
{
    return json_decode(
        (string) file_get_contents(database_path('fixtures/karoks-demo-transcript.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

function demoProcessingResult(KaraokeProject $project, string $disclosure = 'Simulated processing completed.'): KaraokeProcessingResult
{
    $instrumentalPath = instrumentalPathFor($project);
    Storage::disk('local')->put($instrumentalPath, file_get_contents(base_path('tests/fixtures/sample.wav')));

    return new KaraokeProcessingResult(
        instrumentalPath: $instrumentalPath,
        instrumentalMimeType: 'audio/wav',
        transcript: demoTranscript(),
        theme: KaraokeThemeParser::parse([]),
        disclosure: $disclosure,
    );
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
});

describe('processing heartbeat', function () {
    it('sets heartbeat when a project is queued', function () {
        $user = notificationUser();
        $project = createNotificationProject($user);
        $state = app(KaraokeProcessingStateService::class);

        $state->queueForProcessing($project);
        $project->refresh();

        expect($project->status)->toBe(KaraokeProjectStatus::Queued)
            ->and($project->processing_heartbeat_at)->not->toBeNull();
    });

    it('refreshes heartbeat when processing begins and progress is recorded', function () {
        $user = notificationUser();
        $project = createNotificationProject($user);
        $state = app(KaraokeProcessingStateService::class);

        $state->queueForProcessing($project);
        $project->refresh();
        $runId = (string) $project->processing_run_id;
        $queuedHeartbeat = $project->processing_heartbeat_at;

        $this->travel(2)->seconds();

        expect($state->beginProcessingRun($project, $runId))->toBeTrue();
        $project->refresh();
        expect($project->processing_heartbeat_at)->not->toEqual($queuedHeartbeat);

        $beginHeartbeat = $project->processing_heartbeat_at;
        $this->travel(2)->seconds();

        expect($state->recordProgress($project, $runId, KaraokeProcessingStage::Separating, 40))->toBeTrue();
        $project->refresh();
        expect($project->processing_heartbeat_at)->not->toEqual($beginHeartbeat);
    });

    it('does not refresh heartbeat for stale or superseded runs', function () {
        $user = notificationUser();
        $project = createNotificationProject($user, [
            'status' => KaraokeProjectStatus::Processing,
            'processing_run_id' => 'active-run',
            'processing_heartbeat_at' => now()->subMinutes(5),
        ]);
        $state = app(KaraokeProcessingStateService::class);
        $originalHeartbeat = $project->processing_heartbeat_at?->toIso8601String();

        expect($state->recordProgress($project, 'stale-run', KaraokeProcessingStage::Separating, 40))->toBeFalse();
        expect($state->refreshHeartbeat($project, 'stale-run'))->toBeFalse();

        $project->refresh();
        expect($project->processing_heartbeat_at?->toIso8601String())->toBe($originalHeartbeat);
    });

    it('clears heartbeat when a project reaches a terminal state', function () {
        $user = notificationUser();
        $project = createNotificationProject($user);
        $state = app(KaraokeProcessingStateService::class);

        $state->queueForProcessing($project);
        $project->refresh();
        $runId = (string) $project->processing_run_id;

        $state->beginProcessingRun($project, $runId);
        $project->refresh();

        $result = demoProcessingResult($project);

        expect($state->markCompleted($project, $runId, $result))->toBeTrue();
        expect($project->fresh()->processing_heartbeat_at)->toBeNull();

        $failedProject = createNotificationProject($user);
        $state->queueForProcessing($failedProject);
        $failedProject->refresh();
        $failedRunId = (string) $failedProject->processing_run_id;
        $state->beginProcessingRun($failedProject, $failedRunId);

        expect($state->markFailed($failedProject, $failedRunId, 'processing_failed', 'Safe failure message.'))->toBeTrue();
        expect($failedProject->fresh()->processing_heartbeat_at)->toBeNull();

        $cancelledProject = createNotificationProject($user);
        $state->queueForProcessing($cancelledProject);
        expect($state->cancelProcessing($cancelledProject->fresh()))->toBeTrue();
        expect($cancelledProject->fresh()->processing_heartbeat_at)->toBeNull();
    });
});

describe('terminal database notifications', function () {
    it('creates exactly one completed notification', function () {
        $user = notificationUser();
        $project = createNotificationProject($user);
        $state = app(KaraokeProcessingStateService::class);

        $state->queueForProcessing($project);
        runKaraokeProcessingJob($project->fresh());

        expect(karoksNotificationsFor($user)->count())->toBe(1);
        expect(KaroksProcessingNotificationDelivery::query()->where('karaoke_project_id', $project->id)->count())->toBe(1);

        $payload = karoksNotificationPayload($user);
        expect($payload['event_type'])->toBe('completed')
            ->and($payload['simulated_processing'])->toBeTrue()
            ->and($payload['link'])->toContain($project->public_id);

        assertSafeKaroksNotificationPayload($payload, $project->fresh());
    });

    it('creates exactly one failed notification', function () {
        $user = notificationUser();
        $project = createNotificationProject($user);
        $state = app(KaraokeProcessingStateService::class);

        $state->queueForProcessing($project);
        $project->refresh();
        $runId = (string) $project->processing_run_id;
        $state->beginProcessingRun($project, $runId);

        expect($state->markFailed($project, $runId, 'processing_failed', 'Processing could not be completed. Please try again.'))->toBeTrue();

        expect(karoksNotificationsFor($user)->count())->toBe(1);
        expect(karoksNotificationPayload($user)['event_type'])->toBe('failed');
    });

    it('creates exactly one cancelled notification', function () {
        $user = notificationUser();
        $project = createNotificationProject($user);
        $state = app(KaraokeProcessingStateService::class);

        $state->queueForProcessing($project);
        expect($state->cancelProcessing($project->fresh()))->toBeTrue();

        expect(karoksNotificationsFor($user)->count())->toBe(1);
        expect(karoksNotificationPayload($user)['event_type'])->toBe('cancelled');
    });

    it('does not notify on queue-retryable provider failures', function () {
        $user = notificationUser();
        $project = createNotificationProject($user);
        $state = app(KaraokeProcessingStateService::class);

        $state->queueForProcessing($project);
        $project->refresh();
        $runId = (string) $project->processing_run_id;
        $job = new ProcessKaraokeProject($project->id, $runId);

        bindMockProcessingProcessor(new class() implements KaraokeProcessor
        {
            public function process(KaraokeProject $project, string $processingRunId, Closure $reportProgress): KaraokeProcessingResult
            {
                throw new KaraokeProviderProcessingException(
                    errorCode: 'provider_rate_limited',
                    userMessage: 'Provider is busy. Please try again.',
                    queueRetryable: true,
                    manualRetryable: true,
                );
            }
        });

        $state->beginProcessingRun($project, $runId);

        expect(fn () => $job->handle($state))->toThrow(KaraokeProviderProcessingException::class);
        expect($project->fresh()->status)->toBe(KaraokeProjectStatus::Processing);
        expect(karoksNotificationsFor($user)->count())->toBe(0);
    });

    it('creates one failed notification after retryable attempts are exhausted', function () {
        $user = notificationUser();
        $project = createNotificationProject($user);
        $state = app(KaraokeProcessingStateService::class);

        bindMockProcessingProcessor(new FlakyKaraokeProcessor(failuresBeforeSuccess: 99));

        $state->queueForProcessing($project);
        $project->refresh();
        $runId = (string) $project->processing_run_id;
        $job = new ProcessKaraokeProject($project->id, $runId);

        foreach ([1, 2, 3] as $attempt) {
            try {
                $job->handle($state);
            } catch (KaraokeProcessingException) {
                expect($attempt)->toBeLessThanOrEqual(3);
            }
        }

        $job->failed(new KaraokeProcessingException('Transient processing failure.'));

        expect($project->fresh()->status)->toBe(KaraokeProjectStatus::Failed);
        expect(karoksNotificationsFor($user)->count())->toBe(1);
        expect(karoksNotificationPayload($user)['event_type'])->toBe('failed');
    });

    it('does not duplicate notifications for duplicate terminal callbacks', function () {
        $user = notificationUser();
        $project = createNotificationProject($user);
        $state = app(KaraokeProcessingStateService::class);

        $state->queueForProcessing($project);
        $project->refresh();
        $runId = (string) $project->processing_run_id;
        $state->beginProcessingRun($project, $runId);

        expect($state->markFailed($project, $runId, 'processing_failed', 'Processing could not be completed. Please try again.'))->toBeTrue();
        expect($state->markFailed($project, $runId, 'processing_failed', 'Processing could not be completed. Please try again.'))->toBeFalse();
        expect(karoksNotificationsFor($user)->count())->toBe(1);
        expect(KaroksProcessingNotificationDelivery::query()->where('karaoke_project_id', $project->id)->count())->toBe(1);
    });

    it('uses captured driver for mock versus real disclosure', function () {
        $user = notificationUser();
        $project = createNotificationProject($user, [
            'processing_driver' => 'real',
        ]);
        $state = app(KaraokeProcessingStateService::class);

        $state->queueForProcessing($project);
        $project->refresh();
        $runId = (string) $project->processing_run_id;
        $state->beginProcessingRun($project, $runId);

        $result = demoProcessingResult($project, 'External provider processing completed.');

        $project->forceFill(['processing_driver' => 'real'])->save();
        expect($state->markCompleted($project, $runId, $result))->toBeTrue();

        $payload = karoksNotificationPayload($user);
        expect($payload['processing_driver'])->toBe('real')
            ->and($payload['simulated_processing'])->toBeFalse()
            ->and($payload['body'])->toContain('external providers');
    });

    it('blocks cross-user notification access', function () {
        $owner = notificationUser();
        $other = notificationUser();
        $project = createNotificationProject($owner);
        $state = app(KaraokeProcessingStateService::class);

        $state->queueForProcessing($project);
        runKaraokeProcessingJob($project->fresh());

        $notificationId = karoksNotificationsFor($owner)->first()->id;

        $this->actingAs($other)
            ->postJson(route('wave.notification.read', ['id' => $notificationId]))
            ->assertJson([
                'type' => 'error',
                'message' => 'Could not find the specified notification.',
            ]);

        expect(karoksNotificationsFor($owner)->count())->toBe(1);
    });

    it('cleans up project notifications and idempotency records on project deletion', function () {
        $user = notificationUser();
        $project = createNotificationProject($user);
        $state = app(KaraokeProcessingStateService::class);

        $state->queueForProcessing($project);
        runKaraokeProcessingJob($project->fresh());

        expect(karoksNotificationsFor($user)->count())->toBe(1);
        expect(KaroksProcessingNotificationDelivery::query()->where('karaoke_project_id', $project->id)->count())->toBe(1);

        $project->delete();

        expect(karoksNotificationsFor($user)->count())->toBe(0);
        expect(KaroksProcessingNotificationDelivery::query()->where('project_public_id', $project->public_id)->count())->toBe(0);
    });

    it('cleans up karoks notification data when a user is force deleted', function () {
        $user = notificationUser();
        $project = createNotificationProject($user);
        $state = app(KaraokeProcessingStateService::class);

        $state->queueForProcessing($project);
        runKaraokeProcessingJob($project->fresh());

        expect(KaroksProcessingNotificationDelivery::query()->where('user_id', $user->id)->count())->toBe(1);
        expect(karoksNotificationsFor($user)->count())->toBe(1);

        $userId = $user->id;
        $projectId = $project->id;
        $project->delete();
        $user->forceDelete();

        expect(KaroksProcessingNotificationDelivery::query()->where('user_id', $userId)->count())->toBe(0);
        expect(KaroksProcessingNotificationDelivery::query()->where('karaoke_project_id', $projectId)->count())->toBe(0);
        expect(DB::table('notifications')
            ->where('notifiable_id', $userId)
            ->where('type', KaraokeProcessingNotification::class)
            ->count())->toBe(0);
    });
});

describe('stalled notification via recovery', function () {
    it('creates one stalled notification with safe payload', function () {
        $user = notificationUser();
        $project = createNotificationProject($user, [
            'status' => KaraokeProjectStatus::Processing,
            'processing_run_id' => (string) Str::uuid(),
            'processing_driver' => 'mock',
            'processing_attempts' => 1,
            'processing_started_at' => now()->subHour(),
            'processing_heartbeat_at' => now()->subMinutes(30),
            'progress' => 25,
        ]);
        $state = app(KaraokeProcessingStateService::class);
        $runId = (string) $project->processing_run_id;

        expect($state->markFailed(
            $project,
            $runId,
            'processing_stalled',
            'Processing stalled while waiting for the provider. Please try again.',
            retryable: true,
            notificationEvent: KaraokeProcessingNotificationEvent::Stalled,
        ))->toBeTrue();

        expect(karoksNotificationsFor($user)->count())->toBe(1);

        $payload = karoksNotificationPayload($user);
        expect($payload['event_type'])->toBe('processing_stalled')
            ->and($payload['error_code'])->toBe('processing_stalled')
            ->and($payload['status'])->toBe('failed');

        assertSafeKaroksNotificationPayload($payload, $project->fresh());
    });
});
