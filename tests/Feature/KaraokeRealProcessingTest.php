<?php

use App\Contracts\KaraokeProcessor;
use App\Enums\KaraokeProjectStatus;
use App\Exceptions\KaraokeProcessingGateException;
use App\Exceptions\KaraokeProviderProcessingException;
use App\Exceptions\UnsupportedKaroksProcessingDriverException;
use App\Jobs\ProcessKaraokeProject;
use App\Models\KaraokeProject;
use App\Models\KaraokeUsageRecord;
use App\Models\User;
use App\Support\Karaoke\Processing\KaraokeAudioDurationInspector;
use App\Support\Karaoke\Processors\MockKaraokeProcessor;
use App\Support\Karaoke\Processors\RealKaraokeProcessor;
use App\Support\Karaoke\Providers\KaraokeTranscriptNormalizer;
use App\Support\Karaoke\Providers\SafeProviderMediaDownloader;
use App\Support\KaraokeProcessingStateService;
use App\Support\KaraokeProcessorManager;
use App\Support\KaraokeStorage;
use App\Support\KaraokeTranscriptParser;
use DevDojo\Themes\Models\Theme;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\KaraokeTestTheme;

uses(DatabaseTransactions::class);

function realProcessingUser(): User
{
    return User::factory()->create(['verified' => 1]);
}

function buildTestWavBytes(int $durationSeconds = 2, int $sampleRate = 44100): string
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

function realProcessingWavBytes(): string
{
    return buildTestWavBytes(2);
}

function createRealProcessingProject(User $user, array $attributes = []): KaraokeProject
{
    $publicId = (string) Str::uuid();
    $path = 'karaoke/'.$user->id.'/'.$publicId.'/source.wav';
    $audioBytes = realProcessingWavBytes();
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
        'status' => KaraokeProjectStatus::Uploaded,
    ], $attributes));
}

function configureRealProcessing(array $overrides = []): void
{
    Config::set('karoks.processing.driver', 'real');
    Config::set('karoks.processing.enabled', true);
    Config::set('karoks.processing.max_audio_duration_seconds', 720);
    Config::set('karoks.providers.poll_interval_seconds', 1);
    Config::set('karoks.providers.poll_timeout_seconds', 30);
    Config::set('karoks.providers.wavespeed.api_key', 'test-wavespeed-key');
    Config::set('karoks.providers.elevenlabs.api_key', 'test-elevenlabs-key');
    Config::set('karoks.usage.default_monthly_limit', 100);

    foreach ($overrides as $key => $value) {
        Config::set($key, $value);
    }
}

/**
 * @return array<string, mixed>
 */
function fakeElevenLabsWord(string $text, float $start, float $end): array
{
    return ['type' => 'word', 'text' => $text, 'start' => $start, 'end' => $end];
}

function fakeProviderHttp(string $vocalDownloadHost = 'cdn.example.test', string $instrumentalDownloadHost = 'cdn.example.test'): void
{
    Http::fake(function (Request $request) use ($vocalDownloadHost, $instrumentalDownloadHost) {
        $url = $request->url();

        if ($url === 'https://api.wavespeed.ai/api/v3/media/upload/binary') {
            return Http::response([
                'code' => 200,
                'data' => ['download_url' => 'https://uploads.example.test/source.wav'],
            ], 200);
        }

        if ($url === 'https://api.wavespeed.ai/api/v3/wavespeed-ai/audio-vocal-isolator') {
            return Http::response([
                'code' => 200,
                'data' => ['id' => 'pred-test-123'],
            ], 200);
        }

        if ($url === 'https://api.wavespeed.ai/api/v3/predictions/pred-test-123/result') {
            return Http::response([
                'code' => 200,
                'data' => [
                    'id' => 'pred-test-123',
                    'status' => 'completed',
                    'outputs' => [
                        'https://'.$vocalDownloadHost.'/vocal.mp3',
                        'https://'.$instrumentalDownloadHost.'/instrumental.mp3',
                    ],
                ],
            ], 200);
        }

        if ($url === 'https://api.elevenlabs.io/v1/speech-to-text') {
            return Http::response([
                'words' => [
                    fakeElevenLabsWord('Hello', 0.2, 0.6),
                    fakeElevenLabsWord('world', 0.7, 1.1),
                ],
            ], 200);
        }

        if ($url === 'https://'.$vocalDownloadHost.'/vocal.mp3') {
            return Http::response(realProcessingWavBytes(), 200, ['Content-Type' => 'audio/mpeg']);
        }

        if ($url === 'https://'.$instrumentalDownloadHost.'/instrumental.mp3') {
            return Http::response(realProcessingWavBytes(), 200, ['Content-Type' => 'audio/mpeg']);
        }

        return Http::response('unexpected host', 500);
    });
}

function runRealProcessingJob(KaraokeProject $project): void
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
    Config::set('karoks.processing.enabled', true);
    Config::set('karoks.usage.default_monthly_limit', 100);
});

it('keeps mock as the default driver', function () {
    expect(config('karoks.processing.driver'))->toBe('mock');
    expect(app(KaraokeProcessorManager::class)->driver('mock'))->toBeInstanceOf(MockKaraokeProcessor::class);
});

it('resolves the real driver only when explicitly selected', function () {
    configureRealProcessing();

    expect(app(KaraokeProcessorManager::class)->driver('real'))->toBeInstanceOf(RealKaraokeProcessor::class);
});

it('never silently falls back from real to mock', function () {
    configureRealProcessing(['karoks.providers.wavespeed.api_key' => '']);

    expect(fn () => app(KaraokeProcessingStateService::class)->queueForProcessing(createRealProcessingProject(realProcessingUser())))
        ->toThrow(KaraokeProcessingGateException::class);
});

it('blocks missing credentials before usage reservation and dispatch', function () {
    configureRealProcessing(['karoks.providers.elevenlabs.api_key' => '']);
    $user = realProcessingUser();
    $project = createRealProcessingProject($user);

    expect(fn () => app(KaraokeProcessingStateService::class)->queueForProcessing($project, true))
        ->toThrow(KaraokeProcessingGateException::class);

    expect(KaraokeUsageRecord::query()->where('user_id', $user->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('blocks missing provider consent before usage reservation and dispatch', function () {
    configureRealProcessing();
    $user = realProcessingUser();
    $project = createRealProcessingProject($user);

    expect(fn () => app(KaraokeProcessingStateService::class)->queueForProcessing($project, false))
        ->toThrow(KaraokeProcessingGateException::class);

    expect(KaraokeUsageRecord::query()->where('user_id', $user->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('does not require provider consent in mock mode', function () {
    Config::set('karoks.processing.driver', 'mock');
    $user = realProcessingUser();
    $project = createRealProcessingProject($user);

    app(KaraokeProcessingStateService::class)->queueForProcessing($project, false);

    Queue::assertPushed(ProcessKaraokeProject::class);
});

it('stores server derived duration on upload gate', function () {
    configureRealProcessing();
    $project = createRealProcessingProject(realProcessingUser());
    $project->forceFill(['duration_seconds' => null])->save();

    app(KaraokeProcessingStateService::class)->queueForProcessing($project, true);

    expect($project->fresh()->duration_seconds)->toBeInt()->toBeGreaterThan(0);
});

it('rejects over limit audio without provider calls', function () {
    configureRealProcessing(['karoks.processing.max_audio_duration_seconds' => 1]);
    Http::preventStrayRequests();
    $user = realProcessingUser();
    $publicId = (string) Str::uuid();
    $path = 'karaoke/'.$user->id.'/'.$publicId.'/source.wav';
    Storage::disk('local')->put($path, buildTestWavBytes(2));
    $project = KaraokeProject::factory()->create([
        'user_id' => $user->id,
        'public_id' => $publicId,
        'source_path' => $path,
        'mime_type' => 'audio/wav',
        'size_bytes' => strlen(buildTestWavBytes(2)),
        'duration_seconds' => 2,
        'status' => KaraokeProjectStatus::Uploaded,
    ]);

    expect(fn () => app(KaraokeProcessingStateService::class)->queueForProcessing($project, true))
        ->toThrow(KaraokeProcessingGateException::class);

    Http::assertNothingSent();
});

it('rejects corrupt audio without provider calls', function () {
    configureRealProcessing();
    Http::preventStrayRequests();
    $user = realProcessingUser();
    $publicId = (string) Str::uuid();
    $path = 'karaoke/'.$user->id.'/'.$publicId.'/source.wav';
    Storage::disk('local')->put($path, 'not-a-wav');
    $project = KaraokeProject::factory()->create([
        'user_id' => $user->id,
        'public_id' => $publicId,
        'source_path' => $path,
        'mime_type' => 'audio/wav',
        'size_bytes' => 9,
        'status' => KaraokeProjectStatus::Uploaded,
    ]);

    expect(fn () => app(KaraokeProcessingStateService::class)->queueForProcessing($project, true))
        ->toThrow(KaraokeProcessingGateException::class);

    Http::assertNothingSent();
});

it('completes real processing with correct provider request flow', function () {
    configureRealProcessing();
    Http::preventStrayRequests();
    fakeProviderHttp();

    $user = realProcessingUser();
    $project = createRealProcessingProject($user);

    app(KaraokeProcessingStateService::class)->queueForProcessing($project, true);
    runRealProcessingJob($project->fresh());

    $project->refresh();

    expect($project->status)->toBe(KaraokeProjectStatus::Completed)
        ->and($project->transcript)->not->toBeNull()
        ->and($project->instrumental_path)->not->toBeNull()
        ->and(KaraokeStorage::disk()->exists($project->instrumental_path))->toBeTrue()
        ->and(KaraokeTranscriptParser::parse($project->transcript))->not->toBeNull()
        ->and($project->wavespeed_prediction_id)->toBe('pred-test-123');

    Http::assertSentCount(6);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.wavespeed.ai/api/v3/media/upload/binary'
        && $request->hasHeader('Authorization', 'Bearer test-wavespeed-key'));

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.wavespeed.ai/api/v3/wavespeed-ai/audio-vocal-isolator'
        && $request->data()['audio'] === 'https://uploads.example.test/source.wav');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.elevenlabs.io/v1/speech-to-text'
        && $request->hasHeader('xi-api-key', 'test-elevenlabs-key')
        && str_contains((string) $request->header('Content-Type')[0], 'multipart/form-data'));
});

it('rejects unsafe provider download urls', function () {
    configureRealProcessing();
    Http::preventStrayRequests();

    expect(fn () => app(SafeProviderMediaDownloader::class)->downloadToTemp(
        'http://insecure.example.test/vocal.mp3',
        storage_path('app/private/test-safe-download'),
        'vocal',
    ))->toThrow(KaraokeProviderProcessingException::class);

    Http::assertNothingSent();
});

it('marks non retryable auth failures immediately', function () {
    configureRealProcessing();
    Http::preventStrayRequests();
    Http::fake([
        'api.wavespeed.ai/*' => Http::response(['message' => 'secret'], 401),
    ]);

    $project = createRealProcessingProject(realProcessingUser());
    app(KaraokeProcessingStateService::class)->queueForProcessing($project, true);

    try {
        runRealProcessingJob($project->fresh());
    } catch (KaraokeProviderProcessingException) {
        // queue retryable auth should not happen; non-retryable marks failed in job
    }

    $project->refresh();

    expect($project->status)->toBe(KaraokeProjectStatus::Failed)
        ->and($project->error_code)->toBe('provider_auth_failed')
        ->and(app(KaraokeProcessingStateService::class)->isRetryable($project))->toBeFalse();
});

it('does not submit wavespeed isolation twice on retry after separation', function () {
    configureRealProcessing();
    Http::preventStrayRequests();

    $isolatorCalls = 0;

    Http::fake(function (Request $request) use (&$isolatorCalls) {
        $url = $request->url();

        if ($url === 'https://api.wavespeed.ai/api/v3/wavespeed-ai/audio-vocal-isolator') {
            $isolatorCalls++;
        }

        if ($url === 'https://api.wavespeed.ai/api/v3/predictions/pred-retry-1/result') {
            return Http::response([
                'data' => [
                    'status' => 'failed',
                ],
            ], 200);
        }

        return Http::response('unexpected', 500);
    });

    $project = createRealProcessingProject(realProcessingUser(), [
        'status' => KaraokeProjectStatus::Failed,
        'wavespeed_prediction_id' => 'pred-retry-1',
        'processing_driver' => 'real',
        'processing_retryable' => true,
    ]);

    app(KaraokeProcessingStateService::class)->retryProcessing($project->fresh());

    try {
        runRealProcessingJob($project->fresh());
    } catch (KaraokeProviderProcessingException) {
    }

    expect($isolatorCalls)->toBe(0);
});

it('does not expose provider secrets in status json', function () {
    configureRealProcessing();
    fakeProviderHttp();

    $user = realProcessingUser();
    $project = createRealProcessingProject($user);
    app(KaraokeProcessingStateService::class)->queueForProcessing($project, true);
    runRealProcessingJob($project->fresh());

    $payload = json_encode(app(KaraokeProcessingStateService::class)->statusPayload($project->fresh(), $user));

    expect($payload)
        ->not->toContain('test-wavespeed-key')
        ->not->toContain('test-elevenlabs-key')
        ->not->toContain('pred-test-123')
        ->not->toContain('uploads.example.test');
});

it('rejects forged real process post without consent', function () {
    configureRealProcessing();
    $user = realProcessingUser();
    $project = createRealProcessingProject($user);

    $this->actingAs($user)
        ->post(route('karaoke.projects.process', $project))
        ->assertRedirect(route('karaoke.projects.show', $project))
        ->assertSessionHasErrors('provider_consent');

    Queue::assertNothingPushed();
});

it('throws for unsupported driver names', function () {
    expect(fn () => app(KaraokeProcessorManager::class)->driver('unknown'))
        ->toThrow(UnsupportedKaroksProcessingDriverException::class);
});

it('does not call elevenlabs twice when transcript checkpoint exists', function () {
    configureRealProcessing();
    Http::preventStrayRequests();

    $elevenCalls = 0;

    Http::fake(function (Request $request) use (&$elevenCalls) {
        $url = $request->url();

        if ($url === 'https://api.elevenlabs.io/v1/speech-to-text') {
            $elevenCalls++;
        }

        if ($url === 'https://api.wavespeed.ai/api/v3/predictions/pred-transcript-1/result') {
            return Http::response([
                'data' => [
                    'status' => 'completed',
                    'outputs' => [
                        'https://cdn.example.test/vocal.mp3',
                        'https://cdn.example.test/instrumental.mp3',
                    ],
                ],
            ], 200);
        }

        if ($url === 'https://cdn.example.test/instrumental.mp3') {
            return Http::response(realProcessingWavBytes(), 200, ['Content-Type' => 'audio/mpeg']);
        }

        return Http::response('unexpected', 500);
    });

    $project = createRealProcessingProject(realProcessingUser(), [
        'status' => KaraokeProjectStatus::Failed,
        'wavespeed_prediction_id' => 'pred-transcript-1',
        'provider_separation_completed_at' => now(),
        'provider_transcript_checkpoint' => app(KaraokeTranscriptNormalizer::class)->normalize([
            'words' => [
                fakeElevenLabsWord('Hello', 0.2, 0.6),
                fakeElevenLabsWord('world', 0.7, 1.1),
            ],
        ], 2.0, (string) Str::uuid()),
        'processing_driver' => 'real',
        'processing_retryable' => true,
    ]);

    app(KaraokeProcessingStateService::class)->retryProcessing($project->fresh());
    runRealProcessingJob($project->fresh());

    expect($elevenCalls)->toBe(0);
});

it('consumes usage exactly once across provider retries', function () {
    configureRealProcessing(['karoks.providers.poll_interval_seconds' => 1, 'karoks.providers.poll_timeout_seconds' => 3]);
    Http::preventStrayRequests();

    Http::fake([
        'api.wavespeed.ai/*' => Http::sequence()
            ->push(['data' => ['download_url' => 'https://uploads.example.test/source.wav']], 200)
            ->push(['data' => ['id' => 'pred-usage-1']], 200)
            ->push(['data' => ['status' => 'processing']], 200)
            ->push(['data' => ['status' => 'timeout']], 200)
            ->push(['data' => ['status' => 'timeout']], 200),
    ]);

    $user = realProcessingUser();
    $project = createRealProcessingProject($user);
    app(KaraokeProcessingStateService::class)->queueForProcessing($project, true);

    try {
        runRealProcessingJob($project->fresh());
    } catch (KaraokeProviderProcessingException) {
    }

    app(KaraokeProcessingStateService::class)->retryProcessing($project->fresh());

    try {
        runRealProcessingJob($project->fresh());
    } catch (KaraokeProviderProcessingException) {
    }

    expect(KaraokeUsageRecord::query()->where('user_id', $user->id)->where('state', KaraokeUsageRecord::STATE_CONSUMED)->count())->toBe(1);
});

it('does not leak provider details in logs during failure', function () {
    configureRealProcessing();
    Http::preventStrayRequests();
    Http::fake([
        'api.wavespeed.ai/*' => Http::response(['secret' => 'raw-body'], 401),
    ]);

    Log::spy();

    $project = createRealProcessingProject(realProcessingUser());
    app(KaraokeProcessingStateService::class)->queueForProcessing($project, true);

    try {
        runRealProcessingJob($project->fresh());
    } catch (Throwable) {
    }

    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('debug');
});
