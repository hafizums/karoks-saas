<?php

namespace App\Providers;

use App\Contracts\KaraokeProcessor;
use App\Listeners\LogSuccessfulLogin;
use App\Listeners\LogSuccessfulLogout;
use App\Support\KaraokeProcessorManager;
use Exception;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/dashboard';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(KaraokeProcessor::class, function ($app) {
            return $app->make(KaraokeProcessorManager::class)->driver();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment() == 'production') {
            $this->app['request']->server->set('HTTPS', true);
        }

        $this->setSchemaDefaultLength();

        // Register activity log event listeners
        Event::listen(Login::class, LogSuccessfulLogin::class);
        Event::listen(Logout::class, LogSuccessfulLogout::class);

        Validator::extend('base64image', function ($attribute, $value, $parameters, $validator) {
            $explode = explode(',', $value);
            $allow = ['png', 'jpg', 'svg', 'jpeg'];
            $format = str_replace(
                [
                    'data:image/',
                    ';',
                    'base64',
                ],
                [
                    '', '', '',
                ],
                $explode[0]
            );

            // check file format
            if (! in_array($format, $allow)) {
                return false;
            }

            // check base64 format
            if (! preg_match('%^[a-zA-Z0-9/+]*={0,2}$%', $explode[1])) {
                return false;
            }

            return true;
        });

        $this->bootRoute();
    }

    private function setSchemaDefaultLength(): void
    {
        try {
            Schema::defaultStringLength(191);
        } catch (Exception $exception) {
        }
    }

    public function bootRoute()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: (request()->header('CF-Connecting-IP') ?? request()->ip()));
        });

        RateLimiter::for('karoks-editor', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('karoks-processing', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('karoks-public-player', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('karoks-public-audio', function (Request $request) {
            $shareId = (string) $request->route('share');
            $token = (string) $request->route('token');
            $credentialKey = hash('sha256', $shareId.':'.$token);

            return Limit::perMinute(240)->by($request->ip().':'.$credentialKey);
        });

        RateLimiter::for('karoks-share-manage', function (Request $request) {
            $userId = $request->user()?->id ?? 'guest';
            $projectKey = (string) ($request->route('karaokeProject')?->public_id ?? 'unknown');

            return Limit::perMinute(10)->by($userId.':'.$projectKey);
        });

        RateLimiter::for('karoks-public-embed', function (Request $request) {
            $shareId = (string) $request->route('share');
            $token = (string) $request->route('token');
            $credentialKey = hash('sha256', $shareId.':'.$token);

            return Limit::perMinute(60)->by($request->ip().':'.$credentialKey);
        });
    }
}
