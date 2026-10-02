<?php

namespace Nevela\Laravel\Support;

use InvalidArgumentException;

/**
 * One field of a resource, using Flare's field kinds so the same descriptor drives
 * Laravel (columns, rules, casts) and the Flare dashboard (widgets, columns, filters).
 */
final class Field
{
    public const KINDS = ['string', 'text', 'int', 'float', 'boolean', 'date', 'datetime', 'enum'];

    public const FORMATS = [
        'string' => ['email', 'url', 'tel', 'slug', 'color'],
        'float' => ['money', 'percent', 'rating'],
        'int' => ['percent', 'rating'],
    ];

    /** Shorthand types accepted by --fields, mapped to kind + format. */
    private const ALIASES = [
        'string' => ['string', null], 'text' => ['text', null],
        'email' => ['string', 'email'], 'url' => ['string', 'url'], 'tel' => ['string', 'tel'],
        'slug' => ['string', 'slug'], 'color' => ['string', 'color'],
        'int' => ['int', null], 'integer' => ['int', null],
        'float' => ['float', null], 'money' => ['float', 'money'], 'decimal' => ['float', 'money'],
        'percent' => ['float', 'percent'], 'rating' => ['int', 'rating'],
        'boolean' => ['boolean', null], 'bool' => ['boolean', null],
        'date' => ['date', null], 'datetime' => ['datetime', null],
    ];

    public const RESERVED = ['id', 'createdAt', 'updatedAt', 'deletedAt'];

    /**
     * @param  list<string>  $options  enum values
     */
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly ?string $format = null,
        public readonly bool $required = true,
        public readonly bool $unique = false,
        public readonly array $options = [],
        public readonly ?string $label = null,
    ) {
        if (! preg_match('/^[a-z][A-Za-z0-9]*$/', $name)) {
            throw new InvalidArgumentException("Field \"{$name}\" must be camelCase (e.g. publishedAt).");
        }
        if (in_array($name, self::RESERVED, true)) {
            throw new InvalidArgumentException("Field \"{$name}\" is reserved; every resource already has it.");
        }
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unsupported kind \"{$kind}\" on field \"{$name}\".");
        }
        if ($format !== null && ! in_array($format, self::FORMATS[$kind] ?? [], true)) {
            throw new InvalidArgumentException("Format \"{$format}\" is not valid for {$kind} field \"{$name}\".");
        }
        if ($kind === 'enum' && $options === []) {
            throw new InvalidArgumentException("Enum field \"{$name}\" needs values, e.g. {$name}:enum(draft|published).");
        }
        foreach ($options as $option) {
            if (! preg_match('/^[A-Za-z0-9_-]+$/', $option)) {
                throw new InvalidArgumentException("Enum value \"{$option}\" on \"{$name}\" may only use letters, digits, _ and -.");
            }
        }
    }

    /**
     * Parse one `name:type` token. Suffix `?` = optional (nullable), `!` = unique; both may be combined.
     * Enums: `status:enum(draft|published)`.
     */
    public static function parse(string $token): self
    {
        $token = trim($token);
        if (! preg_match('/^([A-Za-z][A-Za-z0-9_]*):([a-z]+)(?:\(([^)]*)\))?([?!]{0,2})$/', $token, $m)) {
            throw new InvalidArgumentException("Invalid field \"{$token}\". Expected name:type, e.g. price:money or status:enum(draft|live)?");
        }
        [, $name, $type, $args, $suffix] = $m + [3 => '', 4 => ''];
        $name = Naming::camel($name);

        if ($type === 'enum') {
            $options = array_values(array_filter(array_map('trim', explode('|', $args)), fn ($v) => $v !== ''));

            return new self($name, 'enum', null, ! str_contains($suffix, '?'), str_contains($suffix, '!'), $options);
        }
        if (! isset(self::ALIASES[$type])) {
            throw new InvalidArgumentException("Unknown type \"{$type}\" on \"{$name}\". Use one of: ".implode(', ', array_keys(self::ALIASES)).', enum(a|b).');
        }
        [$kind, $format] = self::ALIASES[$type];

        return new self($name, $kind, $format, ! str_contains($suffix, '?'), str_contains($suffix, '!'));
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(string $name, array $data): self
    {
        return new self(
            $name,
            (string) ($data['kind'] ?? ''),
            $data['format'] ?? null,
            (bool) ($data['required'] ?? true),
            (bool) ($data['unique'] ?? false),
            array_values($data['options'] ?? []),
            $data['label'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'kind' => $this->kind,
            'format' => $this->format,
            'options' => $this->options ?: null,
            'required' => $this->required ? null : false,
            'unique' => $this->unique ?: null,
            'label' => $this->label,
        ], fn ($v) => $v !== null);
    }

    public function column(): string
    {
        return Naming::snake($this->name);
    }

    public function label(): string
    {
        return $this->label ?? Naming::humanize($this->name);
    }

    public function sortable(): bool
    {
        return $this->kind !== 'text';
    }

    public function filterable(): bool
    {
        return $this->kind !== 'text';
    }

    public function searchable(): bool
    {
        return $this->kind === 'string';
    }
}
