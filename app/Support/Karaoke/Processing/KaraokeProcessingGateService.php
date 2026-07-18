<?php

namespace App\Support\Karaoke\Processing;

use App\Exceptions\KaraokeProcessingGateException;
use App\Models\KaraokeProject;
use App\Support\KaraokeStorage;

class KaraokeProcessingGateService
{
    public function __construct(
        private readonly KaraokeProcessingDriverResolver $driverResolver,
        private readonly KaraokeAudioDurationInspector $durationInspector,
    ) {}

    public function assertCanQueue(KaraokeProject $project, bool $providerConsentAccepted): void
    {
        if ($this->driverResolver->isReal()) {
            if (! $this->driverResolver->realConfigured()) {
                throw new KaraokeProcessingGateException(
                    'provider_not_configured',
                    'Real processing is unavailable because provider configuration is incomplete.',
                );
            }

            if (! $providerConsentAccepted && $project->provider_consent_confirmed_at === null) {
                throw new KaraokeProcessingGateException(
                    'provider_consent_required',
                    'Provider consent is required before real processing can start.',
                );
            }

            $this->assertValidDuration($project);
        }
    }

    public function assertValidDuration(KaraokeProject $project): void
    {
        if (! $this->driverResolver->isReal()) {
            return;
        }
        if (! $project->source_path) {
            throw new KaraokeProcessingGateException('source_missing', 'The uploaded source audio could not be found.');
        }

        $disk = KaraokeStorage::disk();

        if (! $disk->exists($project->source_path)) {
            throw new KaraokeProcessingGateException('source_missing', 'The uploaded source audio could not be found.');
        }

        $inspection = $this->durationInspector->inspectFile(
            $disk->path($project->source_path),
            $project->mime_type,
        );

        if ($inspection['readable'] !== true) {
            throw new KaraokeProcessingGateException('invalid_audio', 'This audio file could not be processed.');
        }

        $duration = (int) $inspection['duration_seconds'];

        if ($this->durationInspector->exceedsLimit($duration)) {
            throw new KaraokeProcessingGateException('invalid_audio', 'This audio exceeds the maximum allowed duration.');
        }

        if ((int) ($project->duration_seconds ?? 0) !== $duration) {
            $project->forceFill(['duration_seconds' => $duration])->save();
        }
    }
}
