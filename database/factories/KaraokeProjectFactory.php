<?php

namespace Database\Factories;

use App\Enums\KaraokeProjectStatus;
use App\Models\KaraokeProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KaraokeProject>
 */
class KaraokeProjectFactory extends Factory
{
    protected $model = KaraokeProject::class;

    public function definition(): array
    {
        $publicId = (string) Str::uuid();

        return [
            'public_id' => $publicId,
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'artist' => fake()->optional()->name(),
            'original_filename' => 'track.wav',
            'source_path' => function (array $attributes) {
                return 'karaoke/'.$attributes['user_id'].'/'.$attributes['public_id'].'/source.wav';
            },
            'mime_type' => 'audio/wav',
            'size_bytes' => 1024,
            'duration_seconds' => null,
            'status' => KaraokeProjectStatus::Uploaded,
            'processing_stage' => null,
            'progress' => 0,
            'rights_confirmed_at' => now(),
            'provider_consent_confirmed_at' => null,
            'transcript' => null,
            'theme' => null,
            'error_code' => null,
            'error_message' => null,
        ];
    }
}
