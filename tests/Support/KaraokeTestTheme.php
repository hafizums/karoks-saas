<?php

namespace Tests\Support;

use DevDojo\Themes\Models\Theme;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Laravel\Folio\Folio;

class KaraokeTestTheme
{
    public static function register(): void
    {
        $theme = Theme::query()->where('active', true)->first()
            ?? Theme::query()->where('folder', 'anchor')->first();

        $folder = $theme?->folder ?? 'anchor';
        $themePath = resource_path('themes/'.$folder);

        if (! is_dir($themePath)) {
            return;
        }

        view()->addNamespace('theme', $themePath);
        Blade::anonymousComponentPath($themePath.'/components/elements');
        Blade::anonymousComponentPath($themePath.'/components');

        $pagesPath = $themePath.'/pages';

        if (is_dir($pagesPath) && ! Route::has('changelogs')) {
            Folio::path($pagesPath);
        }
    }
}
