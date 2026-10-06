<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;

/**
 * Everything a freshly installed app needs before it can be used, in one process.
 *
 * `create-nevela` runs this once. Done as separate artisan calls, each step pays for
 * Laravel starting up again, which on Windows is a few seconds every time.
 */
final class SetupCommand extends Command
{
    protected $signature = 'nevela:setup
        {--name= : Name for the starter account}
        {--email= : Email for the starter account. Leave out to create no account}
        {--password= : Password for the starter account}
        {--no-sample-users : Create the starter account only, without the ten sample users}';

    protected $description = 'Finish setting up a new app: key, Sanctum\'s migration, generated files, migrations, a starter account and sample users';

    protected $hidden = true;

    public function handle(): int
    {
        if (blank(config('app.key'))) {
            $this->callSilently('key:generate', ['--force' => true]);
        }
        $this->callSilently('vendor:publish', ['--tag' => 'sanctum-migrations']);

        // routes/nevela.php, the launcher, and the dashboard's (empty) resource registry.
        if ($this->callSilently('nevela:generate') !== self::SUCCESS) {
            $this->components->error('Could not write the generated files.');

            return self::FAILURE;
        }
        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        if ($this->option('email')) {
            // The first account in an app is its administrator.
            $status = $this->call('nevela:user', [
                '--name' => $this->option('name') ?: 'Admin',
                '--email' => $this->option('email'),
                '--password' => $this->option('password'),
            ]);
            if ($status !== self::SUCCESS) {
                // The app itself is fine; the installer says how to add an account by hand.
                return 2;
            }
            // Ten more people, with the same password, so the Users screen and the roles
            // have something in them. Only with a password that was given here: one typed
            // at a prompt isn't known to this command. Never in production (the command
            // refuses), and missing them is no reason to call the setup failed.
            if (! $this->option('no-sample-users') && $this->option('password')) {
                $this->callSilently('nevela:user', ['--sample' => true, '--password' => $this->option('password')]);
            }
        }

        return self::SUCCESS;
    }
}
