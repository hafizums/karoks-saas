<?php

namespace App\Policies;

use App\Models\KaraokeProject;
use App\Models\User;

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

    public function delete(User $user, KaraokeProject $karaokeProject): bool
    {
        return $this->owns($user, $karaokeProject);
    }

    private function owns(User $user, KaraokeProject $karaokeProject): bool
    {
        return (int) $karaokeProject->user_id === (int) $user->id;
    }
}
