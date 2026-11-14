<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

final class DevCommand extends Command
{
    protected $signature = 'nevela:dev
        {--port=8000 : The first port to try for the API}
        {--api-only : Start the API without the dashboard}';

    protected $description = 'Run the API and the dashboard together, and open the API to a mobile app if the project has one';

    public function handle(): int
    {
        $wanted = max(1, (int) $this->option('port'));
        $port = self::freePort($wanted);
        if ($port === null) {
            $this->components->error("Ports {$wanted} to ".($wanted + 19).' are all in use. Free one, or pass --port.');

            return self::FAILURE;
        }
        if ($port !== $wanted) {
            // Without this check the dashboard would talk to whatever already owns the port.
            // On Windows two servers can listen on the same one, and the older one answers.
            $this->components->warn("Port {$wanted} is already in use by another program, so the API is on {$port}.");
        }
        $url = "http://127.0.0.1:{$port}";

        $php = (new PhpExecutableFinder)->find(false) ?: 'php';
        // A phone can't reach 127.0.0.1 on this computer: that address is the phone itself.
        // With a mobile app in the project the API listens on the network too, so the app
        // on a phone on the same Wi-Fi can call it. Without one it stays on this machine.
        $mobile = self::mobilePath();
        $host = $mobile ? '0.0.0.0' : '127.0.0.1';
        $api = new Process([$php, 'artisan', 'serve', "--host={$host}", "--port={$port}"], base_path(), null, null, null);
        if ($mobile) {
            self::tellMobile($mobile, $port);
        }

        $web = null;
        $webPath = config('nevela.web_path');
        if (! $this->option('api-only') && $webPath && is_dir($webPath)) {
            // The dashboard is told where the API really is, whatever its .env.local says.
            $web = Process::fromShellCommandline(self::packageManager($webPath).' run dev', $webPath, ['NEVELA_API_URL' => "{$url}/api"], null, null);
        }

        // Mail a public form causes is queued, and a queue with nobody working it sends
        // nothing: a confirmation link would never arrive. So the queue is worked here too,
        // unless the app runs jobs as they are made.
        $queue = in_array(config('queue.default'), ['sync', 'null', null], true)
            ? null
            : new Process([$php, 'artisan', 'queue:listen', '--tries=1', '--sleep=2'], base_path(), null, null, null);

        $this->components->info('Starting '.($web ? 'the API and the dashboard' : 'the API').'. Press Ctrl+C to stop.');
        $this->components->twoColumnDetail('API', $url);
        if ($web) {
            $this->components->twoColumnDetail('Dashboard', 'http://localhost:3000/sign-in (see below if that port is taken)');
        }
        if ($mobile) {
            $this->components->twoColumnDetail('Mobile app', 'in another terminal: cd apps/mobile && npx expo start');
            $this->line('  <fg=gray>The API is open to this network while this runs, so a phone on the same Wi-Fi can reach it.</>');
        }
        $this->newLine();

        $api->start($this->printer('api', 35));
        $web?->start($this->printer('web', 36));
        $queue?->start($this->printer('queue', 33));

        try {
            while ($api->isRunning() && ($web === null || $web->isRunning())) {
                usleep(200_000);
            }
        } finally {
            // Whichever stopped, or Ctrl+C: don't leave the other one running behind.
            $queue?->stop(3);
            $web?->stop(3);
            $api->stop(3);
        }

        return self::SUCCESS;
    }

    /** The first port from `$from` that nothing is listening on, or null if twenty in a row are taken. */
    public static function freePort(int $from): ?int
    {
        for ($port = $from; $port < $from + 20; $port++) {
            $socket = @fsockopen('127.0.0.1', $port, $code, $message, 0.3);
            if ($socket === false) {
                return $port;
            }
            fclose($socket);
        }

        return null;
    }

    /** The project's mobile app (nevela.mobile_path), or null when it has none. */
    public static function mobilePath(): ?string
    {
        $path = config('nevela.mobile_path');

        return is_string($path) && $path !== '' && is_file("{$path}/app.json") ? $path : null;
    }

    /**
     * Write the API's port where the mobile app reads it: EXPO_PUBLIC_API_PORT in its
     * .env.local. The app works out the computer's address for itself, and can't guess
     * that the API moved off 8000 because something else had the port. Every other line
     * of the file is left as it is.
     */
    public static function tellMobile(string $mobile, int $port): void
    {
        $file = "{$mobile}/.env.local";
        $line = "EXPO_PUBLIC_API_PORT={$port}";
        $now = is_file($file) ? (string) file_get_contents($file) : '';
        if (preg_match('/^EXPO_PUBLIC_API_PORT=.*$/m', $now)) {
            $next = preg_replace('/^EXPO_PUBLIC_API_PORT=.*$/m', $line, $now, 1);
        } else {
            $next = ($now === '' ? '' : rtrim($now)."\n\n")."# The port `nevela dev` serves the API on. Written each time it starts.\n{$line}\n";
        }
        if ($next !== $now) {
            @file_put_contents($file, $next);
        }
    }

    /** The package manager a folder was installed with, from its lock file. */
    public static function packageManager(string $dir): string
    {
        return match (true) {
            is_file("{$dir}/pnpm-lock.yaml"), is_file("{$dir}/pnpm-workspace.yaml") => 'pnpm',
            is_file("{$dir}/yarn.lock") => 'yarn',
            is_file("{$dir}/bun.lockb"), is_file("{$dir}/bun.lock") => 'bun',
            default => 'npm',
        };
    }

    /** Prints a process's output a line at a time, each line labelled with which app it came from. */
    private function printer(string $name, int $colour): \Closure
    {
        $rest = '';

        return function (string $type, string $chunk) use ($name, $colour, &$rest) {
            $lines = explode("\n", $rest.str_replace("\r\n", "\n", $chunk));
            $rest = array_pop($lines);
            foreach ($lines as $line) {
                $this->output->writeln("\e[{$colour}m{$name}\e[0m │ ".$line, \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);
            }
        };
    }
}
