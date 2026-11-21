<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Nevela\Laravel\Http\OpenApiController;

/**
 * The API as an OpenAPI document, written to a file: for a tool that makes a client from
 * one, or a docs site, that can't sign in to read GET _nevela/openapi.json.
 */
final class OpenApiCommand extends Command
{
    protected $signature = 'nevela:openapi {--output= : Where to write it. Printed when not given}';

    protected $description = 'Write the API as an OpenAPI 3.1 document, from the resource descriptors';

    public function handle(): int
    {
        $json = json_encode(OpenApiController::document(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $output = $this->option('output');
        if (! is_string($output) || $output === '') {
            $this->output->write($json, false, \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }
        if (! is_dir(dirname($output)) && ! @mkdir(dirname($output), 0755, true)) {
            $this->error("Can't make the folder for {$output}.");

            return self::FAILURE;
        }
        file_put_contents($output, $json);
        $this->info("Wrote {$output}.");

        return self::SUCCESS;
    }
}
