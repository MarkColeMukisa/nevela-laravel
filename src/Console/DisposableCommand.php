<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Nevela\Laravel\Forms\Disposable;
use Throwable;

final class DisposableCommand extends Command
{
    protected $signature = 'nevela:disposable {--url= : Fetch the list from somewhere else}';

    protected $description = 'Fetch the current list of throwaway email domains a protected form refuses';

    public function handle(): int
    {
        $url = (string) ($this->option('url') ?: Disposable::SOURCE);
        try {
            $response = Http::timeout(30)->get($url);
        } catch (Throwable $e) {
            $this->components->error("Couldn't fetch the list: ".$e->getMessage());

            return self::FAILURE;
        }
        $domains = [];
        foreach (preg_split('/\R/', $response->successful() ? $response->body() : '') ?: [] as $line) {
            $line = strtolower(trim($line));
            // One domain a line. Anything else is not the list: an error page, a redirect to a sign-in.
            if ($line !== '' && $line[0] !== '#' && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $line)) {
                $domains[$line] = true;
            }
        }
        // The list has thousands. A few lines is something else, and would let every throwaway address through.
        if (count($domains) < 1000) {
            $this->components->error("What came back from {$url} isn't the list (".count($domains).' domains). The one in use is unchanged.');

            return self::FAILURE;
        }
        ksort($domains);
        $path = Disposable::refreshedPath();
        is_dir(dirname($path)) || mkdir(dirname($path), 0775, true);
        file_put_contents($path, implode("\n", array_keys($domains))."\n", LOCK_EX);
        Disposable::forget();
        $this->components->info(number_format(count($domains)).' throwaway domains, kept in '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).'.');

        return self::SUCCESS;
    }
}
