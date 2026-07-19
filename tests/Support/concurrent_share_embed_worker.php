<?php

namespace Tests\Support;

use App\Models\KaraokeProject;
use App\Models\User;
use App\Support\Karaoke\KaraokeProjectShareService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('database.default') === 'sqlite') {
    DB::connection()->getPdo()->exec('PRAGMA busy_timeout = 10000');
}

$userId = (int) ($argv[1] ?? 0);
$projectId = (int) ($argv[2] ?? 0);
$origins = (string) ($argv[3] ?? '');
$syncDir = (string) ($argv[4] ?? '');
$resultPath = (string) ($argv[5] ?? '');
$workerId = (string) ($argv[6] ?? 'worker');

if ($userId <= 0 || $projectId <= 0 || $syncDir === '' || $resultPath === '') {
    file_put_contents($resultPath, 'invalid_args');

    exit(2);
}

file_put_contents($syncDir.DIRECTORY_SEPARATOR.$workerId.'.ready', '1');

while (! is_file($syncDir.DIRECTORY_SEPARATOR.'go')) {
    usleep(1000);
}

try {
    $user = User::query()->findOrFail($userId);
    $project = KaraokeProject::query()->findOrFail($projectId);

    app(KaraokeProjectShareService::class)->enableEmbedding(
        $project,
        $user,
        [$origins],
    );

    file_put_contents($resultPath, 'enabled');
} catch (ValidationException) {
    file_put_contents($resultPath, 'validation_error');
} catch (\Throwable $exception) {
    file_put_contents($resultPath, 'error:'.$exception->getMessage());
}
