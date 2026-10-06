<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Nevela\Laravel\Access\Access;
use Nevela\Laravel\Access\SampleUsers;
use Nevela\Laravel\Models\Role;

final class UserCommand extends Command
{
    protected $signature = 'nevela:user
        {--name= : The person\'s name}
        {--email= : The address they sign in with}
        {--password= : Their password. Leave out to be asked for it without it showing}
        {--role= : Their role: ADMIN, EDITOR, USER or one you have made. The first account is an ADMIN}
        {--sample : Create the ten sample users instead: two editors and eight users}';

    protected $description = 'Create a user who can sign in to the dashboard';

    public function handle(): int
    {
        $model = config('auth.providers.users.model');
        if (! is_string($model) || ! class_exists($model)) {
            $this->components->error('No user model is configured in config/auth.php (auth.providers.users.model).');

            return self::FAILURE;
        }
        if ($this->option('sample')) {
            return $this->sample();
        }

        $asked = $this->option('email') === null;
        $input = [
            'name' => $this->option('name') ?? $this->ask('Name'),
            'email' => $this->option('email') ?? $this->ask('Email'),
            'password' => $this->option('password') ?? $this->secret('Password (at least 8 characters)'),
        ];

        $table = (new $model)->getTable();
        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', "unique:{$table},email"],
            'password' => ['required', 'string', 'min:8'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $role = $this->role($asked);
        if ($role === false) {
            return self::FAILURE;
        }

        // forceFill: this is the operator creating an account, not a form to guard.
        $attributes = ['name' => $input['name'], 'email' => mb_strtolower(trim($input['email'])), 'password' => Hash::make($input['password'])];
        if (Schema::hasColumn($table, 'email_verified_at')) {
            $attributes['email_verified_at'] = now();
        }
        $user = (new $model)->forceFill($attributes);
        $user->save();
        if ($role !== null) {
            Access::grant($user, $role);
        }

        $as = $role === null ? '' : " as {$role}";
        $this->components->info("Created {$attributes['email']}{$as}. They can sign in to the dashboard now.");
        if ($role !== null && $role !== Role::ADMIN && ! $this->option('role')) {
            $this->line("  To give them another role: the dashboard's Users screen, or <fg=cyan>--role=ADMIN</> here.");
        }

        return self::SUCCESS;
    }

    /**
     * The role for the new account: null when this app has no roles yet (its migrations
     * haven't run), false when the one asked for doesn't exist.
     */
    private function role(bool $asked): string|false|null
    {
        if (! Access::ready()) {
            return null;
        }
        $names = Role::query()->orderByDesc('is_system')->orderBy('name')->pluck('name')->all();
        $wanted = $this->option('role');
        if ($wanted !== null) {
            foreach ($names as $name) {
                if (strcasecmp($name, (string) $wanted) === 0) {
                    return $name;
                }
            }
            $this->components->error("There is no role called \"{$wanted}\". The roles are: ".implode(', ', $names).'.');

            return false;
        }
        // Someone has to be able to manage the rest: the first account is the administrator.
        if (Access::otherAdmins() === 0) {
            return Role::ADMIN;
        }

        return $asked && $this->input->isInteractive() ? (string) $this->choice('Role', $names, Role::USER) : Role::USER;
    }

    private function sample(): int
    {
        if (! Access::ready()) {
            $this->components->error('Roles need a migration that has not been run: php nevela migrate');

            return self::FAILURE;
        }
        $password = (string) ($this->option('password') ?? 'password');
        $created = SampleUsers::create($password);
        if ($created === []) {
            $this->components->info('The sample users are already here.');

            return self::SUCCESS;
        }
        foreach ($created as $person) {
            $this->components->twoColumnDetail("{$person['name']} <fg=gray>{$person['email']}</>", $person['role'].($person['active'] ? '' : ' <fg=gray>(switched off)</>'));
        }
        $this->newLine();
        $this->components->info('Created '.count($created)." sample users. They all sign in with the password \"{$password}\".");
        $this->line('  They are in this database only. Delete them before real use: the dashboard\'s Users screen.');

        return self::SUCCESS;
    }
}
