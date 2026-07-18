<?php

namespace App\Console\Commands;

use App\Models\KaraokeProject;
use App\Models\User;
use App\Support\KaroksDemoAudio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SeedKaroksDemoCommand extends Command
{
    protected $signature = 'karoks:seed-demo {--user= : Email of the user who should own the demo project}';

    protected $description = 'Create a local demo karaoke project with bundled transcript and sample audio';

    public function handle(): int
    {
        if (! App::environment('local')) {
            $this->error('This command is only available in the local environment.');

            return self::FAILURE;
        }

        $email = $this->option('user') ?: 'admin@admin.com';
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user found with email [{$email}].");

            return self::FAILURE;
        }

        $transcriptPath = database_path('fixtures/karoks-demo-transcript.json');
        $audioFixturePath = KaroksDemoAudio::fixturePath();

        if (! is_file($transcriptPath)) {
            $this->error('Demo transcript fixture is missing from the repository.');

            return self::FAILURE;
        }

        if (! KaroksDemoAudio::ensureFixtureExists()) {
            $this->error('Unable to create the demo audio fixture.');

            return self::FAILURE;
        }

        $transcript = json_decode((string) file_get_contents($transcriptPath), true);

        if (! is_array($transcript)) {
            $this->error('Demo transcript fixture is invalid JSON.');

            return self::FAILURE;
        }

        $publicId = (string) Str::uuid();
        $storagePath = 'karaoke/'.$user->id.'/'.$publicId.'/source.wav';

        Storage::disk('local')->put($storagePath, file_get_contents($audioFixturePath));

        $project = KaraokeProject::query()->create([
            'public_id' => $publicId,
            'user_id' => $user->id,
            'title' => 'Karoks Demo Track',
            'artist' => 'Demo Artist',
            'original_filename' => 'demo.wav',
            'source_path' => $storagePath,
            'mime_type' => 'audio/wav',
            'size_bytes' => Storage::disk('local')->size($storagePath),
            'rights_confirmed_at' => now(),
            'transcript' => $transcript,
            'theme' => [
                'backgroundPreset' => 'noir-gold',
                'lyricSize' => 'medium',
                'baseColor' => '#f4f0e6',
                'highlightColor' => '#f0c14b',
            ],
        ]);

        $this->info('Demo karaoke project created.');
        $this->line('Player: '.route('karaoke.projects.player', $project));
        $this->line('Show: '.route('karaoke.projects.show', $project));

        return self::SUCCESS;
    }
}
