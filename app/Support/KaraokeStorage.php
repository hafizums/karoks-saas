<?php

namespace App\Support;

use App\Models\KaraokeProject;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

class KaraokeStorage
{
    public static function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    public static function userDirectory(int $userId): string
    {
        return 'karaoke/'.$userId;
    }

    public static function deleteProjectFiles(KaraokeProject $project): void
    {
        if ($project->source_path) {
            self::disk()->delete($project->source_path);
        }

        self::disk()->deleteDirectory($project->storageDirectory());
    }

    public static function deleteUserFiles(int $userId): void
    {
        self::disk()->deleteDirectory(self::userDirectory($userId));
    }
}
