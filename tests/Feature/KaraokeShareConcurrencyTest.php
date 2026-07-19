<?php

use App\Models\KaraokeProjectShare;
use DevDojo\Themes\Models\Theme;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\KaraokeShareTestHelpers;
use Tests\Support\KaraokeTestTheme;

uses(KaraokeShareTestHelpers::class);

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
    Http::fake();
});

it('allows concurrent share creation from independent connections with one winner', function () {
    $user = $this->createShareUser();
    $project = $this->createShareReadyProject($user);

    $syncDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'karoks-share-sync-'.Str::uuid();
    mkdir($syncDir);

    $phpBinary = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
    $workerScript = base_path('tests/Support/concurrent_share_create_worker.php');
    $resultOne = $syncDir.DIRECTORY_SEPARATOR.'result-one.txt';
    $resultTwo = $syncDir.DIRECTORY_SEPARATOR.'result-two.txt';

    $processOne = new Process([
        $phpBinary,
        $workerScript,
        (string) $user->id,
        (string) $project->id,
        $syncDir,
        $resultOne,
        'worker-one',
    ], base_path());
    $processTwo = new Process([
        $phpBinary,
        $workerScript,
        (string) $user->id,
        (string) $project->id,
        $syncDir,
        $resultTwo,
        'worker-two',
    ], base_path());

    $processOne->start();
    $processTwo->start();

    $deadline = microtime(true) + 15;

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

    expect($outcomes)->toContain('created')
        ->and($outcomes)->toContain('already_exists');

    foreach ($outcomes as $outcome) {
        expect($outcome)->not->toStartWith('error:');
    }

    foreach ([$resultOne, $resultTwo] as $resultPath) {
        $contents = (string) file_get_contents($resultPath);
        expect($contents)->not->toMatch('/^[A-Za-z0-9_-]{40,}$/');
        expect($contents)->not->toContain('/karaoke/shared/');
    }

    $activeCount = KaraokeProjectShare::query()
        ->where('karaoke_project_id', $project->id)
        ->get()
        ->filter(fn (KaraokeProjectShare $share): bool => $share->isActive())
        ->count();

    expect($activeCount)->toBe(1);

    KaraokeProjectShare::query()->where('karaoke_project_id', $project->id)->delete();
    Storage::disk('local')->deleteDirectory($project->storageDirectory());
    $project->delete();
    $user->forceDelete();

    @unlink($syncDir.DIRECTORY_SEPARATOR.'go');
    @unlink($syncDir.DIRECTORY_SEPARATOR.'worker-one.ready');
    @unlink($syncDir.DIRECTORY_SEPARATOR.'worker-two.ready');
    @unlink($resultOne);
    @unlink($resultTwo);
    @rmdir($syncDir);
});
