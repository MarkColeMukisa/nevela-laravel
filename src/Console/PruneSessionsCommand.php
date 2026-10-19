<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Nevela\Laravel\Auth\Sessions;

/**
 * Remove sign-ins that can't be used again, and their tokens. The scheduler runs it each
 * night; nothing depends on it running, it only keeps the tables small.
 */
final class PruneSessionsCommand extends Command
{
    protected $signature = 'nevela:prune-sessions';

    protected $description = 'Remove sign-ins that have ended, and access tokens that have expired';

    protected $hidden = true;

    public function handle(): int
    {
        $this->components->info(sprintf('Removed %d ended sign-in%s.', $gone = Sessions::prune(), $gone === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
