<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Nevela\Laravel\Nevela;

final class VersionCommand extends Command
{
    protected $signature = 'nevela:version';

    protected $description = 'Show which version of Nevela this app is on';

    public function handle(): int
    {
        $this->line('Nevela '.Nevela::VERSION);

        return self::SUCCESS;
    }
}
