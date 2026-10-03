<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

final class UserCommand extends Command
{
    protected $signature = 'nevela:user
        {--name= : The person\'s name}
        {--email= : The address they sign in with}
        {--password= : Their password. Leave out to be asked for it without it showing}';

    protected $description = 'Create a user who can sign in to the dashboard';

    public function handle(): int
    {
        $model = config('auth.providers.users.model');
        if (! is_string($model) || ! class_exists($model)) {
            $this->components->error('No user model is configured in config/auth.php (auth.providers.users.model).');

            return self::FAILURE;
        }

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

        // forceFill: this is the operator creating an account, not a form to guard.
        $user = (new $model)->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
        ]);
        $user->save();

        $this->components->info("Created {$input['email']}. They can sign in to the dashboard now.");

        return self::SUCCESS;
    }
}
