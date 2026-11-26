<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Nevela\Laravel\Security\Addresses;

/**
 * The block list from the command line (Security\Addresses): the way back in when a block
 * keeps an administrator out of the dashboard.
 *
 *   php nevela security blocked                       the blocks in force
 *   php nevela security unblock 203.0.113.7           clear an address's blocks
 *   php nevela security block 203.0.113.7 --minutes=60 --note="…"
 */
final class SecurityCommand extends Command
{
    protected $signature = 'nevela:security
        {action : blocked, block or unblock}
        {address? : An address, such as 203.0.113.7, or an IPv6 address (blocked by its /64)}
        {--minutes= : How long a block lasts. Left out: until it is cleared}
        {--note= : Why, for whoever reads the list later}';

    protected $description = 'See, add to and clear the list of blocked addresses';

    public function handle(): int
    {
        if (! Addresses::ready()) {
            $this->components->error('The block list needs a migration that has not been run: php nevela migrate');

            return self::FAILURE;
        }

        return match ($this->argument('action')) {
            'blocked' => $this->list(),
            'block' => $this->block(),
            'unblock' => $this->unblock(),
            default => $this->wrong(),
        };
    }

    private function list(): int
    {
        $blocks = Addresses::current();
        if ($blocks === []) {
            $this->components->info('No address is blocked.');

            return self::SUCCESS;
        }
        $this->table(['Address', 'Why', 'Until', 'Blocked', 'Note'], array_map(fn ($b) => [
            $b['address'], $b['reason'], $b['until'] ?? 'cleared by hand', $b['blockedAt'], $b['note'] ?? '',
        ], $blocks));

        return self::SUCCESS;
    }

    private function block(): int
    {
        $key = Addresses::parse((string) $this->argument('address'));
        if ($key === null) {
            $this->components->error('Name an address to block, such as: php nevela security block 203.0.113.7');

            return self::FAILURE;
        }
        if (Addresses::exempt($key)) {
            $this->components->error("{$key} is never blocked: it is this computer, or on the exempt list (nevela.security.exempt).");

            return self::FAILURE;
        }
        $minutes = (int) $this->option('minutes');
        Addresses::block($key, 'manual', $minutes > 0 ? $minutes : null, null, $this->option('note') ?: null);
        $this->components->info("{$key} is blocked".($minutes > 0 ? " for {$minutes} minutes." : ' until it is cleared.'));

        return self::SUCCESS;
    }

    private function unblock(): int
    {
        $key = Addresses::parse((string) $this->argument('address'));
        if ($key === null) {
            $this->components->error('Name the address to clear, such as: php nevela security unblock 203.0.113.7');

            return self::FAILURE;
        }
        $cleared = Addresses::unblock($key);
        $this->components->info($cleared > 0 ? "{$key} can reach the app again." : "{$key} wasn't blocked.");

        return self::SUCCESS;
    }

    private function wrong(): int
    {
        $this->components->error('Say what to do: blocked, block or unblock.');

        return self::FAILURE;
    }
}
