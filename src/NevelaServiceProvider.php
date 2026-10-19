<?php

namespace Nevela\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Nevela\Laravel\Access\Access;
use Nevela\Laravel\Access\Permissions;
use Nevela\Laravel\Console\AdoptCommand;
use Nevela\Laravel\Console\BlogCommand;
use Nevela\Laravel\Console\DevCommand;
use Nevela\Laravel\Console\DigestCommand;
use Nevela\Laravel\Console\DisposableCommand;
use Nevela\Laravel\Console\GenerateCommand;
use Nevela\Laravel\Console\MakeResourceCommand;
use Nevela\Laravel\Console\SeedCommand;
use Nevela\Laravel\Console\SetupCommand;
use Nevela\Laravel\Console\StatusCommand;
use Nevela\Laravel\Console\TrashCommand;
use Nevela\Laravel\Console\UpdateCommand;
use Nevela\Laravel\Console\UserCommand;
use Nevela\Laravel\Console\VersionCommand;
use Nevela\Laravel\Http\Access\DeletedAccountsController;
use Nevela\Laravel\Http\Access\RolesAreSetUp;
use Nevela\Laravel\Http\Access\RolesController;
use Nevela\Laravel\Http\Access\UsersController;
use Nevela\Laravel\Http\FlareErrors;
use Nevela\Laravel\Http\FormsController;
use Nevela\Laravel\Http\Auth\AccountController;
use Nevela\Laravel\Http\Auth\SecurityController;
use Nevela\Laravel\Http\Auth\SignInController;
use Nevela\Laravel\Http\Auth\SocialController;
use Nevela\Laravel\Http\PublicThrottle;
use Nevela\Laravel\Http\TokenController;
use Nevela\Laravel\Http\ActionController;
use Nevela\Laravel\Http\TrashController;
use Nevela\Laravel\Http\UploadController;
use Nevela\Laravel\Media\Uploads;
use Nevela\Laravel\Support\SchemaFacts;

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
                MakeResourceCommand::class, AdoptCommand::class, BlogCommand::class, GenerateCommand::class, SeedCommand::class, UserCommand::class,
                UpdateCommand::class, DevCommand::class, StatusCommand::class, VersionCommand::class, SetupCommand::class,
                TrashCommand::class, DigestCommand::class, DisposableCommand::class,
            ]);

            // What has been in the trash too long is removed each night, wherever the app's
            // scheduler is running (Laravel's one cron line). Where it isn't, the same thing
            // happens whenever someone opens the trash.
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->command(TrashCommand::class)->dailyAt('03:40')->withoutOverlapping();
                // The notices to the owner that were held back in a busy hour, as one email.
                $schedule->command(DigestCommand::class)->hourly()->withoutOverlapping();
            });
        }

        $this->callAfterResolving(ExceptionHandler::class, function ($handler) {
            if (method_exists($handler, 'renderable')) {
                $handler->renderable(fn (\Throwable $e, $request) => FlareErrors::render($e, $request));
            }
        });

        // Which tables and columns are there is remembered across requests (SchemaFacts).
        // Migrations change that, so it is forgotten when they start and again when they
        // end: a request in between may have found something the next migration removes.
        // `optimize:clear` forgets it too, for a table or column removed some other way.
        Event::listen([MigrationsStarted::class, MigrationsEnded::class], fn () => SchemaFacts::forget());
        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            if ($event->command === 'optimize:clear') {
                SchemaFacts::forget();
            }
        });

        // Octane and queue workers keep one app for many requests and jobs, where PHP-FPM
        // starts each request afresh. What Nevela keeps for one request is let go of at the
        // start of the next. (Octane's events are named, not imported: it may not be installed.)
        Event::listen([
            'Laravel\Octane\Events\RequestReceived', 'Laravel\Octane\Events\TaskReceived', 'Laravel\Octane\Events\TickReceived', JobProcessing::class,
        ], fn () => self::newRequest());

        // An ability shaped like a permission ("products.view") is answered from the
        // person's roles. So `$user->can('products.view')`, `Gate::authorize(...)` and
        // `@can` all work, in a policy or anywhere else. Any other ability is left to
        // the app's own policies and gates.
        Gate::before(fn ($user, $ability) => is_string($ability) && Permissions::isPermission($ability) ? Access::allows($user, $ability) : null);

        if (! $this->app->routesAreCached()) {
            $this->registerRoutes();
        }
    }

    /**
     * What a new request starts without, in a process that served one before: whose roles
     * grant what, the uploads looked up for a page, and which tables and columns are there
     * (read again from bootstrap/cache, which another process may have cleared).
     */
    public static function newRequest(): void
    {
        Access::forget();
        Uploads::forget();
        SchemaFacts::refresh();
    }

    /**
     * Ten tries a minute at one account is plenty for a person and useless for guessing.
     *
     * The count is kept per account (or per sign-in that is under way), not per address
     * alone: the dashboard's server makes these calls, so to Laravel every person arrives
     * from the same address, and one limit for all of them would lock everyone out
     * together. A second, much higher limit per address caps what any one caller can do.
     */
    private function limitAttempts(): void
    {
        RateLimiter::for('nevela-auth', function (Request $request) {
            $who = $request->input('email') ?: $request->input('challenge') ?: $request->input('token') ?: $request->user('sanctum')?->getAuthIdentifier();
            $limits = [Limit::perMinute((int) config('nevela.auth.attempts_per_address', 300))->by('nevela-auth-address:'.$request->ip())];
            if (is_scalar($who) && (string) $who !== '') {
                $limits[] = Limit::perMinute(10)->by('nevela-auth:'.sha1(mb_strtolower((string) $who)));
            }

            return $limits;
        });
    }

    private function registerRoutes(): void
    {
        $this->limitAttempts();

        $prefix = config('nevela.prefix', 'api');

        if (config('nevela.auth.enabled', true)) {
            Route::prefix($prefix.'/auth')->middleware('api')->name('nevela.auth.')->group(function () {
                Route::get('config', [SignInController::class, 'config'])->name('config');

                // Anything that takes a secret, or sends an email, is slowed down (see limitAttempts()).
                Route::middleware('throttle:nevela-auth')->group(function () {
                    Route::post('token', [SignInController::class, 'password'])->name('store');
                    Route::post('two-factor/send', [SignInController::class, 'sendSecondStep'])->name('two-factor.send');
                    Route::post('two-factor/verify', [SignInController::class, 'secondStep'])->name('two-factor.verify');
                    Route::post('magic-link', [SignInController::class, 'sendLink'])->name('magic-link.send');
                    Route::post('magic-link/verify', [SignInController::class, 'link'])->name('magic-link.verify');
                    Route::post('email-code', [SignInController::class, 'sendCode'])->name('email-code.send');
                    Route::post('email-code/verify', [SignInController::class, 'code'])->name('email-code.verify');
                    Route::post('passkey/options', [SignInController::class, 'passkeyOptions'])->name('passkey.options');
                    Route::post('passkey', [SignInController::class, 'passkey'])->name('passkey.verify');
                    // Google and GitHub: called by the dashboard's server, on the way out and on the way back.
                    Route::post('oauth/{provider}/start', [SocialController::class, 'start'])->name('oauth.start');
                    Route::post('oauth/{provider}/callback', [SocialController::class, 'callback'])->name('oauth.callback');
                    Route::post('register', [AccountController::class, 'register'])->name('register');
                    Route::post('email/send', [AccountController::class, 'resendVerification'])->name('email.send');
                    Route::post('email/verify', [AccountController::class, 'verifyEmail'])->name('email.verify');
                    Route::post('password/forgot', [AccountController::class, 'forgotPassword'])->name('password.forgot');
                    Route::post('password/reset', [AccountController::class, 'resetPassword'])->name('password.reset');
                });

                Route::middleware('auth:sanctum')->group(function () {
                    Route::get('me', [TokenController::class, 'show'])->name('show');
                    Route::patch('me', [AccountController::class, 'update'])->name('update');
                    Route::put('avatar', [AccountController::class, 'avatar'])->name('avatar');
                    Route::delete('token', [TokenController::class, 'destroy'])->name('destroy');
                    Route::post('password', [AccountController::class, 'changePassword'])->middleware('throttle:nevela-auth')->name('password.change');
                    Route::post('close', [AccountController::class, 'close'])->middleware('throttle:nevela-auth')->name('close');

                    Route::get('sessions', [AccountController::class, 'sessions'])->name('sessions');
                    Route::delete('sessions', [AccountController::class, 'revokeOtherSessions'])->name('sessions.revoke-others');
                    Route::delete('sessions/{id}', [AccountController::class, 'revokeSession'])->name('sessions.revoke');

                    Route::middleware('throttle:nevela-auth')->group(function () {
                        Route::post('two-factor/enable', [SecurityController::class, 'enable'])->name('two-factor.enable');
                        Route::post('two-factor/confirm', [SecurityController::class, 'confirm'])->name('two-factor.confirm');
                        Route::post('two-factor/disable', [SecurityController::class, 'disable'])->name('two-factor.disable');
                        Route::post('two-factor/backup-codes', [SecurityController::class, 'regenerateBackupCodes'])->name('two-factor.backup-codes');
                    });

                    Route::get('social', [SocialController::class, 'accounts'])->name('social');
                    Route::delete('social/{id}', [SocialController::class, 'disconnect'])->name('social.disconnect');

                    Route::get('passkeys', [SecurityController::class, 'passkeys'])->name('passkeys');
                    Route::post('passkeys/options', [SecurityController::class, 'passkeyOptions'])->name('passkeys.options');
                    Route::post('passkeys', [SecurityController::class, 'addPasskey'])->name('passkeys.add');
                    Route::patch('passkeys/{id}', [SecurityController::class, 'renamePasskey'])->name('passkeys.rename');
                    Route::delete('passkeys/{id}', [SecurityController::class, 'removePasskey'])->name('passkeys.remove');
                });
            });
        }

        // Open to anyone, and says only that this is a Nevela app and which one. It is how the
        // dashboard and `nevela:status` tell this app from another program on the same port.
        Route::prefix($prefix)->middleware(['api', 'throttle:60,1'])->get('_nevela/ping', fn () => response()->json([
            'nevela' => Nevela::VERSION,
            'app' => Nevela::fingerprint(),
        ]))->name('nevela.ping');

        Route::aliasMiddleware('nevela.throttle', PublicThrottle::class);

        // What a resource's "public" section opens: no sign-in, and each route its own throttle.
        if (is_file($open = base_path('routes/nevela-public.php'))) {
            Route::prefix(trim($prefix.'/public', '/'))->middleware('api')->name('nevela.public.')->group($open);
        }

        // Stored files. Open, like any image on a website: the key is the secret.
        Route::prefix($prefix)->middleware('api')->get('_nevela/files/{path}', [UploadController::class, 'show'])
            ->where('path', '.*')->name('nevela.files.show');

        Route::prefix($prefix)->middleware(config('nevela.middleware', ['api', 'auth:sanctum']))->name('nevela.')->group(function () {
            Route::put('_nevela/uploads/{resource}/{field}', [UploadController::class, 'store'])->name('uploads.store');
            // A private file: only for someone who may view the record it belongs to.
            Route::get('_nevela/private/{slug}/{id}/{field}', [UploadController::class, 'download'])->name('private.show');

            // The image profiles, for a client that wants to know which renditions exist.
            Route::get('_nevela/profiles', fn () => response()->json([
                'data' => array_map(fn ($name) => (array) Uploads::profile($name), array_keys((array) config('nevela.uploads.profiles', ['default' => []]))),
            ]))->name('profiles');

            // The trash: deleted records, to restore or to remove for good.
            Route::get('_nevela/trash', [TrashController::class, 'index'])->name('trash.index');
            Route::get('_nevela/trash/_status', [TrashController::class, 'status'])->name('trash.status');
            Route::get('_nevela/trash/{slug}', [TrashController::class, 'show'])->name('trash.show');
            Route::post('_nevela/trash/{slug}/{id}/restore', [TrashController::class, 'restore'])->name('trash.restore');
            Route::delete('_nevela/trash/{slug}/{id}', [TrashController::class, 'destroy'])->name('trash.destroy');
            Route::delete('_nevela/trash/{slug}', [TrashController::class, 'empty'])->name('trash.empty');

            // A public form, from the inside: what it checks and what it has turned away.
            Route::get('_nevela/forms/{slug}', [FormsController::class, 'show'])->name('forms.show');

            // Users and roles, for whoever holds the permissions to manage them.
            Route::middleware(RolesAreSetUp::class)->group(function () {
                Route::get('_nevela/users', [UsersController::class, 'index'])->name('users.index');
                Route::post('_nevela/users', [UsersController::class, 'store'])->name('users.store');
                Route::get('_nevela/users/{id}', [UsersController::class, 'show'])->name('users.show');
                Route::patch('_nevela/users/{id}', [UsersController::class, 'update'])->name('users.update');
                Route::delete('_nevela/users/{id}', [UsersController::class, 'destroy'])->name('users.destroy');
                Route::delete('_nevela/users/{id}/sessions', [UsersController::class, 'revokeSessions'])->name('users.sessions.revoke');

                // Accounts that were closed, and the emails that can't have a new one.
                Route::get('_nevela/deleted-accounts', [DeletedAccountsController::class, 'index'])->name('deleted-accounts.index');
                Route::post('_nevela/deleted-accounts/{id}/restore', [DeletedAccountsController::class, 'restore'])->name('deleted-accounts.restore');
                Route::delete('_nevela/deleted-accounts/{id}', [DeletedAccountsController::class, 'destroy'])->name('deleted-accounts.destroy');
                Route::get('_nevela/blocked-emails', [DeletedAccountsController::class, 'blocked'])->name('blocked-emails.index');
                Route::delete('_nevela/blocked-emails/{id}', [DeletedAccountsController::class, 'allow'])->name('blocked-emails.allow');

                Route::get('_nevela/permissions', [RolesController::class, 'catalog'])->name('permissions');
                Route::get('_nevela/roles', [RolesController::class, 'index'])->name('roles.index');
                Route::post('_nevela/roles', [RolesController::class, 'store'])->name('roles.store');
                Route::get('_nevela/roles/{id}', [RolesController::class, 'show'])->name('roles.show');
                Route::patch('_nevela/roles/{id}', [RolesController::class, 'update'])->name('roles.update');
                Route::delete('_nevela/roles/{id}', [RolesController::class, 'destroy'])->name('roles.destroy');
            });

            // The descriptors, so the web app (and tooling) can check it matches the API.
            Route::get('_nevela/resources', fn () => response()->json([
                'data' => array_map(fn ($d) => $d->toArray(), Nevela::all()),
            ]))->name('resources');

            // A resource's own actions: on one record, and on the list or a selection from it.
            Route::post('{slug}/_actions/{action}', [ActionController::class, 'collection'])->name('actions.collection');
            Route::post('{slug}/{id}/_actions/{action}', [ActionController::class, 'record'])->name('actions.record');

            if (is_file($routes = base_path('routes/nevela.php'))) {
                require $routes;
            }
        });
    }
}
