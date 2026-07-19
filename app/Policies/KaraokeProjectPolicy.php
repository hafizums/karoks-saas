<?php

namespace App\Policies;

use App\Enums\KaraokeProjectStatus;
use App\Models\KaraokeProject;
use App\Models\User;
use App\Support\KaraokeProcessingStateService;

class KaraokeProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, KaraokeProject $karaokeProject): bool
    {
        return $this->owns($user, $karaokeProject);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function downloadSource(User $user, KaraokeProject $karaokeProject): bool
    {
        return $this->owns($user, $karaokeProject);
    }

    public function play(User $user, KaraokeProject $karaokeProject): bool
    {
        return $this->owns($user, $karaokeProject) && $karaokeProject->isReadyForPlayback();
    }

    public function streamAudio(User $user, KaraokeProject $karaokeProject): bool
    {
        return $this->owns($user, $karaokeProject) && $karaokeProject->playbackAudioPath() !== null;
    }

    public function edit(User $user, KaraokeProject $karaokeProject): bool
    {
        return $this->owns($user, $karaokeProject) && $karaokeProject->isReadyForEditing();
    }

    public function update(User $user, KaraokeProject $karaokeProject): bool
    {
        return $this->owns($user, $karaokeProject) && $karaokeProject->isReadyForEditing();
    }

    public function export(User $user, KaraokeProject $karaokeProject): bool
    {
        return $this->owns($user, $karaokeProject) && $karaokeProject->isReadyForEditing();
    }

    public function import(User $user, KaraokeProject $karaokeProject): bool
    {
        return $this->owns($user, $karaokeProject) && $karaokeProject->isReadyForEditing();
    }

    public function process(User $user, KaraokeProject $karaokeProject): bool
    {
        if (! $this->owns($user, $karaokeProject)) {
            return false;
        }

        if (in_array($karaokeProject->status, [KaraokeProjectStatus::Queued, KaraokeProjectStatus::Processing], true)) {
            return true;
        }

        return in_array($karaokeProject->status, [KaraokeProjectStatus::Uploaded, KaraokeProjectStatus::Cancelled], true);
    }

    public function cancel(User $user, KaraokeProject $karaokeProject): bool
    {
        return $this->owns($user, $karaokeProject)
            && in_array($karaokeProject->status, [KaraokeProjectStatus::Queued, KaraokeProjectStatus::Processing], true);
    }

    public function retry(User $user, KaraokeProject $karaokeProject): bool
    {
        if (! $this->owns($user, $karaokeProject)) {
            return false;
        }

        if (in_array($karaokeProject->status, [KaraokeProjectStatus::Queued, KaraokeProjectStatus::Processing], true)) {
            return true;
        }

        return $karaokeProject->status === KaraokeProjectStatus::Failed
            && app(KaraokeProcessingStateService::class)->isRetryable($karaokeProject);
    }

    public function viewStatus(User $user, KaraokeProject $karaokeProject): bool
    {
        return $this->owns($user, $karaokeProject);
    }

    public function delete(User $user, KaraokeProject $karaokeProject): bool
    {
        return $this->owns($user, $karaokeProject);
    }

    public function share(User $user, KaraokeProject $karaokeProject): bool
    {
        return $this->owns($user, $karaokeProject) && $karaokeProject->isReadyForPlayback();
    }

    public function rotateShare(User $user, KaraokeProject $karaokeProject): bool
    {
        return $this->share($user, $karaokeProject);
    }

    public function revokeShare(User $user, KaraokeProject $karaokeProject): bool
    {
        return $this->share($user, $karaokeProject);
    }

    public function manageEmbed(User $user, KaraokeProject $karaokeProject): bool
    {
        return $this->share($user, $karaokeProject);
    }

    private function owns(User $user, KaraokeProject $karaokeProject): bool
    {
        return (int) $karaokeProject->user_id === (int) $user->id;
    }
}
