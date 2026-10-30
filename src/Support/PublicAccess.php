<?php

namespace Nevela\Laravel\Support;

use InvalidArgumentException;

/**
 * What of a resource is open to anyone, signed in or not: the descriptor's "public" section.
 *
 *   "public": {
 *     "read":   { "by": "slug", "fields": ["title", "slug", "body", "publishedAt"] },
 *     "create": { "fields": ["name", "email", "message"], "throttle": "10,1" }
 *   }
 *
 * Nothing is open without it, and nothing beyond what it names: a resource with "read"
 * alone can't be written to, and a field that isn't listed is never sent or accepted.
 * Which records can be read is the model's to say, in its publicQuery scope.
 *
 * A form anyone can send is sent by bots too, so a create can say what stands in their
 * way, and whether the address it is given has to be proved:
 *
 *     "create": {
 *       "fields": ["email"],
 *       "protect": { "turnstile": true, "honeypot": true, "minSeconds": 3, "email": "email" },
 *       "confirm": "email"
 *     }
 *
 * See Forms\FormGuard for the checks, and Forms\Confirmation for the link.
 */
final class PublicAccess
{
    /** Requests a minute, when the section doesn't say: plenty for a page, few for a form. */
    public const READ_THROTTLE = '60,1';

    public const CREATE_THROTTLE = '10,1';

    /** What "protect" can turn on. */
    public const CHECKS = ['turnstile', 'honeypot', 'minSeconds', 'email'];

    /** The longest a form can be made to wait before it is sent, in seconds. */
    public const MAX_SECONDS = 3600;

    /**
     * @param  list<string>|null  $read  The fields anyone may read, or null when nothing may be
     * @param  string|null  $by  The unique field one record is fetched by, in place of its id
     * @param  list<string>|null  $create  The fields anyone may send to create a record, or null when nobody may
     * @param  list<string>  $middleware  More middleware for these routes: the app's own spam checks
     * @param  array{turnstile: bool, honeypot: bool, minSeconds: int, email: string|null}|null  $protect  The checks a create passes before it is looked at, or null for none
     * @param  string|null  $confirm  The field holding an address its owner has to prove, by a link sent to it
     */
    public function __construct(
        public readonly ?array $read = null,
        public readonly ?string $by = null,
        public readonly ?array $create = null,
        public readonly string $readThrottle = self::READ_THROTTLE,
        public readonly string $createThrottle = self::CREATE_THROTTLE,
        public readonly array $middleware = [],
        public readonly ?array $protect = null,
        public readonly ?string $confirm = null,
    ) {
        foreach ([$readThrottle, $createThrottle] as $throttle) {
            if (! preg_match('/^[1-9][0-9]*,[1-9][0-9]*$/', $throttle)) {
                throw new InvalidArgumentException("\"{$throttle}\" is not a throttle. Write how many requests in how many minutes: \"10,1\".");
            }
        }
        if ($read === null && $create === null) {
            throw new InvalidArgumentException('A "public" section opens something: give it "read", "create" or both.');
        }
        foreach ($middleware as $name) {
            if (! is_string($name) || ! preg_match('/^[A-Za-z0-9_.:,\\\\-]+$/', $name)) {
                throw new InvalidArgumentException('Public middleware is named as in a route: "honeypot", or "throttle:5,1".');
            }
        }
        if ($protect !== null && ! $protect['turnstile'] && ! $protect['honeypot'] && $protect['minSeconds'] === 0 && $protect['email'] === null) {
            throw new InvalidArgumentException('"protect" turns nothing on. Give it "turnstile", "honeypot", "minSeconds" or "email", or leave it out.');
        }
        if ($protect !== null && ($protect['minSeconds'] < 0 || $protect['minSeconds'] > self::MAX_SECONDS)) {
            throw new InvalidArgumentException('"minSeconds" is how long a person takes to fill the form in: between 0 and '.self::MAX_SECONDS.'.');
        }
    }

    /** Whether a create has checks to pass. */
    public function protected(): bool
    {
        return $this->protect !== null;
    }

    /**
     * "protect" as it is written, made whole: every check said yes or no to.
     *
     * @return array{turnstile: bool, honeypot: bool, minSeconds: int, email: string|null}
     */
    private static function protection(mixed $given): array
    {
        if (! is_array($given)) {
            throw new InvalidArgumentException('"protect" lists the checks to make: { "turnstile": true, "honeypot": true, "minSeconds": 3, "email": "email" }.');
        }
        if (($unknown = array_diff(array_keys($given), self::CHECKS)) !== []) {
            throw new InvalidArgumentException('"protect" has no check called "'.reset($unknown).'". There are: '.implode(', ', self::CHECKS).'.');
        }
        $seconds = $given['minSeconds'] ?? 0;
        if (! is_int($seconds)) {
            throw new InvalidArgumentException('"minSeconds" is a whole number of seconds.');
        }
        $email = $given['email'] ?? null;
        if ($email !== null && ! is_string($email)) {
            throw new InvalidArgumentException('"email" in "protect" names the field that holds the address.');
        }

        return ['turnstile' => ($given['turnstile'] ?? false) === true, 'honeypot' => ($given['honeypot'] ?? false) === true, 'minSeconds' => $seconds, 'email' => $email];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $read = isset($data['read']) ? (array) $data['read'] : null;
        $create = isset($data['create']) ? (array) $data['create'] : null;

        return new self(
            $read === null ? null : array_values(array_map('strval', (array) ($read['fields'] ?? []))),
            $read['by'] ?? null,
            $create === null ? null : array_values(array_map('strval', (array) ($create['fields'] ?? []))),
            (string) ($read['throttle'] ?? self::READ_THROTTLE),
            (string) ($create['throttle'] ?? self::CREATE_THROTTLE),
            array_values((array) ($data['middleware'] ?? [])),
            isset($create['protect']) ? self::protection($create['protect']) : null,
            isset($create['confirm']) ? (string) $create['confirm'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'read' => $this->read === null ? null : array_filter([
                'by' => $this->by,
                'fields' => $this->read,
                'throttle' => $this->readThrottle === self::READ_THROTTLE ? null : $this->readThrottle,
            ], fn ($v) => $v !== null),
            'create' => $this->create === null ? null : array_filter([
                'fields' => $this->create,
                'throttle' => $this->createThrottle === self::CREATE_THROTTLE ? null : $this->createThrottle,
                // Only what is on, so a descriptor reads as it was written.
                'protect' => $this->protect === null ? null : array_filter($this->protect, fn ($v) => $v !== false && $v !== 0 && $v !== null),
                'confirm' => $this->confirm,
            ], fn ($v) => $v !== null),
            'middleware' => $this->middleware ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Checked against the resource's fields, which the section on its own can't know.
     *
     * @param  array<string, Field>  $fields
     *
     * @throws InvalidArgumentException
     */
    public function check(string $resource, array $fields): void
    {
        foreach (['read' => $this->read, 'create' => $this->create] as $what => $listed) {
            if ($listed === null) {
                continue;
            }
            if ($listed === []) {
                throw new InvalidArgumentException("{$resource}: public {$what} names no fields, so there would be nothing to {$what}.");
            }
            foreach ($listed as $field) {
                if (! isset($fields[$field])) {
                    throw new InvalidArgumentException("{$resource}: public {$what} lists \"{$field}\", which is not one of its fields.");
                }
                if ($fields[$field]->multiple) {
                    throw new InvalidArgumentException("{$resource}: public {$what} lists \"{$field}\", which holds several files. The public API doesn't {$what} those yet; leave it to the dashboard.");
                }
            }
        }
        foreach ($this->read ?? [] as $field) {
            if ($fields[$field]->private) {
                throw new InvalidArgumentException("{$resource}: \"{$field}\" is a private file, so it can't be read by anyone who asks.");
            }
        }
        if ($this->by !== null) {
            if ($this->read === null) {
                throw new InvalidArgumentException("{$resource}: \"by\" says how one record is read, and nothing is.");
            }
            if (! isset($fields[$this->by]) || ! $fields[$this->by]->unique) {
                throw new InvalidArgumentException("{$resource}: a record is fetched by \"{$this->by}\", which has to be a unique field ({$this->by}:slug!).");
            }
        }
        if ($this->create === null && ($this->protect !== null || $this->confirm !== null)) {
            throw new InvalidArgumentException("{$resource}: \"protect\" and \"confirm\" are about a public create, and there isn't one.");
        }
        foreach (['protect.email' => $this->protect['email'] ?? null, 'confirm' => $this->confirm] as $what => $named) {
            if ($named === null) {
                continue;
            }
            // The address has to be something a visitor types into the form.
            if (! in_array($named, $this->create ?? [], true) || $fields[$named]->kind !== 'string') {
                throw new InvalidArgumentException("{$resource}: {$what} names \"{$named}\", which has to be a text field the public create lists.");
            }
        }
        if ($this->create !== null) {
            foreach ($fields as $field) {
                // Left out of the form, a field needs something to be: nothing, or a default.
                // (A yes/no column is no unless said otherwise.) A number Laravel gives, or a
                // value it copies from a related record, is something too.
                $filled = $field->auto !== null || ($field->from !== null && str_contains($field->from, '.'));
                if ($field->required && $field->default === null && $field->kind !== 'boolean' && ! $filled && ! in_array($field->name, $this->create, true)) {
                    throw new InvalidArgumentException("{$resource}: \"{$field->name}\" is required and a public create can't send it. List it under create, give it a default, or make it optional.");
                }
            }
        }
    }
}
