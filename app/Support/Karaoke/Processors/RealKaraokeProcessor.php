<?php

namespace App\Support\Karaoke\Processors;

use App\Contracts\KaraokeProcessor;
use App\Enums\KaraokeProcessingStage;
use App\Exceptions\KaraokeProviderProcessingException;
use App\Exceptions\NonRetryableKaraokeProcessingException;
use App\Exceptions\ProcessingRunInterruptedException;
use App\Models\KaraokeProject;
use App\Rules\ValidKaraokeAudio;
use App\Support\Karaoke\Processing\KaraokeProcessingCheckpointService;
use App\Support\Karaoke\Processing\KaraokeProcessingGateService;
use App\Support\Karaoke\Processing\KaraokeProcessingRunGuard;
use App\Support\Karaoke\Providers\ElevenLabsClient;
use App\Support\Karaoke\Providers\KaraokeProviderErrorMapper;
use App\Support\Karaoke\Providers\KaraokeTranscriptNormalizer;
use App\Support\Karaoke\Providers\SafeProviderMediaDownloader;
use App\Support\Karaoke\Providers\WaveSpeedClient;
use App\Support\KaraokeProcessingProgress;
use App\Support\KaraokeProcessingResult;
use App\Support\KaraokeStorage;
use App\Support\KaraokeThemeParser;
use Closure;
use Illuminate\Support\Facades\File;

class RealKaraokeProcessor implements KaraokeProcessor
{
    public const DISCLOSURE = 'This project was processed with WaveSpeed vocal separation and ElevenLabs Scribe transcription.';

    public function __construct(
        private readonly WaveSpeedClient $waveSpeed,
        private readonly ElevenLabsClient $elevenLabs,
        private readonly SafeProviderMediaDownloader $downloader,
        private readonly KaraokeTranscriptNormalizer $normalizer,
        private readonly KaraokeProcessingCheckpointService $checkpoints,
        private readonly KaraokeProviderErrorMapper $errors,
        private readonly KaraokeProcessingGateService $gateService,
        private readonly KaraokeProcessingRunGuard $runGuard,
    ) {}

    /**
     * @param  Closure(KaraokeProcessingProgress): void  $reportProgress
     */
    public function process(KaraokeProject $project, string $processingRunId, Closure $reportProgress): KaraokeProcessingResult
    {
        if ($project->processing_driver !== 'real') {
            throw new NonRetryableKaraokeProcessingException('processing_failed');
        }

        $this->gateService->assertRealExternalProcessingAllowed($project);
        $this->runGuard->assertActive($project, $processingRunId);

        $disk = KaraokeStorage::disk();

        if (! $project->source_path || ! $disk->exists($project->source_path)) {
            throw new NonRetryableKaraokeProcessingException('source_missing');
        }

        $sourceAbsolute = $disk->path($project->source_path);
        $extension = ValidKaraokeAudio::safeExtensionFromMime($project->mime_type)
            ?? pathinfo($project->source_path, PATHINFO_EXTENSION);

        if (! is_string($extension) || $extension === '') {
            throw new NonRetryableKaraokeProcessingException('unsupported_audio');
        }

        $this->checkpoints->bindRun($project, $processingRunId, 'real');
        $project->refresh();

        $reportProgress(new KaraokeProcessingProgress(KaraokeProcessingStage::Preparing, KaraokeProcessingStage::Preparing->progress()));

        $tempDirectory = storage_path('app/private/karaoke-temp/'.$project->user_id.'/'.$project->public_id.'/'.$processingRunId);
        File::ensureDirectoryExists($tempDirectory);

        $vocalTempPath = null;
        $instrumentalTempPath = null;

        try {
            $predictionId = $project->wavespeed_prediction_id;

            if (! $this->checkpoints->hasReusableSeparation($project)) {
                $reportProgress(new KaraokeProcessingProgress(KaraokeProcessingStage::Separating, KaraokeProcessingStage::Separating->progress()));
                $this->runGuard->assertActive($project, $processingRunId);
                $this->gateService->assertRealExternalProcessingAllowed($project);

                $uploadUrl = $this->waveSpeed->uploadSourceFile(
                    $sourceAbsolute,
                    basename($project->source_path),
                    $project->mime_type,
                );

                $this->runGuard->assertActive($project, $processingRunId);
                $this->gateService->assertRealExternalProcessingAllowed($project);

                $predictionId = $this->waveSpeed->submitVocalIsolation($uploadUrl);
                $this->checkpoints->savePredictionId($project, $processingRunId, $predictionId);
                $project->refresh();
            } else {
                $reportProgress(new KaraokeProcessingProgress(KaraokeProcessingStage::Separating, KaraokeProcessingStage::Separating->progress()));
            }

            $outputs = $this->waveSpeed->pollUntilCompleted(
                (string) $predictionId,
                function () use ($reportProgress, $project, $processingRunId): void {
                    $this->runGuard->assertActive($project, $processingRunId);
                    $reportProgress(new KaraokeProcessingProgress(
                        KaraokeProcessingStage::Separating,
                        max(KaraokeProcessingStage::Separating->progress(), 35),
                    ));
                },
            );

            $this->runGuard->assertActive($project, $processingRunId);
            $this->checkpoints->markSeparationCompleted($project, $processingRunId);
            $project->refresh();

            $reportProgress(new KaraokeProcessingProgress(KaraokeProcessingStage::Transcribing, KaraokeProcessingStage::Transcribing->progress()));

            $transcript = $this->checkpoints->reusableTranscript($project);

            if ($transcript === null) {
                $this->runGuard->assertActive($project, $processingRunId);
                $this->gateService->assertRealExternalProcessingAllowed($project);

                $vocalDownload = $this->downloader->downloadToTemp(
                    $outputs['vocal_url'],
                    $tempDirectory,
                    'vocal',
                );
                $vocalTempPath = $vocalDownload['path'];

                $this->runGuard->assertActive($project, $processingRunId);
                $this->gateService->assertRealExternalProcessingAllowed($project);

                $elevenResponse = $this->elevenLabs->transcribeVocalFile(
                    $vocalTempPath,
                    'vocals.'.$vocalDownload['extension'],
                    $vocalDownload['mime_type'],
                );

                $duration = max(1.0, (float) ($project->duration_seconds ?? 0));
                $transcript = $this->normalizer->normalize($elevenResponse, $duration, $project->public_id);
                $this->checkpoints->saveTranscriptCheckpoint($project, $processingRunId, $transcript);
                $project->refresh();
            }

            $reportProgress(new KaraokeProcessingProgress(KaraokeProcessingStage::Assembling, KaraokeProcessingStage::Assembling->progress()));
            $this->runGuard->assertActive($project, $processingRunId);

            $instrumentalDownload = $this->downloader->downloadToTemp(
                $outputs['instrumental_url'],
                $tempDirectory,
                'instrumental',
            );
            $instrumentalTempPath = $instrumentalDownload['path'];

            $this->runGuard->assertActive($project, $processingRunId);

            $instrumentalPath = $project->storageDirectory().'/instrumental.'.$instrumentalDownload['extension'];
            $this->persistInstrumental($disk->path(dirname($instrumentalPath)), $instrumentalPath, $instrumentalTempPath);

            $theme = KaraokeThemeParser::defaults();

            $reportProgress(new KaraokeProcessingProgress(KaraokeProcessingStage::Completed, 100));

            return new KaraokeProcessingResult(
                instrumentalPath: $instrumentalPath,
                instrumentalMimeType: $instrumentalDownload['mime_type'],
                transcript: $transcript,
                theme: $theme,
                disclosure: self::DISCLOSURE,
                simulated: false,
            );
        } catch (KaraokeProviderProcessingException $exception) {
            if ($exception->invalidatesSeparationCheckpoint) {
                $this->checkpoints->invalidateSeparationCheckpoint($project, $processingRunId);
            }

            throw $exception;
        } catch (ProcessingRunInterruptedException $exception) {
            throw $exception;
        } finally {
            if (is_string($vocalTempPath)) {
                @unlink($vocalTempPath);
            }

            if (is_string($instrumentalTempPath) && is_file($instrumentalTempPath)) {
                @unlink($instrumentalTempPath);
            }

            if (is_dir($tempDirectory)) {
                File::deleteDirectory($tempDirectory);
            }
        }
    }

    private function persistInstrumental(string $directory, string $relativePath, string $sourcePath): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw $this->errors->storageFailed();
        }

        $absolutePath = KaraokeStorage::disk()->path($relativePath);
        $input = fopen($sourcePath, 'rb');

        if ($input === false) {
            throw $this->errors->storageFailed();
        }

        $output = fopen($absolutePath, 'wb');

        if ($output === false) {
            fclose($input);

            throw $this->errors->storageFailed();
        }

        try {
            $copied = stream_copy_to_stream($input, $output);

            if ($copied === false || $copied <= 0) {
                throw $this->errors->storageFailed();
            }
        } finally {
            fclose($input);
            fclose($output);
        }

        if (! is_file($absolutePath) || filesize($absolutePath) <= 0) {
            @unlink($absolutePath);

            throw $this->errors->storageFailed();
        }
    }
}
