<?php

use App\Http\Controllers\KaraokeProjectController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')
    ->prefix('karaoke')
    ->name('karaoke.projects.')
    ->group(function (): void {
        Route::get('/', [KaraokeProjectController::class, 'index'])->name('index');
        Route::get('/create', [KaraokeProjectController::class, 'create'])->name('create');
        Route::post('/', [KaraokeProjectController::class, 'store'])->name('store');
        Route::get('/{karaokeProject}', [KaraokeProjectController::class, 'show'])->name('show');
        Route::get('/{karaokeProject}/source', [KaraokeProjectController::class, 'source'])->name('source');
        Route::delete('/{karaokeProject}', [KaraokeProjectController::class, 'destroy'])->name('destroy');
    });
