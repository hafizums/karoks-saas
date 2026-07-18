<?php

use App\Http\Controllers\KaraokeProjectController;
use App\Http\Controllers\KaraokeProjectEditorController;
use Illuminate\Support\Facades\Route;

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
        Route::get('/{karaokeProject}/player', [KaraokeProjectController::class, 'player'])->name('player');
        Route::match(['get', 'head'], '/{karaokeProject}/audio', [KaraokeProjectController::class, 'audio'])->name('audio');
        Route::get('/{karaokeProject}/source', [KaraokeProjectController::class, 'source'])->name('source');
        Route::get('/{karaokeProject}', [KaraokeProjectController::class, 'show'])->name('show');
        Route::delete('/{karaokeProject}', [KaraokeProjectController::class, 'destroy'])->name('destroy');
    });
