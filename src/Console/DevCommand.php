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

    protected $description = 'Run the API and the dashboard together';

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
        $api = new Process([$php, 'artisan', 'serve', '--host=127.0.0.1', "--port={$port}"], base_path(), null, null, null);

        $web = null;
        $webPath = config('nevela.web_path');
        if (! $this->option('api-only') && $webPath && is_dir($webPath)) {
            // The dashboard is told where the API really is, whatever its .env.local says.
            $web = Process::fromShellCommandline(self::packageManager($webPath).' run dev', $webPath, ['NEVELA_API_URL' => "{$url}/api"], null, null);
        }

        $this->components->info('Starting '.($web ? 'the API and the dashboard' : 'the API').'. Press Ctrl+C to stop.');
        $this->components->twoColumnDetail('API', $url);
        if ($web) {
            $this->components->twoColumnDetail('Dashboard', 'http://localhost:3000/sign-in (see below if that port is taken)');
        }
        $this->newLine();

        $api->start($this->printer('api', 35));
        $web?->start($this->printer('web', 36));

        try {
            while ($api->isRunning() && ($web === null || $web->isRunning())) {
                usleep(200_000);
            }
        } finally {
            // Whichever stopped, or Ctrl+C: don't leave the other one running behind.
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
