<?php

use App\Enums\KaraokeProjectStatus;
use App\Exceptions\KaraokeUsageLimitExceededException;
use App\Models\KaraokeProject;
use App\Models\KaraokeUsageRecord;
use App\Models\User;
use App\Support\KaraokeUsageService;
use DevDojo\Themes\Models\Theme;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\KaraokeTestTheme;

beforeEach(function () {
    if (! Theme::query()->where('folder', 'anchor')->exists()) {
        Theme::query()->create([
            'name' => 'Anchor Theme',
            'folder' => 'anchor',
            'active' => true,
            'version' => 1.0,
        ]);
    }

    KaraokeTestTheme::register();
    Storage::fake('local');
    Config::set('karoks.processing.enabled', true);
    Config::set('karoks.usage.default_monthly_limit', 2);
});

function concurrentUsageUser(): User
{
    return User::factory()->create(['verified' => 1]);
}

function concurrentUsageProject(User $user): KaraokeProject
{
    $publicId = (string) Str::uuid();
    $path = 'karaoke/'.$user->id.'/'.$publicId.'/source.wav';

    Storage::disk('local')->put($path, file_get_contents(base_path('tests/fixtures/sample.wav')));

    return KaraokeProject::factory()->create([
        'user_id' => $user->id,
        'public_id' => $publicId,
        'source_path' => $path,
        'mime_type' => 'audio/wav',
        'size_bytes' => Storage::disk('local')->size($path),
        'status' => KaraokeProjectStatus::Uploaded,
    ]);
}

it('allows exactly one concurrent reservation for the final monthly allowance', function () {
    Config::set('karoks.usage.default_monthly_limit', 1);

    $user = concurrentUsageUser();
    $first = concurrentUsageProject($user);
    $second = concurrentUsageProject($user);

    KaraokeUsageRecord::query()->where('user_id', $user->id)->delete();

    $syncDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'karoks-usage-sync-'.Str::uuid();
    mkdir($syncDir);

    $phpBinary = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
    $workerScript = base_path('tests/Support/concurrent_usage_reserve_worker.php');
    $resultOne = $syncDir.DIRECTORY_SEPARATOR.'result-one.txt';
    $resultTwo = $syncDir.DIRECTORY_SEPARATOR.'result-two.txt';

    $processOne = new Process([
        $phpBinary,
        $workerScript,
        (string) $user->id,
        (string) $first->id,
        '1',
        $syncDir,
        $resultOne,
        'worker-one',
    ], base_path());
    $processTwo = new Process([
        $phpBinary,
        $workerScript,
        (string) $user->id,
        (string) $second->id,
        '1',
        $syncDir,
        $resultTwo,
        'worker-two',
    ], base_path());

    $processOne->start();
    $processTwo->start();

    $deadline = microtime(true) + 10;

    while (
        (! is_file($syncDir.DIRECTORY_SEPARATOR.'worker-one.ready') || ! is_file($syncDir.DIRECTORY_SEPARATOR.'worker-two.ready'))
        && microtime(true) < $deadline
    ) {
        usleep(5000);
    }

    expect(is_file($syncDir.DIRECTORY_SEPARATOR.'worker-one.ready'))->toBeTrue()
        ->and(is_file($syncDir.DIRECTORY_SEPARATOR.'worker-two.ready'))->toBeTrue();

    file_put_contents($syncDir.DIRECTORY_SEPARATOR.'go', '1');

    expect($processOne->wait())->toBe(0)
        ->and($processTwo->wait())->toBe(0);

    $outcomes = [
        trim((string) file_get_contents($resultOne)),
        trim((string) file_get_contents($resultTwo)),
    ];

    expect($outcomes)->toContain('reserved')
        ->and($outcomes)->toContain('limit_exceeded')
        ->and(KaraokeUsageRecord::query()
            ->where('user_id', $user->id)
            ->where('state', KaraokeUsageRecord::STATE_RESERVED)
            ->count())->toBe(1);

    KaraokeUsageRecord::query()->where('user_id', $user->id)->delete();
    $first->delete();
    $second->delete();
    $user->forceDelete();

    @unlink($syncDir.DIRECTORY_SEPARATOR.'go');
    @unlink($syncDir.DIRECTORY_SEPARATOR.'worker-one.ready');
    @unlink($syncDir.DIRECTORY_SEPARATOR.'worker-two.ready');
    @unlink($resultOne);
    @unlink($resultTwo);
    @rmdir($syncDir);
})->group('concurrency');

it('serializes concurrent reservations through the user row lock', function () {
    Config::set('karoks.usage.default_monthly_limit', 1);

    $user = concurrentUsageUser();
    $first = concurrentUsageProject($user);
    $second = concurrentUsageProject($user);
    $service = app(KaraokeUsageService::class);

    $service->reserveForProject($user, $first, 1);

    expect(fn () => $service->reserveForProject($user, $second, 1))
        ->toThrow(KaraokeUsageLimitExceededException::class);

    usageRecordsFor($user)->delete();
    $first->delete();
    $second->delete();
    $user->forceDelete();
});
