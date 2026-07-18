<?php

namespace App\Support\Karaoke\Providers;

use App\Exceptions\KaraokeProviderProcessingException;

class KaraokeProviderErrorMapper
{
    /**
     * @return array{error_code: string, user_message: string, queue_retryable: bool, manual_retryable: bool}
     */
    public function mapHttpFailure(string $provider, int $status, string $step = 'request'): array
    {
        unset($step);

        if ($provider === 'wavespeed') {
            return $this->mapWaveSpeedStatus($status);
        }

        return $this->mapElevenLabsStatus($status);
    }

    /**
     * @return array{error_code: string, user_message: string, queue_retryable: bool, manual_retryable: bool}
     */
    public function mapWaveSpeedStatus(int $status): array
    {
        return match (true) {
            $status === 401 => [
                'error_code' => 'provider_auth_failed',
                'user_message' => 'Real processing is unavailable because provider authentication failed.',
                'queue_retryable' => false,
                'manual_retryable' => false,
            ],
            $status === 403 => [
                'error_code' => 'provider_insufficient_credit',
                'user_message' => 'Real processing is unavailable because provider credits are insufficient.',
                'queue_retryable' => false,
                'manual_retryable' => false,
            ],
            $status === 408 => [
                'error_code' => 'provider_timeout',
                'user_message' => 'Processing timed out while contacting the provider. Please try again.',
                'queue_retryable' => true,
                'manual_retryable' => true,
            ],
            $status === 429 => [
                'error_code' => 'provider_rate_limited',
                'user_message' => 'Processing is temporarily rate limited. Please try again shortly.',
                'queue_retryable' => true,
                'manual_retryable' => true,
            ],
            $status >= 500 => [
                'error_code' => 'provider_failed',
                'user_message' => 'Processing failed at the provider. Please try again.',
                'queue_retryable' => true,
                'manual_retryable' => true,
            ],
            default => [
                'error_code' => 'provider_failed',
                'user_message' => 'Processing could not be completed at the provider.',
                'queue_retryable' => false,
                'manual_retryable' => true,
            ],
        };
    }

    /**
     * @return array{error_code: string, user_message: string, queue_retryable: bool, manual_retryable: bool}
     */
    public function mapElevenLabsStatus(int $status): array
    {
        return match (true) {
            $status === 401, $status === 403 => [
                'error_code' => 'provider_auth_failed',
                'user_message' => 'Real processing is unavailable because transcription authentication failed.',
                'queue_retryable' => false,
                'manual_retryable' => false,
            ],
            $status === 408 => [
                'error_code' => 'provider_timeout',
                'user_message' => 'Transcription timed out. Please try again.',
                'queue_retryable' => true,
                'manual_retryable' => true,
            ],
            $status === 429 => [
                'error_code' => 'provider_rate_limited',
                'user_message' => 'Transcription is temporarily rate limited. Please try again shortly.',
                'queue_retryable' => true,
                'manual_retryable' => true,
            ],
            $status >= 500 => [
                'error_code' => 'provider_failed',
                'user_message' => 'Transcription failed at the provider. Please try again.',
                'queue_retryable' => true,
                'manual_retryable' => true,
            ],
            default => [
                'error_code' => 'invalid_provider_output',
                'user_message' => 'Transcription could not be completed for this audio.',
                'queue_retryable' => false,
                'manual_retryable' => false,
            ],
        };
    }

    public function exceptionFromMapping(array $mapping): KaraokeProviderProcessingException
    {
        return new KaraokeProviderProcessingException(
            errorCode: $mapping['error_code'],
            userMessage: $mapping['user_message'],
            queueRetryable: $mapping['queue_retryable'],
            manualRetryable: $mapping['manual_retryable'],
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

    public function invalidProviderOutput(): KaraokeProviderProcessingException
    {
        return new KaraokeProviderProcessingException(
            errorCode: 'invalid_provider_output',
            userMessage: 'Processing returned an invalid result.',
            queueRetryable: false,
            manualRetryable: true,
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

    public function providerTimeout(): KaraokeProviderProcessingException
    {
        return new KaraokeProviderProcessingException(
            errorCode: 'provider_timeout',
            userMessage: 'Processing timed out while waiting for the provider.',
            queueRetryable: true,
            manualRetryable: true,
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
}
