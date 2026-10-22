<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Nevela\Laravel\Access\Access;
use Nevela\Laravel\Access\ClosedAccounts;
use Nevela\Laravel\Access\SampleUsers;
use Nevela\Laravel\Auth\Account;
use Nevela\Laravel\Auth\Challenges;
use Nevela\Laravel\Auth\Sessions;
use Nevela\Laravel\Auth\Web;
use Nevela\Laravel\Models\Role;

final class UserCommand extends Command
{
    protected $signature = 'nevela:user
        {account? : The email of an account that exists, to change it instead of making one}
        {--name= : The person\'s name}
        {--email= : The address they sign in with}
        {--password= : Their password, for a new account. Leave out to be asked for it without it showing}
        {--role= : Their role: ADMIN, EDITOR, USER or one you have made. The first account is an ADMIN. For an account that exists, one more role for it}
        {--verified : Mark the email as verified. Only for an address you know is theirs}
        {--verify : The same, for an account that exists}
        {--reset : Give an account that exists a new password, asked for without it showing}
        {--password-stdin : With --reset, read the new password from standard input, where nothing can ask for it}
        {--sign-out-everywhere : End every sign-in the account has, on every device}
        {--link : Print a sign-in link for the account. It works once, within 15 minutes}
        {--sample : Create the ten sample users instead: two editors and eight users}';

    protected $description = 'Create a user who can sign in to the dashboard, or change one that exists';

    /** How long a printed sign-in link works. */
    private const LINK_MINUTES = 15;

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
        if ($this->argument('account') !== null) {
            return $this->change(mb_strtolower(trim((string) $this->argument('account'))));
        }
        foreach (['reset', 'password-stdin', 'sign-out-everywhere', 'link', 'verify'] as $option) {
            if ($this->option($option)) {
                $this->components->error("--{$option} is for an account that exists: name it, as in nevela user --{$option} owner@example.com");

                return self::FAILURE;
            }
        }

        $asked = $this->option('email') === null;
        $input = [
            'name' => $this->option('name') ?? $this->ask('Name'),
            // As it will be stored, so that what is checked is what is saved.
            'email' => mb_strtolower(trim((string) ($this->option('email') ?? $this->ask('Email')))),
            'password' => $this->option('password') ?? $this->secret('Password (at least 8 characters)'),
        ];

        $table = (new $model)->getTable();
        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            // Compared without regard to capitals, whatever the database does: Ada@ and ada@ are one person.
            'email' => ['required', 'email', 'max:255', function (string $attribute, mixed $value, \Closure $fail) {
                if (is_string($value) && Account::findByEmail($value)) {
                    $fail('Someone already has this email.');
                }
            }],
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
        $attributes = ['name' => $input['name'], 'email' => $input['email'], 'password' => Hash::make($input['password'])];
        // "Verified" means the owner has proved the address is theirs, by a link or a code
        // sent to it. Making an account here proves nothing of the kind, so it starts
        // unverified unless whoever runs this says they know the address is right.
        if ($this->option('verified') && Schema::hasColumn($table, 'email_verified_at')) {
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
     * Change an account that exists: a new password, a verified address, one more role, a
     * sign-in link, or every device signed out. Everything is checked before anything is
     * changed, and a password is never taken from the command line, where the shell's
     * history and the host's command log would keep it.
     */
    private function change(string $email): int
    {
        $user = Account::findByEmail($email);
        if (! $user) {
            $this->components->error("No account has the email {$email}. To make one: nevela user --email={$email}");

            return self::FAILURE;
        }
        $email = (string) $user->email;
        if (ClosedAccounts::isClosed($user)) {
            $this->components->error("The account for {$email} is closed. Restore it first, under Deleted accounts in the dashboard.");

            return self::FAILURE;
        }
        if ($this->option('password') !== null) {
            $this->components->error('A password on the command line stays in your shell\'s history. Use --reset, and it is asked for, or --reset --password-stdin.');

            return self::FAILURE;
        }

        $reset = $this->option('reset') || $this->option('password-stdin');
        $verify = $this->option('verify') || $this->option('verified');
        $signOut = (bool) $this->option('sign-out-everywhere');
        $link = (bool) $this->option('link');
        if (! $reset && ! $verify && ! $signOut && ! $link && $this->option('role') === null) {
            return $this->describe($user);
        }

        // --- everything checked first ---
        $role = null;
        if ($this->option('role') !== null) {
            $role = $this->existingRole((string) $this->option('role'));
            if ($role === false) {
                return self::FAILURE;
            }
        }
        if ($link && ! config('nevela.auth.magic_link', true)) {
            $this->components->error('Sign-in links are switched off in this app (auth.magic_link). Give the account a password instead: --reset');

            return self::FAILURE;
        }
        $password = null;
        if ($reset) {
            $password = $this->newPassword();
            if ($password === null) {
                return self::FAILURE;
            }
        }

        // --- then changed ---
        $done = [];
        if ($role !== null) {
            $had = Access::rolesOf($user)->contains(fn (Role $held) => $held->name === $role);
            Access::grant($user, $role);
            $done[] = $had ? "was already {$role}" : "is now {$role}";
        }
        if ($verify && Schema::hasColumn($user->getTable(), 'email_verified_at')) {
            $done[] = $user->email_verified_at === null ? 'has its address marked verified' : 'already had its address verified';
            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();
        }
        if ($password !== null) {
            $user->forceFill(['password' => Hash::make($password)])->save();
            $done[] = 'has a new password';
        }
        if ($signOut) {
            $ended = Account::revokeTokens($user);
            $done[] = 'is signed out everywhere ('.$ended.' '.($ended === 1 ? 'sign-in' : 'sign-ins').' ended)';
        }
        if ($done !== []) {
            $this->components->info("{$email} ".$this->sentence($done).'.');
        }
        if (! Access::active($user)) {
            $this->components->warn('This account is switched off, so it can\'t sign in until it is switched on again, on the dashboard\'s Users screen.');
        }
        if ($link) {
            // The link an email would carry, marked as handed out here: opening it doesn't
            // prove the address, and an emailed second step is still asked for.
            $token = Challenges::start('magic-link', ['user' => (string) $user->getAuthIdentifier(), 'handed' => true], self::LINK_MINUTES * 60);
            $this->components->info('A sign-in link for '.$email.'. It works once, within '.self::LINK_MINUTES.' minutes:');
            $this->line('  '.Web::url('/api/auth/magic-link', ['token' => $token, 'next' => '/dashboard']));
            $this->newLine();
            $this->line('  <fg=gray>Whoever opens it is signed in as this account. Send it only to them.</>');
        }

        return self::SUCCESS;
    }

    /** What an account is, when nothing was asked to change. */
    private function describe(mixed $user): int
    {
        $roles = Access::ready() ? Access::rolesOf($user)->pluck('name')->implode(', ') : '';
        $devices = count(Sessions::live($user));
        $this->components->twoColumnDetail('Account', "{$user->name} <fg=gray>{$user->email}</>");
        $this->components->twoColumnDetail('Roles', $roles !== '' ? $roles : '<fg=gray>none</>');
        $this->components->twoColumnDetail('Address', ($user->email_verified_at ?? null) !== null ? 'verified' : '<fg=yellow>not verified</>');
        $this->components->twoColumnDetail('Can sign in', Access::active($user) ? 'yes' : '<fg=yellow>no: switched off</>');
        if (Sessions::ready()) {
            $this->components->twoColumnDetail('Signed in on', $devices === 1 ? '1 device' : "{$devices} devices");
        }
        $this->newLine();
        $this->line('  To change it: <fg=cyan>--reset</> (a new password), <fg=cyan>--verify</>, <fg=cyan>--role=ADMIN</>, <fg=cyan>--link</> (a sign-in link), <fg=cyan>--sign-out-everywhere</>');

        return self::SUCCESS;
    }

    /** The new password, asked for or read from standard input, and checked. Null when it isn't one. */
    private function newPassword(): ?string
    {
        if ($this->option('password-stdin')) {
            $password = rtrim((string) stream_get_contents(STDIN), "\r\n");
        } elseif (! $this->input->isInteractive()) {
            $this->components->error('Nothing here can ask for the password. Pipe it in with --password-stdin, or print a sign-in link with --link.');

            return null;
        } else {
            $password = (string) $this->secret('New password (at least 8 characters)');
            if ($password !== (string) $this->secret('The same again')) {
                $this->components->error('The two passwords are different. Nothing was changed.');

                return null;
            }
        }

        $validator = Validator::make(['password' => $password], ['password' => Account::passwordRules()]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return null;
        }

        return $password;
    }

    /** A role that exists, by name in any case, or false when there is none. */
    private function existingRole(string $wanted): string|false
    {
        if (! Access::ready()) {
            $this->components->error('Roles need a migration that has not been run: php nevela migrate');

            return false;
        }
        $names = Role::query()->orderByDesc('is_system')->orderBy('name')->pluck('name')->all();
        foreach ($names as $name) {
            if (strcasecmp($name, $wanted) === 0) {
                return $name;
            }
        }
        $this->components->error("There is no role called \"{$wanted}\". The roles are: ".implode(', ', $names).'.');

        return false;
    }

    /** @param  list<string>  $parts */
    private function sentence(array $parts): string
    {
        $last = array_pop($parts);

        return $parts === [] ? (string) $last : implode(', ', $parts).' and '.$last;
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
        if (! SampleUsers::allowed()) {
            $this->components->error('Sample users are for development: ten accounts sharing one password. This app is in production, so none were made.');

            return self::FAILURE;
        }
        $password = (string) ($this->option('password') ?? 'password');
        if (mb_strlen($password) < 8) {
            $this->components->error('Give the sample users a password of at least 8 characters, or leave --password out for "password".');

            return self::FAILURE;
        }
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
