<?php

namespace Nevela\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Nevela\Laravel\Console\DevCommand;
use Nevela\Laravel\Console\GenerateCommand;
use Nevela\Laravel\Console\MakeResourceCommand;
use Nevela\Laravel\Console\SeedCommand;
use Nevela\Laravel\Console\SetupCommand;
use Nevela\Laravel\Console\StatusCommand;
use Nevela\Laravel\Console\UpdateCommand;
use Nevela\Laravel\Console\UserCommand;
use Nevela\Laravel\Console\VersionCommand;
use Nevela\Laravel\Http\FlareErrors;
use Nevela\Laravel\Http\TokenController;
use Nevela\Laravel\Http\UploadController;
use Nevela\Laravel\Media\Uploads;

final class NevelaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nevela.php', 'nevela');
    }

    public function boot(): void
    {
        // The table that records uploads. It arrives with `php artisan migrate`.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            // `php artisan serve` starts PHP with a short list of environment variables, and
            // the temporary folder isn't on it. On Windows PHP then has nowhere to buffer a
            // request body, and any upload over 16 KB fails. Passing these through fixes it.
            if (class_exists(ServeCommand::class)) {
                ServeCommand::$passthroughVariables = array_values(array_unique([...ServeCommand::$passthroughVariables, 'TEMP', 'TMP', 'TMPDIR']));
            }

            $this->publishes([__DIR__.'/../config/nevela.php' => config_path('nevela.php')], 'nevela-config');
            $this->commands([
                MakeResourceCommand::class, GenerateCommand::class, SeedCommand::class, UserCommand::class,
                UpdateCommand::class, DevCommand::class, StatusCommand::class, VersionCommand::class, SetupCommand::class,
            ]);
        }

        $this->callAfterResolving(ExceptionHandler::class, function ($handler) {
            if (method_exists($handler, 'renderable')) {
                $handler->renderable(fn (\Throwable $e, $request) => FlareErrors::render($e, $request));
            }
        });

        if (! $this->app->routesAreCached()) {
            $this->registerRoutes();
        }
    }

    private function registerRoutes(): void
    {
        $prefix = config('nevela.prefix', 'api');

        if (config('nevela.auth.enabled', true)) {
            Route::prefix($prefix)->middleware('api')->name('nevela.auth.')->group(function () {
                Route::post('auth/token', [TokenController::class, 'store'])->middleware('throttle:6,1')->name('store');
                Route::middleware('auth:sanctum')->group(function () {
                    Route::get('auth/me', [TokenController::class, 'show'])->name('show');
                    Route::delete('auth/token', [TokenController::class, 'destroy'])->name('destroy');
                });
            });
        }

        // Open to anyone, and says only that this is a Nevela app and which one. It is how the
        // dashboard and `nevela:status` tell this app from another program on the same port.
        Route::prefix($prefix)->middleware(['api', 'throttle:60,1'])->get('_nevela/ping', fn () => response()->json([
            'nevela' => Nevela::VERSION,
            'app' => Nevela::fingerprint(),
        ]))->name('nevela.ping');

        // Stored files. Open, like any image on a website: the key is the secret.
        Route::prefix($prefix)->middleware('api')->get('_nevela/files/{path}', [UploadController::class, 'show'])
            ->where('path', '.*')->name('nevela.files.show');

        Route::prefix($prefix)->middleware(config('nevela.middleware', ['api', 'auth:sanctum']))->name('nevela.')->group(function () {
            Route::put('_nevela/uploads/{resource}/{field}', [UploadController::class, 'store'])->name('uploads.store');

            // The image profiles, for a client that wants to know which renditions exist.
            Route::get('_nevela/profiles', fn () => response()->json([
                'data' => array_map(fn ($name) => (array) Uploads::profile($name), array_keys((array) config('nevela.uploads.profiles', ['default' => []]))),
            ]))->name('profiles');

            // The descriptors, so the web app (and tooling) can check it matches the API.
            Route::get('_nevela/resources', fn () => response()->json([
                'data' => array_map(fn ($d) => $d->toArray(), Nevela::all()),
            ]))->name('resources');

            if (is_file($routes = base_path('routes/nevela.php'))) {
                require $routes;
            }
        });
    }
}
