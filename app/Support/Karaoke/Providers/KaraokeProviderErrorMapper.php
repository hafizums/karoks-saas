<?php

namespace App\Support\Karaoke\Providers;

use App\Exceptions\KaraokeProviderProcessingException;

class KaraokeProviderErrorMapper
{
    /**
     * @return array{error_code: string, user_message: string, queue_retryable: bool, manual_retryable: bool, invalidates_separation: bool}
     */
    public function mapHttpFailure(string $provider, int $status, string $step = 'request'): array
    {
        $billablePost = in_array($step, ['isolator_submit'], true);

        if ($provider === 'wavespeed') {
            return $this->mapWaveSpeedStatus($status, $billablePost, $step);
        }

        return $this->mapElevenLabsStatus($status, $step);
    }

    /**
     * @return array{error_code: string, user_message: string, queue_retryable: bool, manual_retryable: bool, invalidates_separation: bool}
     */
    public function mapWaveSpeedStatus(int $status, bool $billablePost = false, string $step = 'request'): array
    {
        unset($step);

        return match (true) {
            $status === 401 => $this->mapping('provider_auth_failed', 'Real processing is unavailable because provider authentication failed.', false, false),
            $status === 403 => $this->mapping('provider_insufficient_credit', 'Real processing is unavailable because provider credits are insufficient.', false, false),
            $status === 408 => $this->mapping('provider_timeout', 'Processing timed out while contacting the provider. Please try again.', ! $billablePost, true),
            $status === 429 => $this->mapping('provider_rate_limited', 'Processing is temporarily rate limited. Please try again shortly.', true, true),
            $status >= 500 => $this->mapping(
                'provider_failed',
                'Processing failed at the provider. Please try again.',
                ! $billablePost,
                true,
            ),
            default => $this->mapping(
                'provider_failed',
                'Processing could not be completed at the provider.',
                false,
                true,
            ),
        };
    }

    /**
     * @return array{error_code: string, user_message: string, queue_retryable: bool, manual_retryable: bool, invalidates_separation: bool}
     */
    public function mapElevenLabsStatus(int $status, string $step = 'request'): array
    {
        unset($step);

        return match (true) {
            $status === 401, $status === 403 => $this->mapping('provider_auth_failed', 'Real processing is unavailable because transcription authentication failed.', false, false),
            $status === 408 => $this->mapping('provider_timeout', 'Transcription timed out. Please try again.', true, true),
            $status === 429 => $this->mapping('provider_rate_limited', 'Transcription is temporarily rate limited. Please try again shortly.', true, true),
            $status >= 500 => $this->mapping('provider_failed', 'Transcription failed at the provider. Please try again.', true, true),
            default => $this->mapping('invalid_provider_output', 'Transcription could not be completed for this audio.', false, false),
        };
    }

    /**
     * @param  array{error_code: string, user_message: string, queue_retryable: bool, manual_retryable: bool, invalidates_separation?: bool}  $mapping
     */
    public function exceptionFromMapping(array $mapping, bool $invalidatesSeparation = false): KaraokeProviderProcessingException
    {
        return new KaraokeProviderProcessingException(
            errorCode: $mapping['error_code'],
            userMessage: $mapping['user_message'],
            queueRetryable: $mapping['queue_retryable'],
            manualRetryable: $mapping['manual_retryable'],
            invalidatesSeparationCheckpoint: $invalidatesSeparation || ($mapping['invalidates_separation'] ?? false),
        );
    }

    public function notConfigured(): KaraokeProviderProcessingException
    {
        return new KaraokeProviderProcessingException(
            errorCode: 'provider_not_configured',
            userMessage: 'Real processing is not configured on this server.',
            queueRetryable: false,
            manualRetryable: false,
        );
    }

    public function invalidAudio(): KaraokeProviderProcessingException
    {
        return new KaraokeProviderProcessingException(
            errorCode: 'invalid_audio',
            userMessage: 'This audio file could not be processed.',
            queueRetryable: false,
            manualRetryable: false,
        );
    }

    public function invalidProviderOutput(bool $invalidatesSeparation = true): KaraokeProviderProcessingException
    {
        return new KaraokeProviderProcessingException(
            errorCode: 'invalid_provider_output',
            userMessage: 'Processing returned an invalid result.',
            queueRetryable: false,
            manualRetryable: true,
            invalidatesSeparationCheckpoint: $invalidatesSeparation,
        );
    }

    public function noLyricsFound(): KaraokeProviderProcessingException
    {
        return new KaraokeProviderProcessingException(
            errorCode: 'no_lyrics_found',
            userMessage: 'No lyrics were found in this track.',
            queueRetryable: false,
            manualRetryable: false,
        );
    }

    public function providerPollingDeadlineExceeded(): KaraokeProviderProcessingException
    {
        return new KaraokeProviderProcessingException(
            errorCode: 'provider_timeout',
            userMessage: 'Processing timed out while waiting for the provider.',
            queueRetryable: true,
            manualRetryable: true,
        );
    }

    public function providerTerminalFailure(string $status): KaraokeProviderProcessingException
    {
        return new KaraokeProviderProcessingException(
            errorCode: $status === 'timeout' ? 'provider_timeout' : 'provider_failed',
            userMessage: $status === 'timeout'
                ? 'The provider timed out while separating this track.'
                : 'Processing failed at the provider. Please try again.',
            queueRetryable: false,
            manualRetryable: true,
            invalidatesSeparationCheckpoint: true,
        );
    }

    public function providerFailed(): KaraokeProviderProcessingException
    {
        return new KaraokeProviderProcessingException(
            errorCode: 'provider_failed',
            userMessage: 'Processing failed at the provider. Please try again.',
            queueRetryable: true,
            manualRetryable: true,
        );
    }

    public function storageFailed(): KaraokeProviderProcessingException
    {
        return new KaraokeProviderProcessingException(
            errorCode: 'processing_failed',
            userMessage: 'Processing could not be completed. Please try again.',
            queueRetryable: false,
            manualRetryable: true,
        );
    }

    /**
     * @return array{error_code: string, user_message: string, queue_retryable: bool, manual_retryable: bool, invalidates_separation: bool}
     */
    private function mapping(string $code, string $message, bool $queueRetryable, bool $manualRetryable, bool $invalidatesSeparation = false): array
    {
        return [
            'error_code' => $code,
            'user_message' => $message,
            'queue_retryable' => $queueRetryable,
            'manual_retryable' => $manualRetryable,
            'invalidates_separation' => $invalidatesSeparation,
        ];
    }
}
