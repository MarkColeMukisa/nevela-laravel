<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Nevela\Laravel\Forms\Confirmation;
use Nevela\Laravel\Forms\PublicMail;
use Nevela\Laravel\Media\Uploads;
use Nevela\Laravel\Trash\Trash;

final class TrashCommand extends Command
{
    protected $signature = 'nevela:trash';

    protected $description = 'Remove, for good, the deleted records whose time in the trash has run out';

    public function handle(): int
    {
        // Files visitors uploaded for a form they never sent. Nothing to do with the trash,
        // but the same kind of job, on the same night.
        $unclaimed = Uploads::purgeUnclaimed();
        if ($unclaimed > 0) {
            $this->components->info('Removed '.number_format($unclaimed).' file(s) uploaded through a public form and never used.');
        }

        // People who joined a list and never followed the link that proves their address.
        $hours = Confirmation::hours();
        foreach (Confirmation::purge() as $resource => $count) {
            $this->components->info('Removed '.number_format($count)." {$resource} record(s) whose address wasn't confirmed within {$hours} hours.");
        }
        PublicMail::prune();

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
