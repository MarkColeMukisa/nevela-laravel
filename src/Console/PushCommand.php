<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Nevela\Laravel\Auth\Account;
use Nevela\Laravel\Push\Push;
use Throwable;

/**
 * `nevela push owner@example.com`: send a test push to someone's phones, and say what became of
 * it. Expo's answer to a send (a ticket) only says it took the message. Whether Firebase or
 * Apple took it in turn is in the receipt, a few seconds later: bad Firebase credentials show
 * there and nowhere else. A push that "didn't arrive" was often a test that had signed out,
 * which forgets the phone on purpose; this says so too.
 */
final class PushCommand extends Command
{
    protected $signature = 'nevela:push
        {account : The email of the person to send it to}
        {--title=A test from Nevela : What the push says}
        {--wait=8 : Seconds to wait before asking Expo for the receipts}';

    protected $description = 'Send a test push to someone\'s phones, and show what Expo, Firebase and Apple made of it';

    public function handle(): int
    {
        if (! Push::ready()) {
            $this->components->error('Push notifications need their table first: php artisan migrate');

            return self::FAILURE;
        }
        $user = Account::findByEmail((string) $this->argument('account'));
        if ($user === null) {
            $this->components->error('No account has that email.');

            return self::FAILURE;
        }
        $phones = DB::table(Push::TABLE)->where('user_id', (string) $user->getAuthIdentifier())->get(['token', 'platform', 'updated_at']);
        if ($phones->isEmpty()) {
            $this->components->error('They have no phone registered. Sign in on the phone app (a build, not Expo Go on Android) and allow notifications. Signing out forgets the phone, so stay signed in.');

            return self::FAILURE;
        }
        foreach ($phones as $phone) {
            $this->components->twoColumnDetail(($phone->platform ?? 'phone').', since '.substr((string) $phone->updated_at, 0, 16), substr((string) $phone->token, 0, 32).'…');
        }

        $tickets = Push::send([(string) $user->getAuthIdentifier()], (string) $this->option('title'), 'If this arrived, pushes work.');
        if ($tickets === []) {
            $this->components->error('Expo didn\'t take it: see the log for why.');

            return self::FAILURE;
        }
        $ids = [];
        $failed = false;
        foreach ($tickets as $i => $ticket) {
            if (($ticket['status'] ?? null) === 'ok') {
                $ids[] = (string) $ticket['id'];
                $this->components->twoColumnDetail("Ticket {$i}", '<fg=green>taken by Expo</>');
            } else {
                $failed = true;
                $this->components->twoColumnDetail("Ticket {$i}", '<fg=red>'.($ticket['details']['error'] ?? 'refused').': '.($ticket['message'] ?? '').'</>');
            }
        }
        if ($ids === []) {
            return self::FAILURE;
        }

        $wait = max(0, (int) $this->option('wait'));
        $this->line("  Waiting {$wait} s for the receipts…");
        sleep($wait);
        try {
            $receipts = Push::receipts($ids);
        } catch (Throwable $e) {
            $this->components->error("Couldn't get the receipts: {$e->getMessage()}");

            return self::FAILURE;
        }
        foreach ($ids as $id) {
            $receipt = $receipts[$id] ?? null;
            if ($receipt === null) {
                $this->components->twoColumnDetail("Receipt {$id}", '<fg=yellow>not ready yet: run this again with a longer --wait</>');
            } elseif (($receipt['status'] ?? null) === 'ok') {
                $this->components->twoColumnDetail("Receipt {$id}", '<fg=green>delivered to Firebase or Apple</>');
            } else {
                $failed = true;
                $error = (string) ($receipt['details']['error'] ?? 'error');
                $why = match ($error) {
                    'InvalidCredentials' => 'Firebase (FCM V1) or Apple credentials are missing or wrong: npx eas-cli@latest credentials',
                    'DeviceNotRegistered' => 'the app was removed from that phone, or its notifications reset',
                    'MessageTooBig' => 'the push is over 4 KB',
                    default => (string) ($receipt['message'] ?? ''),
                };
                $this->components->twoColumnDetail("Receipt {$id}", "<fg=red>{$error}: {$why}</>");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
