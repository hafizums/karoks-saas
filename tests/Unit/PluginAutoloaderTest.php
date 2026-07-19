<?php

use Wave\Plugins\PluginAutoloader;

beforeEach(function (): void {
    foreach (spl_autoload_functions() ?: [] as $loader) {
        if (! $loader instanceof Closure) {
            continue;
        }

        $ref = new ReflectionFunction($loader);

        if (str_contains((string) $ref->getFileName(), 'PluginAutoloader.php')) {
            spl_autoload_unregister($loader);
        }
    }

    $registered = (new ReflectionClass(PluginAutoloader::class))->getProperty('registered');
    $registered->setAccessible(true);
    $registered->setValue(null, false);
});

it('registers the plugin autoloader only once', function () {
    $before = spl_autoload_functions() ?: [];

    PluginAutoloader::register();
    $afterFirst = spl_autoload_functions() ?: [];

    PluginAutoloader::register();
    $afterSecond = spl_autoload_functions() ?: [];

    $pluginClosures = array_filter($afterSecond, function ($loader) {
        if ($loader instanceof Closure) {
            $ref = new ReflectionFunction($loader);

            return str_contains($ref->getFileName(), 'PluginAutoloader.php');
        }

        return false;
    });

    expect(count($pluginClosures))->toBe(1)
        ->and(count($afterFirst))->toBe(count($before) + 1)
        ->and(count($afterSecond))->toBe(count($afterFirst));
});
