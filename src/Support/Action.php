<?php

namespace Nevela\Laravel\Support;

use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Something that can be done to a record, or to a resource as a whole, beyond creating,
 * editing and deleting it: accept an application, send an announcement.
 *
 * The descriptor says what it is called, where its button goes and what it asks for.
 * What it does is a class of the app's own, written once: App\Nevela\Actions\<Resource>\<Action>.
 * Between the two, Nevela does the route, the permission, the checking and the button.
 */
final class Action
{
    /** Where its button is: on one record, or on the list as a whole. */
    public const ON = ['record', 'collection'];

    /** Names that already mean something for every resource. */
    public const RESERVED = ['create', 'view', 'edit', 'delete', 'update', 'restore', 'list', 'read'];

    /** What an action can ask for. A picture or a relation is a record's business, not a button's. */
    public const KINDS = ['string', 'text', 'int', 'float', 'boolean', 'date', 'datetime', 'enum'];

    /**
     * @param  string  $name  camelCase, as in the descriptor and the address: accept, sendAnnouncement
     * @param  string|null  $confirm  A question to ask before it runs
     * @param  array<string, scalar|null>  $when  Field values a record must have for the action to apply to it
     * @param  array<string, Field>  $fields  What it asks the person for, in a sheet
     * @param  bool  $many  A record action that can also be run on a selection of rows
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $on = 'record',
        public readonly ?string $icon = null,
        public readonly ?string $confirm = null,
        public readonly array $when = [],
        public readonly array $fields = [],
        public readonly bool $many = false,
    ) {
        if (! preg_match('/^[a-z][A-Za-z0-9]*$/', $name)) {
            throw new InvalidArgumentException("Action \"{$name}\" must be camelCase, e.g. accept or sendAnnouncement.");
        }
        if (in_array(Naming::snake($name), self::RESERVED, true)) {
            throw new InvalidArgumentException("\"{$name}\" is something every resource does already. Call the action something else.");
        }
        if (! in_array($on, self::ON, true)) {
            throw new InvalidArgumentException("Action \"{$name}\" is on \"{$on}\". Use record or collection.");
        }
        if ($on === 'collection' && ($when !== [] || $many)) {
            throw new InvalidArgumentException("Action \"{$name}\" is on the collection, so it has no one record to check \"when\" against or to select.");
        }
        foreach ($when as $field => $value) {
            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException("Action \"{$name}\": \"when\" compares \"{$field}\" with one value, or with null.");
            }
        }
        foreach ($fields as $field) {
            if (! in_array($field->kind, self::KINDS, true)) {
                throw new InvalidArgumentException("Action \"{$name}\" can't ask for \"{$field->name}\": a {$field->kind} isn't something a button asks for. Use: ".implode(', ', self::KINDS).'.');
            }
            if ($field->conditional()) {
                throw new InvalidArgumentException("Action \"{$name}\" asks for \"{$field->name}\" only sometimes, which a button's sheet can't do. Ask for it always, as optional: {$field->name}?");
            }
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(string $name, array $data): self
    {
        $fields = [];
        foreach ((array) ($data['fields'] ?? []) as $key => $field) {
            $fields[(string) $key] = Field::fromArray((string) $key, (array) $field);
        }

        return new self(
            $name,
            (string) ($data['label'] ?? Naming::humanize($name)),
            (string) ($data['on'] ?? 'record'),
            $data['icon'] ?? null,
            $data['confirm'] ?? null,
            (array) ($data['when'] ?? []),
            $fields,
            (bool) ($data['many'] ?? false),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'label' => $this->label,
            'icon' => $this->icon,
            'on' => $this->on,
            'confirm' => $this->confirm,
            'when' => $this->when ?: null,
            'fields' => $this->fields ? array_map(fn (Field $f) => $f->toArray(), $this->fields) : null,
            'many' => $this->many ?: null,
        ], fn ($v) => $v !== null);
    }

    /** The second half of its permission: "send_announcement" in "newsletter_subscriptions.send_announcement". */
    public function key(): string
    {
        return Naming::snake($this->name);
    }

    /** The class that does it, without its namespace: SendAnnouncement. */
    public function className(): string
    {
        return Naming::pascal($this->name);
    }

    /**
     * Whether it applies to a record with these values, by field name.
     *
     * @param  array<string, mixed>  $values
     */
    public function applies(array $values): bool
    {
        foreach ($this->when as $field => $wanted) {
            $value = $values[$field] ?? null;
            $same = match (true) {
                $wanted === null => $value === null,
                $value === null => false,
                // A yes/no column comes back from some databases as 1 and 0.
                is_bool($wanted) => (bool) $value === $wanted,
                default => (string) $value === (string) $wanted,
            };
            if (! $same) {
                return false;
            }
        }

        return true;
    }

    /**
     * The rules its fields are checked by: the ones a resource's field of the same kind has.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [];
        foreach ($this->fields as $field) {
            $rules[$field->name] = [$field->required ? 'required' : 'nullable', ...match (true) {
                $field->format === 'email' => ['email', 'max:'.($field->maxLength ?? 255)],
                $field->format === 'url' => ['url', 'max:'.($field->maxLength ?? 2048)],
                $field->format === 'tel' && $field->country !== null => ['string', 'max:'.($field->maxLength ?? 32), new \Nevela\Laravel\Rules\Phone($field->country)],
                $field->format === 'tel' => ['string', 'max:'.($field->maxLength ?? 32)],
                $field->format === 'slug' => ['string', 'alpha_dash', 'max:'.($field->maxLength ?? 255)],
                $field->format === 'color' => ['string', 'regex:/^#[0-9a-fA-F]{6}$/'],
                $field->kind === 'string' => ['string', 'max:'.($field->maxLength ?? 255)],
                $field->kind === 'text' => ['string', 'max:'.($field->maxLength ?? 65535)],
                $field->kind === 'int' && $field->format === 'percent' => ['integer', 'between:0,100'],
                $field->kind === 'int' => ['integer'],
                $field->kind === 'float' && $field->format === 'percent' => ['numeric', 'between:0,100'],
                $field->kind === 'float' => ['numeric'],
                $field->kind === 'boolean' => ['boolean'],
                $field->kind === 'date' => ['date_format:Y-m-d'],
                $field->kind === 'datetime' => ['date'],
                $field->kind === 'enum' => [Rule::in($field->options)],
            }];
        }

        return $rules;
    }
}
