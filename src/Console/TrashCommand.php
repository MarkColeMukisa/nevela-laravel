<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Nevela\Laravel\Trash\Trash;

final class TrashCommand extends Command
{
    protected $signature = 'nevela:trash';

    protected $description = 'Remove, for good, the deleted records whose time in the trash has run out';

    public function handle(): int
    {
        $days = Trash::days();
        if ($days === null) {
            $this->components->info('Deleted records are kept until someone removes them (nevela.trash.days is off), so there is nothing to do.');

            return self::SUCCESS;
        }

        $removed = Trash::purgeExpired();
        foreach ($removed as $resource => $count) {
            $this->components->twoColumnDetail($resource, number_format($count).' removed');
        }
        $this->components->info($removed === []
            ? "Nothing in the trash is older than {$days} days."
            : 'Removed '.number_format(array_sum($removed))." record(s) deleted more than {$days} days ago.");

        return self::SUCCESS;
    }
}
