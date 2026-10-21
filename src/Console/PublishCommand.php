<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Nevela\Laravel\Support\PublishedConfig;

final class PublishCommand extends Command
{
    protected $signature = 'nevela:publish
        {--tag=nevela-config : What to publish. Nevela has its settings, nevela-config}
        {--force : Replace config/nevela.php if it is there. What you changed in it is lost}';

    protected $description = 'Write Nevela\'s settings to config/nevela.php, to change those that have no .env variable';

    public function handle(): int
    {
        // `vendor:publish --tag=nevela-config` is what Laravel people type, so the tag is taken too.
        $tag = (string) $this->option('tag');
        if (! in_array($tag, ['nevela-config', 'config'], true)) {
            $this->components->error("Nevela has nothing called \"{$tag}\" to publish. Its settings are: nevela publish");

            return self::FAILURE;
        }

        $file = config_path('nevela.php');
        if (is_file($file) && ! $this->option('force')) {
            $this->components->info('config/nevela.php is already here, and was left as it is.');
            if (($missing = PublishedConfig::missingFromApp()) !== []) {
                $this->components->warn('It has no '.PublishedConfig::describe($missing).". Nevela's defaults apply. To set them, copy them from vendor/nevela/laravel/config/nevela.php, or start again from Nevela's with: nevela publish --force (what you changed in it is lost).");
            }

            return self::SUCCESS;
        }

        // Written beside it and moved into place, so a copy that fails half way never leaves
        // the app with half a config file, which Laravel couldn't load.
        is_dir(dirname($file)) || mkdir(dirname($file), 0775, true);
        $temporary = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (! @copy(dirname(__DIR__, 2).'/config/nevela.php', $temporary) || ! @rename($temporary, $file)) {
            @unlink($temporary);
            $this->components->error("Couldn't write config/nevela.php. Nothing was changed.");

            return self::FAILURE;
        }
        $this->components->info('Wrote config/nevela.php. Change what you need there.');
        $this->line('  A setting with a .env variable, such as SOCIAL_AUTH_ENABLED or NEVELA_REGISTRATION, is still read from .env.');

        return self::SUCCESS;
    }
}
