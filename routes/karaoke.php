<?php

use App\Http\Controllers\KaraokeEmbedController;
use App\Http\Controllers\KaraokeProjectController;
use App\Http\Controllers\KaraokeProjectEditorController;
use App\Http\Controllers\KaraokeProjectProcessingController;
use App\Http\Controllers\KaraokeProjectShareController;
use App\Http\Controllers\KaraokeProjectShareEmbedController;
use App\Http\Controllers\KaraokePublicShareController;
use Illuminate\Support\Facades\Route;

Route::prefix('karaoke/embed')
    ->name('karaoke.embed.')
    ->group(function (): void {
        Route::get('/{share}/{token}', [KaraokeEmbedController::class, 'show'])
            ->middleware('throttle:karoks-public-embed')
            ->name('show');
    });

Route::prefix('karaoke/shared')
    ->name('karaoke.shared.')
    ->middleware(['karoks.public-share-headers'])
    ->group(function (): void {
        Route::get('/{share}/{token}', [KaraokePublicShareController::class, 'show'])
            ->middleware('throttle:karoks-public-player')
            ->name('show');

        Route::match(['get', 'head'], '/{share}/{token}/audio', [KaraokePublicShareController::class, 'audio'])
            ->middleware('throttle:karoks-public-audio')
            ->name('audio');
    });

Route::middleware('auth')
    ->prefix('karaoke')
    ->name('karaoke.projects.')
    ->group(function (): void {
        Route::get('/', [KaraokeProjectController::class, 'index'])->name('index');
        Route::get('/create', [KaraokeProjectController::class, 'create'])->name('create');
        Route::post('/', [KaraokeProjectController::class, 'store'])->name('store');
        Route::get('/{karaokeProject}/edit', [KaraokeProjectEditorController::class, 'edit'])->name('edit');
        Route::patch('/{karaokeProject}', [KaraokeProjectEditorController::class, 'update'])
            ->middleware('throttle:karoks-editor')
            ->name('update');
        Route::get('/{karaokeProject}/export', [KaraokeProjectEditorController::class, 'export'])->name('export');
        Route::post('/{karaokeProject}/import', [KaraokeProjectEditorController::class, 'import'])
            ->middleware('throttle:karoks-editor')
            ->name('import');
        Route::post('/{karaokeProject}/process', [KaraokeProjectProcessingController::class, 'process'])
            ->middleware('throttle:karoks-processing')
            ->name('process');
        Route::post('/{karaokeProject}/cancel', [KaraokeProjectProcessingController::class, 'cancel'])
            ->middleware('throttle:karoks-processing')
            ->name('cancel');
        Route::post('/{karaokeProject}/retry', [KaraokeProjectProcessingController::class, 'retry'])
            ->middleware('throttle:karoks-processing')
            ->name('retry');
        Route::get('/{karaokeProject}/status', [KaraokeProjectProcessingController::class, 'status'])
            ->middleware('throttle:karoks-processing')
            ->name('status');
        Route::post('/{karaokeProject}/share', [KaraokeProjectShareController::class, 'store'])
            ->middleware('throttle:karoks-share-manage')
            ->name('share.store');
        Route::post('/{karaokeProject}/share/rotate', [KaraokeProjectShareController::class, 'rotate'])
            ->middleware('throttle:karoks-share-manage')
            ->name('share.rotate');
        Route::delete('/{karaokeProject}/share', [KaraokeProjectShareController::class, 'destroy'])
            ->name('share.destroy');
        Route::patch('/{karaokeProject}/share/embed', [KaraokeProjectShareEmbedController::class, 'update'])
            ->middleware('throttle:karoks-share-manage')
            ->name('share.embed.update');
        Route::delete('/{karaokeProject}/share/embed', [KaraokeProjectShareEmbedController::class, 'destroy'])
            ->middleware('throttle:karoks-share-manage')
            ->name('share.embed.destroy');
        Route::get('/{karaokeProject}/player', [KaraokeProjectController::class, 'player'])->name('player');
        Route::match(['get', 'head'], '/{karaokeProject}/audio', [KaraokeProjectController::class, 'audio'])->name('audio');
        Route::get('/{karaokeProject}/source', [KaraokeProjectController::class, 'source'])->name('source');
        Route::get('/{karaokeProject}', [KaraokeProjectController::class, 'show'])->name('show');
        Route::delete('/{karaokeProject}', [KaraokeProjectController::class, 'destroy'])->name('destroy');
    });
