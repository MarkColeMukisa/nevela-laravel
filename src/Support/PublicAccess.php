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
 */
final class PublicAccess
{
    /** Requests a minute, when the section doesn't say: plenty for a page, few for a form. */
    public const READ_THROTTLE = '60,1';

    public const CREATE_THROTTLE = '10,1';

    /**
     * @param  list<string>|null  $read  The fields anyone may read, or null when nothing may be
     * @param  string|null  $by  The unique field one record is fetched by, in place of its id
     * @param  list<string>|null  $create  The fields anyone may send to create a record, or null when nobody may
     * @param  list<string>  $middleware  More middleware for these routes: the app's own spam checks
     */
    public function __construct(
        public readonly ?array $read = null,
        public readonly ?string $by = null,
        public readonly ?array $create = null,
        public readonly string $readThrottle = self::READ_THROTTLE,
        public readonly string $createThrottle = self::CREATE_THROTTLE,
        public readonly array $middleware = [],
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
        if ($this->create !== null) {
            foreach ($fields as $field) {
                // Left out of the form, a field needs something to be: nothing, or a default.
                // (A yes/no column is no unless said otherwise.)
                if ($field->required && $field->default === null && $field->kind !== 'boolean' && ! in_array($field->name, $this->create, true)) {
                    throw new InvalidArgumentException("{$resource}: \"{$field->name}\" is required and a public create can't send it. List it under create, give it a default, or make it optional.");
                }
            }
        }
    }
}
