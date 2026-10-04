<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Support\DashboardState;
use Nevela\Laravel\Support\Releases;
use Throwable;

final class StatusCommand extends Command
{
    protected $signature = 'nevela:status';

    protected $description = 'Check the app: versions, the database, migrations, users, resources and the dashboard';

    public function handle(): int
    {
        $problems = 0;
        $row = fn (string $label, string $value) => $this->components->twoColumnDetail($label, $value);
        $bad = function (string $text) use (&$problems): string {
            $problems++;

            return "<fg=red>{$text}</>";
        };

        $this->newLine();
        $latest = Releases::latest();
        $row('Nevela', Nevela::VERSION.match (true) {
            $latest === null => ' <fg=gray>(couldn\'t check for a newer one)</>',
            version_compare($latest, Nevela::VERSION, '>') => " <fg=yellow>({$latest} is out: php nevela upgrade)</>",
            default => ' <fg=green>(latest)</>',
        });
        $row('Laravel / PHP', app()->version().' / '.PHP_VERSION);

        // --- database ---
        try {
            $connection = DB::connection();
            $connection->getPdo();
            $name = $connection->getDatabaseName();
            $row('Database', $connection->getDriverName().($name ? ' · '.str_replace(base_path().DIRECTORY_SEPARATOR, '', (string) $name) : ''));

            $migrator = app('migrator');
            if (! $migrator->repositoryExists()) {
                $row('Migrations', $bad('none have run: php nevela migrate'));
            } else {
                $ran = $migrator->getRepository()->getRan();
                $files = array_keys($migrator->getMigrationFiles([...$migrator->paths(), database_path('migrations')]));
                $pending = array_values(array_diff($files, $ran));
                $row('Migrations', count($ran).' ran, '.($pending === []
                    ? '<fg=green>none pending</>'
                    : $bad(count($pending).' pending: php nevela migrate')));
                foreach ($pending as $migration) {
                    $this->line("    <fg=gray>pending</> {$migration}");
                }
            }

            $model = config('auth.providers.users.model');
            if (is_string($model) && class_exists($model)) {
                try {
                    $users = $model::query()->count();
                    $row('Users who can sign in', $users > 0 ? (string) $users : $bad('none: php nevela user'));
                } catch (Throwable) {
                    $row('Users who can sign in', $bad('the users table is missing: php nevela migrate'));
                }
            }
        } catch (Throwable $e) {
            $row('Database', $bad('can\'t connect: '.str($e->getMessage())->limit(90)));
        }

        // --- resources ---
        try {
            $resources = Nevela::all();
        } catch (Throwable $e) {
            $resources = [];
            $row('Resources', $bad('a descriptor can\'t be read: '.str($e->getMessage())->limit(90)));
        }
        if ($resources === []) {
            $row('Resources', '<fg=gray>none yet: php nevela resource Product --fields="name:string"</>');
        }
        foreach ($resources as $resource) {
            try {
                $row("Resource: {$resource->name}", number_format(DB::table($resource->table)->count()).' records');
            } catch (Throwable) {
                $row("Resource: {$resource->name}", $bad("the {$resource->table} table is missing: php nevela migrate"));
            }
        }

        // --- dashboard ---
        $web = config('nevela.web_path');
        if (! $web || ! is_dir($web)) {
            $row('Dashboard', '<fg=gray>not found at nevela.web_path</>');
        } else {
            $state = DashboardState::read($web);
            $row('Dashboard', $state->template !== null ? "template {$state->template}" : '<fg=gray>template version not recorded</>');

            // What this app has made its own. An update leaves every one of these alone.
            $changes = $state->yourChanges($web);
            if ($changes === null) {
                $row('Your dashboard changes', '<fg=gray>not tracked yet: php nevela upgrade records them</>');
            } else {
                $count = count($changes['changed']) + count($changes['deleted']);
                $row('Your dashboard changes', $count === 0 ? 'none' : "{$count} file(s), kept on every update");
                if ($this->output->isVerbose()) {
                    foreach ($changes['changed'] as $path) {
                        $this->line("    <fg=gray>changed</> {$path}");
                    }
                    foreach ($changes['deleted'] as $path) {
                        $this->line("    <fg=gray>deleted</> {$path}");
                    }
                } elseif ($count > 0) {
                    $this->line('    <fg=gray>php nevela status -v lists them</>');
                }
            }
            if ($last = end($state->history)) {
                $row('Last dashboard update', "{$last['from']} → {$last['to']} on ".substr((string) ($last['at'] ?? ''), 0, 10));
            }

            // `php nevela dev` may have put the API on another port and told the dashboard so,
            // which is fine. Look for this app wherever it is running before judging.
            $url = self::apiUrl($web);
            $who = self::whoAnswers($url);
            $running = $who === 'this' ? $url : self::findRunning();

            if ($who === 'this') {
                $row('API', "{$url} <fg=green>(running, and it is this app)</>");
            } elseif ($running !== null) {
                $row('API', "{$running} <fg=green>(running, and it is this app)</>");
                $row('', '<fg=gray>'.parse_url($url, PHP_URL_PORT).' belongs to another program; php nevela dev chose a free port</>');
            } elseif ($who === 'nobody') {
                $row('API', '<fg=gray>not running: php nevela dev</>');
            } else {
                $row('API', $bad("{$url} is answered by ".($who === 'nevela' ? 'another Nevela app' : 'another program').', not this app'));
                $row('', '<fg=gray>start with php nevela dev, which picks a free port and tells the dashboard</>');
            }
        }
        $this->newLine();

        if ($problems > 0) {
            $this->components->warn($problems === 1 ? 'One thing needs attention, marked in red above.' : "{$problems} things need attention, marked in red above.");

            return self::FAILURE;
        }
        $this->components->info('Everything looks right.');

        return self::SUCCESS;
    }

    /** Where the dashboard is told the API is: NEVELA_API_URL in its .env.local, else the default. */
    public static function apiUrl(string $web): string
    {
        $env = (string) @file_get_contents(rtrim($web, '/\\').'/.env.local');
        if (preg_match('/^\s*NEVELA_API_URL\s*=\s*["\']?([^"\'\r\n#]+)/m', $env, $m)) {
            return rtrim(trim($m[1]), '/');
        }

        return 'http://127.0.0.1:8000/api';
    }

    /** Who answers at an API address: 'this' app, another 'nevela' app, some 'other' program, or 'nobody'. */
    public static function whoAnswers(string $url): string
    {
        try {
            $response = Http::timeout(3)->acceptJson()->get($url.'/_nevela/ping');
        } catch (Throwable) {
            return 'nobody';
        }
        if ($response->json('app') === Nevela::fingerprint()) {
            return 'this';
        }

        return $response->json('nevela') !== null ? 'nevela' : 'other';
    }

    /** This app's API on one of the ports `php nevela dev` would use, or null if it isn't running. */
    private static function findRunning(): ?string
    {
        $prefix = trim((string) config('nevela.prefix', 'api'), '/');
        for ($port = 8000; $port < 8020; $port++) {
            $socket = @fsockopen('127.0.0.1', $port, $code, $message, 0.15);
            if ($socket === false) {
                continue;
            }
            fclose($socket);
            $url = "http://127.0.0.1:{$port}".($prefix === '' ? '' : "/{$prefix}");
            if (self::whoAnswers($url) === 'this') {
                return $url;
            }
        }

        return null;
    }
}
