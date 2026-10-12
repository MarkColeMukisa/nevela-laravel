<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Nevela\Laravel\Forms\PublicMail;

final class DigestCommand extends Command
{
    protected $signature = 'nevela:digest';

    protected $description = 'Send the owner one email for the form notices that were held back in a busy hour';

    public function handle(): int
    {
        if (PublicMail::owner() === null) {
            $this->components->warn('There is nobody to send it to. Set NEVELA_NOTIFY_TO, or the address the app sends mail from.');

            return self::SUCCESS;
        }
        $count = PublicMail::digest();
        $this->components->info($count === 0
            ? 'No notices are waiting.'
            : 'Sent one email naming '.number_format($count).' notice(s) to '.PublicMail::owner().'.');

        return self::SUCCESS;
    }
}
