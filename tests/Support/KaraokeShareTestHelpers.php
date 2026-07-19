<?php

namespace Tests\Support;

use App\Enums\KaraokeProjectStatus;
use App\Enums\KaraokeShareExpirationOption;
use App\Models\KaraokeProject;
use App\Models\KaraokeProjectShare;
use App\Models\User;
use App\Support\Karaoke\KaraokeProjectShareService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait KaraokeShareTestHelpers
{
    protected function createShareUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['verified' => 1], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createShareReadyProject(User $user, array $attributes = []): KaraokeProject
    {
        $publicId = (string) Str::uuid();
        $path = 'karaoke/'.$user->id.'/'.$publicId.'/source.wav';
        $audioBytes = file_get_contents(base_path('tests/fixtures/sample.wav'));

        Storage::disk('local')->put($path, $audioBytes);

        $transcript = json_decode(
            (string) file_get_contents(database_path('fixtures/karoks-demo-transcript.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $project = KaraokeProject::factory()->create(array_merge([
            'user_id' => $user->id,
            'public_id' => $publicId,
            'source_path' => $path,
            'mime_type' => 'audio/wav',
            'size_bytes' => strlen($audioBytes),
            'transcript' => $transcript,
            'processing_driver' => 'mock',
        ], $attributes));

        if (! array_key_exists('status', $attributes)) {
            $instrumentalPath = $project->storageDirectory().'/instrumental.wav';
            Storage::disk('local')->put($instrumentalPath, $audioBytes);

            $project->forceFill([
                'status' => KaraokeProjectStatus::Completed,
                'instrumental_path' => $instrumentalPath,
                'instrumental_mime_type' => 'audio/wav',
                'processing_stage' => 'completed',
                'progress' => 100,
            ])->save();

            $project->refresh();
        }

        return $project;
    }

    /**
     * @return array{share: KaraokeProjectShare, token: string, url: string}
     */
    protected function createShareForProject(
        KaraokeProject $project,
        User $user,
        KaraokeShareExpirationOption $expiration = KaraokeShareExpirationOption::Days7,
    ): array {
        $result = app(KaraokeProjectShareService::class)->createShare($project, $user, $expiration);

        return [
            'share' => $result['share'],
            'token' => $this->extractTokenFromShareUrl($result['url']),
            'url' => $result['url'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function shareFormPayload(string $expiresIn = '7d'): array
    {
        return [
            'sharing_confirmation' => '1',
            'expires_in' => $expiresIn,
        ];
    }

    protected function extractTokenFromShareUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return (string) Str::afterLast((string) $path, '/');
    }
}
