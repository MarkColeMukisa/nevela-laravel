<?php

namespace Nevela\Laravel;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Auth\Access\Response as GateResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Nevela\Laravel\Access\Access;
use Nevela\Laravel\Activity\Activity;
use Nevela\Laravel\Access\Permissions;
use Nevela\Laravel\Access\Visibility;
use Nevela\Laravel\Auth\Account;
use Nevela\Laravel\Auth\Sessions;
use Nevela\Laravel\Console\AdoptCommand;
use Nevela\Laravel\Console\BlogCommand;
use Nevela\Laravel\Console\DeployCheckCommand;
use Nevela\Laravel\Console\DevCommand;
use Nevela\Laravel\Console\DigestCommand;
use Nevela\Laravel\Console\DisposableCommand;
use Nevela\Laravel\Console\FieldCommand;
use Nevela\Laravel\Console\GenerateCommand;
use Nevela\Laravel\Console\MakeResourceCommand;
use Nevela\Laravel\Console\OpenApiCommand;
use Nevela\Laravel\Console\PruneSessionsCommand;
use Nevela\Laravel\Console\PublishCommand;
use Nevela\Laravel\Console\RemoveResourceCommand;
use Nevela\Laravel\Console\SeedCommand;
use Nevela\Laravel\Console\SetupCommand;
use Nevela\Laravel\Console\StatusCommand;
use Nevela\Laravel\Console\TrashCommand;
use Nevela\Laravel\Console\UpdateCommand;
use Nevela\Laravel\Console\UserCommand;
use Nevela\Laravel\Console\VersionCommand;
use Nevela\Laravel\Http\Access\BlockedAddressesController;
use Nevela\Laravel\Http\Access\DeletedAccountsController;
use Nevela\Laravel\Http\Access\RolesAreSetUp;
use Nevela\Laravel\Http\Access\RolesController;
use Nevela\Laravel\Http\Access\UsersController;
use Nevela\Laravel\Http\FlareErrors;
use Nevela\Laravel\Http\FormsController;
use Nevela\Laravel\Http\Auth\AccountController;
use Nevela\Laravel\Http\Auth\ConfirmController;
use Nevela\Laravel\Http\Auth\SecurityController;
use Nevela\Laravel\Http\Auth\SignInController;
use Nevela\Laravel\Http\Auth\SocialController;
use Nevela\Laravel\Http\BlockedAddresses;
use Nevela\Laravel\Http\PublicThrottle;
use Nevela\Laravel\Http\ScannerShield;
use Nevela\Laravel\Http\TokenController;
use Nevela\Laravel\Http\ActionController;
use Nevela\Laravel\Http\HistoryController;
use Nevela\Laravel\Http\ActivityController;
use Nevela\Laravel\Http\TrashController;
use Nevela\Laravel\Http\UploadController;
use Nevela\Laravel\Http\NotificationsController;
use Nevela\Laravel\Http\OpenApiController;
use Nevela\Laravel\Media\Uploads;
use Nevela\Laravel\Notices\Conditions;
use Nevela\Laravel\Notices\Notice;
use Nevela\Laravel\Notices\Notices;
use Nevela\Laravel\Security\Addresses;
use Nevela\Laravel\Support\Fixed;
use Nevela\Laravel\Support\PublishedConfig;
use Nevela\Laravel\Support\SchemaFacts;
use Nevela\Laravel\Support\SqliteDefaults;

final class NevelaServiceProvider extends ServiceProvider
{
    /** What a policy is asked about one record. A visibleTo scope that keeps the record from someone answers them first. */
    private const RECORD_ABILITIES = ['view', 'update', 'delete', 'restore', 'forceDelete'];

    public function register(): void
    {
        // Key by key, not Laravel's one level deep: a published config/nevela.php from an
        // older Nevela has sections without the settings added to them since.
        if (! ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached())) {
            $config = $this->app->make('config');
            $config->set('nevela', PublishedConfig::merge(require __DIR__.'/../config/nevela.php', (array) $config->get('nevela', [])));
        }
        // SQLite that waits for a write instead of failing with "database is locked" (Support\SqliteDefaults).
        // A cached config is set again on each request, so this goes in either way.
        $config = $this->app->make('config');
        $config->set('database.connections', SqliteDefaults::apply((array) $config->get('database.connections', []), (array) $config->get('nevela.sqlite', [])));
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
                MakeResourceCommand::class, FieldCommand::class, AdoptCommand::class, BlogCommand::class, GenerateCommand::class, SeedCommand::class, UserCommand::class,
                UpdateCommand::class, DevCommand::class, StatusCommand::class, VersionCommand::class, SetupCommand::class,
                TrashCommand::class, Console\SecurityCommand::class, DigestCommand::class, DisposableCommand::class, PruneSessionsCommand::class, PublishCommand::class, DeployCheckCommand::class, RemoveResourceCommand::class, OpenApiCommand::class, Console\PushCommand::class,
            ]);

            // What has been in the trash too long is removed each night, wherever the app's
            // scheduler is running (Laravel's one cron line). Where it isn't, the same thing
            // happens whenever someone opens the trash.
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->command(TrashCommand::class)->dailyAt('03:40')->withoutOverlapping();
                // The notices to the owner that were held back in a busy hour, as one email.
                $schedule->command(DigestCommand::class)->hourly()->withoutOverlapping();
                // Sign-ins that have ended, and access tokens long expired.
                $schedule->command(PruneSessionsCommand::class)->dailyAt('03:50')->withoutOverlapping();
                // Blocks that have ended (Security\Addresses).
                $schedule->call(fn () => Addresses::prune())->dailyAt('03:52')->name('nevela:blocked-addresses:prune');
                // A sign of life, so `nevela deploy:check` can tell the scheduler is running.
                $schedule->call(fn () => Cache::forever(DeployCheckCommand::HEARTBEAT, time()))->everyFiveMinutes()->name('nevela:heartbeat');
                // The bell's notices, once kept long enough, and whether a newer Nevela is out.
                $schedule->call(fn () => Notices::prune() + Activity::prune())->dailyAt('03:55')->name('nevela:notifications:prune');
                $schedule->call(fn () => Conditions::lookUpRelease())->dailyAt('04:10')->name('nevela:releases');
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
        // The bell's "migrations haven't been run" goes once they have.
        Event::listen(MigrationsEnded::class, fn () => Conditions::forget());
        // Every bell notice also goes to the phones of the people it is for, as a push. After the
        // answer is sent: nobody waits on Expo. Nothing is asked of Expo for people with no phone.
        // always(): the notice is in the bell whatever the answer was, so its push goes too.
        Event::listen(Events\NoticeSent::class, fn (Events\NoticeSent $sent) => \Illuminate\Support\defer(fn () => Push\Push::send($sent->userIds, $sent->title, $sent->body, $sent->url))->always());

        // The activity log: every write to a resource's records by someone signed in, from
        // Eloquent's own events, written once the response has gone (Activity\Activity).
        foreach (['created', 'updated', 'deleted', 'restored'] as $action) {
            Event::listen("eloquent.{$action}: *", function (string $event, array $payload) use ($action) {
                if (($payload[0] ?? null) instanceof Model) {
                    Activity::noted($action, $payload[0]);
                }
            });
        }
        $this->app->terminating(fn () => Activity::flush());

        // Records that are fixed once made (a placed order): refused when someone signed in
        // changes or deletes one, or its lines, outside an action. See Support\Fixed.
        Event::listen('eloquent.creating: *', fn (string $event, array $payload) => ($payload[0] ?? null) instanceof Model ? Fixed::creating($payload[0]) : null);
        Event::listen('eloquent.created: *', fn (string $event, array $payload) => ($payload[0] ?? null) instanceof Model ? Fixed::created($payload[0]) : null);
        Event::listen(['eloquent.updating: *', 'eloquent.deleting: *'], fn (string $event, array $payload) => ($payload[0] ?? null) instanceof Model ? Fixed::changing($payload[0]) : null);
        // What one request made, it may finish; the next one may not.
        Event::listen(RequestHandled::class, fn () => Fixed::forget());

        // A job that failed for good is something only an administrator can look into.
        // One row per kind of job while unread, however often it fails.
        Event::listen(JobFailed::class, function (JobFailed $event) {
            $name = class_basename($event->job->resolveName());
            Notices::toAdmins(new Notice(
                "A background job failed: {$name}",
                body: Str::limit(trim(strtok($event->exception->getMessage(), "\n") ?: $event->exception::class), 300),
                tone: 'warning',
                kind: 'job.failed',
                group: 'job.failed:'.$event->job->resolveName(),
                many: fn (int $count) => "{$name} failed {$count} times",
            ));
        });
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
        // A record its model's visibleTo scope keeps from someone isn't theirs to see, change
        // or delete, whatever the policy says, and isn't there for them: 404, as for no record.
        Gate::before(fn ($user, $ability, $arguments) => in_array($ability, self::RECORD_ABILITIES, true) && ($arguments[0] ?? null) instanceof Model && ! Visibility::allows($user, $arguments[0])
            ? GateResponse::denyAsNotFound()
            : null);

        // A token Sanctum finds valid (the expiry of a session's included) is also held to
        // the idle and absolute limits when it belongs to no session. See Auth\Sessions. An
        // app that sets its own callback replaces this one; a session's tokens still expire.
        if (class_exists(Sanctum::class) && method_exists(Sanctum::class, 'authenticateAccessTokensUsing')) {
            Sanctum::authenticateAccessTokensUsing(fn ($token, bool $isValid) => $isValid && Sessions::accepts($token));
        }

        // What the routes refer to by name. Set up on every boot, so a route cache
        // (`route:cache`, `optimize`) finds them: the cache keeps the routes, not these.
        $this->limitAttempts();
        Route::aliasMiddleware('nevela.throttle', PublicThrottle::class);
        // Before anything else runs, whatever the route: blocked addresses turned away (Http\BlockedAddresses),
        // then scanners (Http\ScannerShield). Each is put in front of the last, so the block runs first.
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        $kernel->prependMiddleware(ScannerShield::class);
        $kernel->prependMiddleware(BlockedAddresses::class);

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
        // Anything the last one did that wasn't written when it ended.
        Activity::flush();
        Fixed::forget();
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
            // The visitor's address, never the web app's server (Security\Addresses::of): through the
            // web app, every sign-in would otherwise share one allowance. Null: no address limit.
            $address = Addresses::of($request);
            $limits = $address === null ? [] : [Limit::perMinute((int) config('nevela.auth.attempts_per_address', 300))->by('nevela-auth-address:'.$address)];
            if (is_scalar($who) && (string) $who !== '') {
                // By the address and the account together: keyed on the account alone, anyone sending
                // its email ten times a minute would keep its owner from signing in.
                $limits[] = Limit::perMinute(10)->by('nevela-auth:'.sha1(($address ?? '').'|'.mb_strtolower((string) $who)));
            }

            return $limits;
        });

        // The signed-in API: lists, records, actions and Nevela's own screens. Per person, so a
        // stolen token can't be run as fast as a script likes, and per address, so a script
        // can't spread itself over many accounts. 0 switches either off.
        RateLimiter::for('nevela-api', function (Request $request) {
            $limits = [];
            $person = (int) config('nevela.security.api_per_person', 600);
            $user = $request->user();
            if ($person > 0 && $user !== null) {
                $limits[] = Limit::perMinute($person)->by('nevela-api-person:'.$user->getAuthIdentifier())->response(self::tooMany(...));
            }
            $address = (int) config('nevela.security.api_per_address', 1200);
            // The visitor's address, never the web app's server (Security\Addresses::of): null when
            // the web app can't tell who it is for, and then only the person's own limit applies.
            $from = Addresses::of($request);
            if ($address > 0 && $from !== null) {
                // Beyond it is a strike against the address (the person's own limit isn't: one
                // account's trouble shouldn't block everyone behind an office's address).
                $limits[] = Limit::perMinute($address)->by('nevela-api-address:'.$from)->response(function (Request $request, array $headers) use ($from) {
                    Addresses::strike($from, 'limit');

                    return self::tooMany($request, $headers);
                });
            }

            return $limits ?: Limit::none();
        });
    }

    /** A 429 that says when to try again, in words and in Retry-After. */
    private static function tooMany(Request $request, array $headers): \Illuminate\Http\JsonResponse
    {
        $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));

        return response()->json([
            'error' => "Too many requests at once. Try again in {$seconds} second".($seconds === 1 ? '' : 's').'.',
            'code' => 'TOO_MANY_REQUESTS',
            'retryAfter' => $seconds,
        ], 429, $headers);
    }

    /** Only routes: anything else here would be missing when the routes come from the cache. */
    private function registerRoutes(): void
    {
        $prefix = config('nevela.prefix', 'api');

        if (config('nevela.auth.enabled', true)) {
            Route::prefix($prefix.'/auth')->middleware('api')->name('nevela.auth.')->group(function () {
                Route::get('config', [SignInController::class, 'config'])->name('config');
                // A new access token for a refresh token. Not throttled with the rest: every
                // person using the dashboard refreshes from its server's one address, and a
                // 64-character random token isn't something anyone can guess.
                Route::post('refresh', [TokenController::class, 'refresh'])->name('refresh');

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
                    // The way back from the provider when a mobile app started the sign-in.
                    Route::get('oauth/{provider}/app', [SocialController::class, 'app'])->name('oauth.app');
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
                    // Confirming it's you again, for a request that answered 428 (Auth\Confirmation).
                    Route::middleware('throttle:nevela-auth')->group(function () {
                        Route::post('confirm', [ConfirmController::class, 'confirm'])->name('confirm');
                        Route::post('confirm/send', [ConfirmController::class, 'send'])->name('confirm.send');
                        Route::post('confirm/passkey/options', [ConfirmController::class, 'passkeyOptions'])->name('confirm.passkey');
                    });

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

        // What anyone may read, described: the resources with a "public.read", each cut to the
        // fields it opens. The mobile app reads it to show a shop to someone not signed in, on
        // every launch: phones on one campus Wi-Fi share an address, so the limit is generous.
        Route::prefix($prefix)->middleware(['api', 'nevela.throttle:600,1'])->get('public/_nevela/resources', fn () => response()->json([
            'data' => Nevela::publicDescribed(),
        ]))->name('nevela.public.resources');

        // What a resource's "public" section opens: no sign-in, and each route its own throttle.
        if (is_file($open = base_path('routes/nevela-public.php'))) {
            Route::prefix(trim($prefix.'/public', '/'))->middleware('api')->name('nevela.public.')->group($open);
        }

        // Stored files. Open, like any image on a website: the key is the secret.
        Route::prefix($prefix)->middleware('api')->get('_nevela/files/{path}', [UploadController::class, 'show'])
            ->where('path', '.*')->name('nevela.files.show');
        // A private file by a link that works for a few minutes, for a phone's browser (UploadController::link).
        Route::prefix($prefix)->middleware(['api', 'signed:relative', 'throttle:60,1'])->get('_nevela/private-link/{slug}/{id}/{field}', [UploadController::class, 'signed'])
            ->name('nevela.private.signed');

        // Signed in, and limited (nevela-api, above): after the sign-in, so it knows who is asking.
        $signedIn = [...(array) config('nevela.middleware', ['api', 'auth:sanctum']), 'throttle:nevela-api'];
        Route::prefix($prefix)->middleware($signedIn)->name('nevela.')->group(function () {
            Route::put('_nevela/uploads/{resource}/{field}', [UploadController::class, 'store'])->name('uploads.store');
            // A private file: only for someone who may view the record it belongs to.
            Route::get('_nevela/private/{slug}/{id}/{field}', [UploadController::class, 'download'])->name('private.show');
            Route::post('_nevela/private/{slug}/{id}/{field}/link', [UploadController::class, 'link'])->name('private.link');

            // The image profiles, for a client that wants to know which renditions exist.
            Route::get('_nevela/profiles', fn () => response()->json([
                'data' => array_map(fn ($name) => (array) Uploads::profile($name), array_keys((array) config('nevela.uploads.profiles', ['default' => []]))),
            ]))->name('profiles');

            // The trash: deleted records, to restore or to remove for good.
            // The bell: everyone has one, whatever their roles.
            Route::get('_nevela/notifications', [NotificationsController::class, 'index'])->name('notifications.index');
            Route::post('_nevela/notifications/read', [NotificationsController::class, 'read'])->name('notifications.read');
            Route::get('_nevela/activity', [ActivityController::class, 'index'])->name('activity.index');
            // The shop at a glance, for the home screen: only in an app with a shop.
            Route::get('_nevela/shop-figures', Http\ShopFiguresController::class)->name('shop-figures');
            // The phone the app runs on, for push notifications: registered on signing in, forgotten on signing out.
            Route::put('_nevela/push-tokens', [Http\PushTokenController::class, 'store'])->name('push-tokens.store');
            Route::delete('_nevela/push-tokens/{token}', [Http\PushTokenController::class, 'destroy'])->name('push-tokens.destroy');
            // A record's history: each action done to it, for whoever may see the record.
            Route::get('_nevela/history/{slug}/{id}', [HistoryController::class, 'show'])->name('history.show');

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
                // The addresses turned away (Security\Addresses).
                Route::get('_nevela/blocked-addresses', [BlockedAddressesController::class, 'index'])->name('blocked-addresses.index');
                Route::post('_nevela/blocked-addresses', [BlockedAddressesController::class, 'store'])->name('blocked-addresses.store');
                Route::delete('_nevela/blocked-addresses/{id}', [BlockedAddressesController::class, 'destroy'])->name('blocked-addresses.destroy');

                Route::get('_nevela/permissions', [RolesController::class, 'catalog'])->name('permissions');
                Route::get('_nevela/roles', [RolesController::class, 'index'])->name('roles.index');
                Route::post('_nevela/roles', [RolesController::class, 'store'])->name('roles.store');
                Route::get('_nevela/roles/{id}', [RolesController::class, 'show'])->name('roles.show');
                Route::patch('_nevela/roles/{id}', [RolesController::class, 'update'])->name('roles.update');
                Route::delete('_nevela/roles/{id}', [RolesController::class, 'destroy'])->name('roles.destroy');
            });

            // The descriptors, so the web app (and tooling) can check it matches the API.
            Route::get('_nevela/resources', fn () => response()->json([
                'data' => Nevela::described(),
            ]))->name('resources');
            // The same, as an OpenAPI document: every resource's routes, fields and permissions.
            Route::get('_nevela/openapi.json', OpenApiController::class)->name('openapi');

            // A resource's own actions: on one record, and on the list or a selection from it.
            Route::post('{slug}/_actions/{action}', [ActionController::class, 'collection'])->name('actions.collection');
            Route::post('{slug}/{id}/_actions/{action}', [ActionController::class, 'record'])->name('actions.record');

            if (is_file($routes = base_path('routes/nevela.php'))) {
                require $routes;
            }
        });
    }
}
