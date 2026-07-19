<?php

use App\Enums\KaraokeProcessingNotificationEvent;
use App\Enums\KaraokeProjectStatus;
use App\Models\KaraokeProject;
use App\Models\User;
use App\Support\Karaoke\Processing\KaraokeAudioDurationInspector;
use App\Support\Karaoke\Processing\KaraokeProcessingCheckpointService;
use App\Support\KaraokeProcessingStateService;
use DevDojo\Themes\Models\Theme;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\KaraokeTestTheme;

use function Tests\Support\runKaraokeProcessingJob;

uses(DatabaseTransactions::class);

function checkpointUser(): User
{
    return User::factory()->create(['verified' => 1]);
}

function createCheckpointProject(User $user, array $attributes = []): KaraokeProject
{
    $publicId = (string) Str::uuid();
    $path = 'karaoke/'.$user->id.'/'.$publicId.'/source.wav';
    $audioBytes = buildCheckpointWavBytes();
    Storage::disk('local')->put($path, $audioBytes);
    $duration = app(KaraokeAudioDurationInspector::class)->inspectFile(
        Storage::disk('local')->path($path),
        'audio/wav',
    );

    return KaraokeProject::factory()->create(array_merge([
        'user_id' => $user->id,
        'public_id' => $publicId,
        'source_path' => $path,
        'mime_type' => 'audio/wav',
        'size_bytes' => strlen($audioBytes),
        'duration_seconds' => $duration['readable'] ? $duration['duration_seconds'] : null,
        'status' => KaraokeProjectStatus::Processing,
        'processing_driver' => 'real',
        'processing_attempts' => 1,
    ], $attributes));
}

function buildCheckpointWavBytes(int $durationSeconds = 2, int $sampleRate = 44100): string
{
    $numSamples = $durationSeconds * $sampleRate;
    $dataSize = $numSamples * 2;
    $byteRate = $sampleRate * 2;

    $header = 'RIFF'
        .pack('V', 36 + $dataSize)
        .'WAVEfmt '
        .pack('V', 16)
        .pack('v', 1)
        .pack('v', 1)
        .pack('V', $sampleRate)
        .pack('V', $byteRate)
        .pack('v', 2)
        .pack('v', 16)
        .'data'
        .pack('V', $dataSize);

    return $header.str_repeat("\0", $dataSize);
}

function boundCheckpointProject(User $user, ?string $runId = null): KaraokeProject
{
    $runId ??= (string) Str::uuid();
    $project = createCheckpointProject($user, [
        'processing_run_id' => $runId,
    ]);
    app(KaraokeProcessingCheckpointService::class)->bindRun($project, $runId, 'real');

    return $project->fresh();
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
    Config::set('karoks.processing.driver', 'real');
    Config::set('karoks.processing.enabled', true);
    Config::set('karoks.providers.wavespeed.api_key', 'test-wavespeed-key');
    Config::set('karoks.providers.elevenlabs.api_key', 'test-elevenlabs-key');
});

describe('stale checkpoint writes after cancellation', function () {
    it('rejects bindRun after cancellation', function () {
        $project = boundCheckpointProject(checkpointUser());
        $runId = (string) $project->processing_run_id;
        $checkpoints = app(KaraokeProcessingCheckpointService::class);

        app(KaraokeProcessingStateService::class)->cancelProcessing($project->fresh());

        $checkpoints->bindRun($project->fresh(), $runId, 'real');

        $project->refresh();
        expect($project->status)->toBe(KaraokeProjectStatus::Cancelled)
            ->and($project->provider_checkpoint_run_id)->toBeNull();
    });

    it('rejects all checkpoint writes after cancellation', function () {
        $project = boundCheckpointProject(checkpointUser());
        $runId = (string) $project->processing_run_id;
        $checkpoints = app(KaraokeProcessingCheckpointService::class);

        app(KaraokeProcessingStateService::class)->cancelProcessing($project->fresh());

        $checkpoints->savePredictionId($project->fresh(), $runId, 'stale-prediction');
        $checkpoints->markSeparationCompleted($project->fresh(), $runId);
        $checkpoints->saveTranscriptCheckpoint($project->fresh(), $runId, ['version' => 1, 'lines' => []]);

        $project->refresh();
        expect($project->wavespeed_prediction_id)->toBeNull()
            ->and($project->provider_separation_completed_at)->toBeNull()
            ->and($project->provider_transcript_checkpoint)->toBeNull();
    });
});

describe('stale checkpoint writes after stalled recovery', function () {
    it('rejects bindRun and provider checkpoint writes after stalled recovery', function () {
        $project = boundCheckpointProject(checkpointUser());
        $runId = (string) $project->processing_run_id;
        $checkpoints = app(KaraokeProcessingCheckpointService::class);
        $state = app(KaraokeProcessingStateService::class);

        expect($state->markFailed(
            $project->fresh(),
            $runId,
            'processing_stalled',
            'Processing stalled while waiting for the provider. Please try again.',
            retryable: true,
            notificationEvent: KaraokeProcessingNotificationEvent::Stalled,
        ))->toBeTrue();

        $checkpoints->bindRun($project->fresh(), $runId, 'real');
        $checkpoints->savePredictionId($project->fresh(), $runId, 'stale-prediction');
        $checkpoints->markSeparationCompleted($project->fresh(), $runId);
        $checkpoints->saveTranscriptCheckpoint($project->fresh(), $runId, ['version' => 1, 'lines' => []]);

        $project->refresh();
        expect($project->status)->toBe(KaraokeProjectStatus::Failed)
            ->and($project->error_code)->toBe('processing_stalled')
            ->and($project->wavespeed_prediction_id)->toBeNull()
            ->and($project->provider_checkpoint_run_id)->toBeNull()
            ->and($project->provider_transcript_checkpoint)->toBeNull();
    });

    it('does not reuse resurrected checkpoints on the next retry with the same driver', function () {
        configureRealProcessing();
        Config::set('karoks.providers.allowed_media_host_suffixes', ['example.test']);
        Http::preventStrayRequests();

        $isolatorCalls = 0;

        Http::fake(function (Request $request) use (&$isolatorCalls) {
            $url = $request->url();

            if ($url === 'https://api.wavespeed.ai/api/v3/media/upload/binary') {
                return Http::response([
                    'code' => 200,
                    'data' => ['download_url' => 'https://uploads.example.test/source.wav'],
                ], 200);
            }

            if ($url === 'https://api.wavespeed.ai/api/v3/wavespeed-ai/audio-vocal-isolator') {
                $isolatorCalls++;

                return Http::response([
                    'code' => 200,
                    'data' => ['id' => 'pred-retry-1'],
                ], 200);
            }

            if ($url === 'https://api.wavespeed.ai/api/v3/predictions/pred-retry-1/result') {
                return Http::response([
                    'code' => 200,
                    'data' => [
                        'id' => 'pred-retry-1',
                        'status' => 'completed',
                        'outputs' => [
                            'https://cdn.example.test/vocal.mp3',
                            'https://cdn.example.test/instrumental.mp3',
                        ],
                    ],
                ], 200);
            }

            if ($url === 'https://api.elevenlabs.io/v1/speech-to-text') {
                return Http::response([
                    'words' => [
                        ['type' => 'word', 'text' => 'hello', 'start' => 0.0, 'end' => 0.5],
                    ],
                ], 200);
            }

            if ($url === 'https://cdn.example.test/vocal.mp3' || $url === 'https://cdn.example.test/instrumental.mp3') {
                return Http::response(buildCheckpointWavBytes(), 200, ['Content-Type' => 'audio/mpeg']);
            }

            return Http::response('unexpected', 500);
        });

        $user = checkpointUser();
        $project = createCheckpointProject($user, ['status' => KaraokeProjectStatus::Uploaded]);
        $state = app(KaraokeProcessingStateService::class);
        $checkpoints = app(KaraokeProcessingCheckpointService::class);

        $state->queueForProcessing($project, true);
        $project = $project->fresh();
        $runId = (string) $project->processing_run_id;
        $state->beginProcessingRun($project, $runId);
        $checkpoints->bindRun($project->fresh(), $runId, 'real');

        expect($state->markFailed(
            $project->fresh(),
            $runId,
            'processing_stalled',
            'Processing stalled while waiting for the provider. Please try again.',
            retryable: true,
            notificationEvent: KaraokeProcessingNotificationEvent::Stalled,
        ))->toBeTrue();

        $checkpoints->savePredictionId($project->fresh(), $runId, 'stale-resurrected-prediction');
        $checkpoints->saveTranscriptCheckpoint($project->fresh(), $runId, ['version' => 1, 'lines' => [['id' => 'line-1', 'start' => 0, 'end' => 1, 'words' => []]]]);

        expect($project->fresh()->wavespeed_prediction_id)->toBeNull()
            ->and($checkpoints->hasReusableSeparation($project->fresh()))->toBeFalse()
            ->and($checkpoints->reusableTranscript($project->fresh()))->toBeNull();

        $state->retryProcessing($project->fresh());
        runKaraokeProcessingJob($project->fresh());

        expect($isolatorCalls)->toBe(1)
            ->and($project->fresh()->status)->toBe(KaraokeProjectStatus::Completed);
    });
});

describe('checkpoint identity requirements', function () {
    it('rejects provider writes before bindRun establishes checkpoint identity', function () {
        $runId = (string) Str::uuid();
        $project = createCheckpointProject(checkpointUser(), [
            'processing_run_id' => $runId,
        ]);
        $checkpoints = app(KaraokeProcessingCheckpointService::class);

        $checkpoints->savePredictionId($project, $runId, 'pred-unbound');
        $checkpoints->markSeparationCompleted($project, $runId);
        $checkpoints->saveTranscriptCheckpoint($project, $runId, ['version' => 1, 'lines' => []]);

        $project->refresh();
        expect($project->wavespeed_prediction_id)->toBeNull()
            ->and($project->provider_separation_completed_at)->toBeNull()
            ->and($project->provider_transcript_checkpoint)->toBeNull();
    });

    it('rejects writes when checkpoint attempt does not match processing attempts', function () {
        $runId = (string) Str::uuid();
        $project = createCheckpointProject(checkpointUser(), [
            'processing_run_id' => $runId,
            'processing_attempts' => 2,
            'provider_checkpoint_run_id' => $runId,
            'provider_checkpoint_attempt' => 1,
        ]);
        $checkpoints = app(KaraokeProcessingCheckpointService::class);

        $checkpoints->savePredictionId($project, $runId, 'pred-mismatch');

        expect($project->fresh()->wavespeed_prediction_id)->toBeNull();
    });
});
