<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Nevela\Laravel\Access\Access;
use Nevela\Laravel\Auth\Account;
use Nevela\Laravel\Auth\Social;
use Nevela\Laravel\Support\QueueProbe;
use Throwable;

/**
 * What a deployed app needs and a local one doesn't, checked in one go on the server: run
 * it after a deploy (`php artisan nevela:deploy:check`). Each problem says what to change.
 *
 * It checks this Laravel app, then asks the dashboard about itself over its health route,
 * with the secret the two share: whether it reaches this API, whether its secret is this
 * one, and the address it puts in links.
 */
final class DeployCheckCommand extends Command
{
    protected $signature = 'nevela:deploy:check
        {--wait=15 : How many seconds to give a queue worker to take a test job}
        {--no-dashboard : Leave out the questions to the dashboard}';

    protected $description = 'Check a deployed app: environment, migrations, cache, scheduler, queue worker, mail, administrators and the dashboard';

    /** Where the scheduler leaves the time it last ran (every five minutes). */
    public const HEARTBEAT = 'nevela:scheduler:ran-at';

    private int $problems = 0;

    public function handle(): int
    {
        // The command object is kept between calls in one process: count afresh each time.
        $this->problems = 0;
        $production = app()->isProduction();
        $this->newLine();
        if (! $production) {
            $this->components->warn('APP_ENV is '.app()->environment().'. These checks are for the server: some only apply in production.');
        }

        $this->section('This app');
        // The server's PHP, which can differ from the one the app was built and tested with.
        $this->check('PHP', version_compare(PHP_VERSION, \Nevela\Laravel\Nevela::PHP_MINIMUM, '>='), PHP_VERSION, PHP_VERSION.': Nevela runs on PHP '.substr(\Nevela\Laravel\Nevela::PHP_MINIMUM, 0, 3).' or newer. On Laravel Cloud, choose it under the environment\'s Runtime; on Sevalla, "php": "^8.5" in composer.json');
        $this->check('APP_KEY', (string) config('app.key') !== '', 'set', 'not set: php artisan key:generate --show, and put it in the environment');
        $this->check('APP_DEBUG', ! ($production && config('app.debug')), $production ? 'off' : (config('app.debug') ? 'on (fine here, never in production)' : 'off'), 'on in production: every error page shows the code and settings behind it. Set APP_DEBUG=false');
        $this->migrations();
        $this->cache();
        $this->scheduler();
        $this->queue();
        $this->mail($production);

        $this->section('Signing in');
        if (! \Nevela\Laravel\Auth\Web::exists()) {
            // An API and a mobile app: emails carry codes, and nothing stands between the phone and the API.
            $this->check('Dashboard', true, 'none (NEVELA_DASHBOARD=false): emails carry codes, not links', '');
        } else {
            $web = rtrim((string) config('nevela.auth.web_url'), '/');
            $local = \Nevela\Laravel\Notices\Conditions::isLocal($web);
            $this->check('NEVELA_WEB_URL', ! $production || (! $local && str_starts_with($web, 'https://')), $web, $local ? "{$web}: set it to the dashboard's address, as people reach it. Links in emails and passkeys use it" : "{$web}: the dashboard must be served over https in production");
            // The staff's admin app, where the project has one beside the public site.
            $admin = rtrim((string) config('nevela.auth.admin_url'), '/');
            if ($admin !== '') {
                $adminLocal = \Nevela\Laravel\Notices\Conditions::isLocal($admin);
                $this->check('NEVELA_ADMIN_URL', ! $production || (! $adminLocal && str_starts_with($admin, 'https://') && $admin !== $web), $admin, match (true) {
                    $adminLocal => "{$admin}: set it to the admin app's address, as staff reach it",
                    $admin === $web => "{$admin}: the same as NEVELA_WEB_URL. The admin app has an address of its own, such as https://admin.example.com",
                    default => "{$admin}: the admin app must be served over https in production",
                });
            }
            $secret = (string) config('nevela.auth.proxy_secret');
            $this->check('NEVELA_PROXY_SECRET', $secret !== '' || ! $production, $secret !== '' ? 'set' : 'not set (fine here)', 'not set: without it every device is listed with the dashboard server\'s address, and public forms count every visitor as one. Set the same value in both apps');
        }
        $admins = Access::ready() ? Access::administratorEmails() : [];
        $this->check('Administrators', $admins !== [], implode(', ', array_slice($admins, 0, 3)).(count($admins) > 3 ? ' and '.(count($admins) - 3).' more' : ''), 'none can sign in: php artisan nevela:user --role=ADMIN owner@example.com');

        if (isset($web, $secret) && ! $this->option('no-dashboard')) {
            // The triple tier: the site at NEVELA_WEB_URL and the staff's admin at NEVELA_ADMIN_URL, each checked.
            $adminUrl = rtrim((string) config('nevela.auth.admin_url'), '/');
            $this->section($adminUrl !== '' ? 'The site' : 'The dashboard');
            $this->dashboard($web, $secret);
            if ($adminUrl !== '') {
                $this->section('The admin');
                $this->dashboard($adminUrl, $secret, admin: true, site: $web);
                $this->staffRules($adminUrl, $web);
            }
        }

        $this->newLine();
        if ($this->problems > 0) {
            $this->components->error($this->problems === 1 ? 'One thing to fix, marked in red above.' : "{$this->problems} things to fix, marked in red above.");

            return self::FAILURE;
        }
        $this->components->info('Ready: everything checked looks right.');

        return self::SUCCESS;
    }

    private function migrations(): void
    {
        try {
            DB::connection()->getPdo();
            $migrator = app('migrator');
            $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];
            $files = array_keys($migrator->getMigrationFiles([...$migrator->paths(), database_path('migrations')]));
            $pending = array_values(array_diff($files, $ran));
        } catch (Throwable $e) {
            $this->check('Database', false, '', "can't connect: ".Str::limit($e->getMessage(), 160));

            return;
        }
        // Neon's pooled address can't run a migration with several statements in one go.
        $pooled = str_contains((string) config('database.connections.'.config('database.default').'.host'), '-pooler');
        $this->check('Migrations', $pending === [], count($ran).' ran, none pending', count($pending).' pending: php artisan migrate --force'.($pooled ? '. Through a pooled database address they can fail: run them over the direct one' : ''));
    }

    private function cache(): void
    {
        $store = (string) config('cache.default');
        $driver = (string) config("cache.stores.{$store}.driver");
        // Sign-in codes, links, rate limits and the second step are kept in the cache between requests.
        $this->check('Cache', ! in_array($driver, ['array', 'null'], true), "{$store} ({$driver})", "{$store} ({$driver}) keeps nothing between requests: sign-in codes and links never work, and rate limits don't count. Set CACHE_STORE=database or redis");
    }

    private function scheduler(): void
    {
        $at = Cache::get(self::HEARTBEAT);
        $ago = is_int($at) ? time() - $at : null;
        $this->check(
            'Scheduler',
            $ago !== null && $ago <= 15 * 60,
            $ago === null ? '' : 'ran '.($ago < 60 ? 'under a minute' : intdiv($ago, 60).' min').' ago',
            ($ago === null ? 'no sign of it' : 'last ran '.intdiv($ago, 60).' min ago').
                '. It removes the trash and ended sign-ins each night and sends the hourly digest. '.self::howToRunTheScheduler().' (It leaves a sign every 5 minutes.)',
        );
    }

    /**
     * The ways to run the scheduler, the likeliest first. A container host (Sevalla, Railway,
     * Fly.io, Render) often has no cron, and a second process there can't always write where
     * the web process reads: SQLite or the database cache on a disk only one process holds.
     * So the scheduler runs in the web process's own container, started beside it.
     */
    public static function howToRunTheScheduler(): string
    {
        $cloud = 'On Laravel Cloud, switch on the scheduler for the app.';
        $cron = 'On a server with cron, add the line: * * * * * php artisan schedule:run.';
        $container = 'On a host with no cron, run it beside the web server in the start command: (php artisan schedule:work &) && <start the web server>.';

        return implode(' ', self::inContainer() ? [$cloud, $container, $cron] : [$cloud, $cron, $container]);
    }

    /** Whether this runs in a container, where there is seldom a crontab. */
    private static function inContainer(): bool
    {
        return is_file('/.dockerenv') || is_file('/run/.containerenv') || getenv('KUBERNETES_SERVICE_HOST') !== false;
    }

    private function queue(): void
    {
        $connection = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$connection}.driver", $connection);
        // These run a job without a worker. A deferred one runs after the request, or this
        // command, has finished, so waiting for it here would only ever see nothing.
        if (in_array($driver, ['sync', 'deferred', 'background'], true)) {
            $this->note('Queue', match ($driver) {
                'sync' => "{$connection}: queued mail (form notices, confirmations) is sent while the visitor waits. It works; a worker is better",
                'deferred' => "{$connection}: queued mail is sent after each answer, by the same process. It needs no worker; a worker is sturdier",
                default => "{$connection}: each job runs in a process of its own, and needs no worker",
            });

            return;
        }
        if ($driver === 'null') {
            $this->check('Queue', false, '', "{$connection} throws every job away: queued mail is never sent. Set QUEUE_CONNECTION=database or redis, and run a worker");

            return;
        }

        $token = Str::random(24);
        try {
            app('queue')->connection($connection)->push(new QueueProbe($token));
        } catch (Throwable $e) {
            $this->check('Queue', false, '', "a job couldn't be queued on {$connection}: ".Str::limit($e->getMessage(), 160));

            return;
        }
        $deadline = microtime(true) + max(0, (int) $this->option('wait'));
        do {
            if (Cache::get(QueueProbe::key($token)) === true) {
                $this->check('Queue worker', true, "took a test job on {$connection}", '');

                return;
            }
            usleep(500_000);
        } while (microtime(true) < $deadline);

        $this->check('Queue worker', false, '', "none took a test job on {$connection} within {$this->option('wait')} s: queued mail (form notices, confirmations) waits until one does. On Laravel Cloud, add a queue worker to the app; elsewhere, keep php artisan queue:work running");
    }

    private function mail(bool $production): void
    {
        $mailer = (string) config('mail.default');
        $transport = (string) config("mail.mailers.{$mailer}.transport", $mailer);
        $from = (string) config('mail.from.address');
        if ($production && in_array($transport, ['log', 'array'], true)) {
            $this->check('Mail', false, '', "{$mailer} writes emails to the log instead of sending them: sign-in links, codes and password resets never arrive. Set MAIL_MAILER and its settings");
        } elseif ($transport === 'smtp' && (string) config("mail.mailers.{$mailer}.host") === '') {
            $this->check('Mail', false, '', 'smtp, with no MAIL_HOST');
        } elseif ($transport === 'resend' && (string) (config("mail.mailers.{$mailer}.key") ?? config('services.resend.key')) === '') {
            $this->check('Mail', false, '', 'resend, with no RESEND_API_KEY: get one at resend.com/api-keys');
        } elseif ($transport === 'resend' && ! class_exists(\Resend::class)) {
            $this->check('Mail', false, '', 'resend, without its package: composer require resend/resend-php');
        } else {
            $this->check('Mail', true, $transport.($from !== '' ? ", from {$from}" : ''), '');
        }
        if ($production && in_array($from, ['', 'hello@example.com'], true)) {
            $this->note('Mail from', ($from === '' ? 'not set' : $from).': set MAIL_FROM_ADDRESS to an address of yours, or mail may be refused as spam');
        }
    }

    /** Ask the dashboard what it sees, with the secret the two share. */
    /**
     * One of the web apps, as it answers its health check. `$admin`: the staff's admin app
     * of the triple tier, whose link to the site (NEXT_PUBLIC_SITE_URL) is where customers who
     * sign in there are sent, so it has to be `$site`.
     */
    private function dashboard(string $web, string $secret, bool $admin = false, ?string $site = null): void
    {
        $setting = $admin ? 'NEVELA_ADMIN_URL' : 'NEVELA_WEB_URL';
        try {
            $response = Http::timeout(10)->acceptJson()
                ->withHeaders($secret !== '' ? ['X-Nevela-Proxy-Secret' => $secret] : [])
                ->get("{$web}/api/nevela/health");
        } catch (Throwable) {
            $this->check('Reachable', false, '', "can't reach {$web}: check {$setting}, and that the app is deployed and running");

            return;
        }
        $code = (string) $response->json('code');
        if ($response->status() === 403 && $code !== '') {
            $this->check('Shared secret', false, '', match ($code) {
                'NO_SECRET' => "the dashboard at {$web} has no NEVELA_PROXY_SECRET: set the same value as this app's",
                'NO_SECRET_SENT' => "this app has no NEVELA_PROXY_SECRET to prove itself with: set the same value as the dashboard's",
                default => "the dashboard's NEVELA_PROXY_SECRET isn't this app's: set the same value in both",
            });

            return;
        }
        if (! $response->successful() || $response->json('nevela') !== 'dashboard') {
            $this->check('Reachable', false, '', "{$web} answered {$response->status()}, and not as a Nevela dashboard: it is older than 0.17 (upgrade it), or {$setting} isn't its address");

            return;
        }
        // Each address answered by the app it names: the admin at NEVELA_ADMIN_URL, the site at NEVELA_WEB_URL.
        $app = (string) $response->json('app');
        if ($admin && $app !== 'admin') {
            $this->check('Which app', false, '', "{$web} is not the admin app (it says ".($app === '' ? 'it is one app doing both' : "\"{$app}\"")."): NEVELA_ADMIN_URL has to be apps/admin's address");

            return;
        }
        if (! $admin && $app === 'admin') {
            $this->check('Which app', false, '', "{$web} is the admin app: NEVELA_WEB_URL has to be the site's address, and the admin's goes in NEVELA_ADMIN_URL");

            return;
        }

        $this->check('Reachable', true, "{$web}, and the secret matches", '');
        $problems = (array) $response->json('problems');
        $this->check('Reaches this API', $problems === [], 'yes', (string) ($problems[0] ?? ''));
        foreach (array_slice($problems, 1) as $problem) {
            $this->check('', false, '', (string) $problem);
        }
        $origin = fn (string $url) => strtolower((string) parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST).(parse_url($url, PHP_URL_PORT) ? ':'.parse_url($url, PHP_URL_PORT) : ''));
        if ($admin) {
            // Built into the admin when it was built: set on the host before building, not after.
            $said = $response->json('siteUrl');
            $right = is_string($said) && $said !== '' && $site !== null && $origin($said) === $origin($site);
            $this->check('Link to the site', $right, (string) $said, is_string($said) && $said !== ''
                ? "NEXT_PUBLIC_SITE_URL in the admin is {$said}, and the site is {$site}: customers who sign in to the admin are sent to {$said}. Set it to {$site} where the admin is built, and build it again"
                : "NEXT_PUBLIC_SITE_URL isn't set where the admin was built, so customers who sign in there are sent to http://localhost:3000. Set it to {$site} and build it again");

            return;
        }
        $site = $response->json('siteUrl');
        if (! is_string($site) || $site === '') {
            $this->note('Site address', "NEXT_PUBLIC_SITE_URL isn't set in the dashboard: links in the blog's feed and shared links say http://localhost:3000. Set it to {$web}");
        } elseif ($origin($site) !== $origin($web)) {
            $this->note('Site address', "NEXT_PUBLIC_SITE_URL is {$site}, and NEVELA_WEB_URL is {$web}: links shared from the blog use {$site}. With a custom domain, set both to it");
        } else {
            $this->check('Site address', true, $site, '');
        }
    }

    /** What the admin app asks of staff, and what Google and GitHub need to know of it. */
    private function staffRules(string $admin, string $site): void
    {
        $this->check('Staff two-step sign-in', true, Account::staffTwoFactorRequired() ? 'required in the admin' : 'not required (NEVELA_STAFF_TWO_FACTOR=false)', '');
        $minutes = (int) config('nevela.auth.session.admin_absolute', 60 * 12);
        $this->check('Staff sign-ins', true, $minutes > 0 ? 'end after '.($minutes % 60 === 0 ? ($minutes / 60).' hours' : "{$minutes} minutes").' in the admin' : 'no limit of their own', '');
        if (Social::available() !== []) {
            $this->note('Google and GitHub', 'register both callback addresses with each provider: '.implode(' and ', array_map(fn ($origin) => "{$origin}/api/auth/oauth/{provider}/callback", [$site, $admin])));
        }
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("  <options=bold>{$title}</>");
    }

    /** Worth knowing, and not a failure. */
    private function note(string $label, string $text): void
    {
        $this->components->twoColumnDetail($label, "<fg=yellow>{$text}</>");
    }

    /** One row: what it is, green when it's right, red with what to change when it isn't. */
    private function check(string $label, bool $ok, string $good, string $bad): void
    {
        if ($ok) {
            $this->components->twoColumnDetail($label, "<fg=green>{$good}</>");

            return;
        }
        $this->problems++;
        $this->components->twoColumnDetail($label, "<fg=red>{$bad}</>");
    }
}
