<?php

namespace Nevela\Laravel\Support;

use InvalidArgumentException;
use JsonException;

/**
 * A resource described once. The JSON form (nevela/resources/<slug>.json) is the
 * generation input; Laravel code generated from it is the runtime authority, and the
 * Flare `.resource.ts` generated from it drives the Next.js dashboard.
 */
final class Descriptor
{
    /** @param array<string, Field> $fields keyed by field name, in declaration order */
    public function __construct(
        public readonly string $name,
        public readonly array $fields,
        public readonly string $table,
        public readonly string $slug,
        public readonly string $label,
        public readonly string $pluralLabel,
        public readonly ?string $icon = null,
        public readonly ?string $group = null,
        public readonly ?string $titleField = null,
    ) {
        if (! preg_match('/^[A-Z][A-Za-z0-9]*$/', $name)) {
            throw new InvalidArgumentException('Resource name must be PascalCase, e.g. Product or BlogPost.');
        }
        if ($fields === []) {
            throw new InvalidArgumentException("Resource {$name} needs at least one field.");
        }
        if ($titleField !== null && ! isset($fields[$titleField])) {
            throw new InvalidArgumentException("titleField \"{$titleField}\" is not a field of {$name}.");
        }
    }

    /** Build from a name and a `--fields` string: "name:string, price:money, status:enum(draft|live)?". */
    public static function fromSpec(string $name, string $spec, ?string $icon = null, ?string $group = null): self
    {
        $fields = [];
        foreach (array_filter(array_map('trim', self::splitSpec($spec))) as $token) {
            $field = Field::parse($token);
            if (isset($fields[$field->name])) {
                throw new InvalidArgumentException("Field \"{$field->name}\" is declared twice.");
            }
            $fields[$field->name] = $field;
        }

        return self::make(Naming::pascal($name), $fields, icon: $icon, group: $group);
    }

    /** @param array<string, Field> $fields */
    public static function make(string $name, array $fields, ?string $icon = null, ?string $group = null, ?string $titleField = null): self
    {
        $plural = Naming::plural($name);

        return new self(
            $name,
            $fields,
            Naming::snake($plural),
            Naming::kebab($plural),
            Naming::humanize($name),
            Naming::humanize($plural),
            $icon,
            $group,
            $titleField,
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $name = (string) ($data['name'] ?? '');
        $fields = [];
        foreach (($data['fields'] ?? []) as $key => $field) {
            $fields[$key] = Field::fromArray($key, (array) $field);
        }
        $base = self::make($name, $fields);

        return new self(
            $name,
            $fields,
            $data['table'] ?? $base->table,
            $data['slug'] ?? $base->slug,
            $data['label'] ?? $base->label,
            $data['pluralLabel'] ?? $base->pluralLabel,
            $data['icon'] ?? null,
            $data['group'] ?? null,
            $data['titleField'] ?? null,
        );
    }

    public static function fromJson(string $json): self
    {
        try {
            return self::fromArray(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Descriptor is not valid JSON: '.$e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            '$schema' => 'https://nevela.dev/schema/resource.v1.json',
            'name' => $this->name,
            'table' => $this->table,
            'slug' => $this->slug,
            'label' => $this->label,
            'pluralLabel' => $this->pluralLabel,
            'icon' => $this->icon,
            'group' => $this->group,
            'titleField' => $this->titleField,
            'fields' => array_map(fn (Field $f) => $f->toArray(), $this->fields),
        ], fn ($v) => $v !== null);
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    /** The field shown as a record's title: explicit, else the first string field, else id. */
    public function title(): string
    {
        if ($this->titleField !== null) {
            return $this->titleField;
        }
        foreach ($this->fields as $field) {
            if ($field->kind === 'string') {
                return $field->name;
            }
        }

        return 'id';
    }

    public function variable(): string
    {
        return Naming::camel($this->name);
    }

    /** Route parameter name Laravel's apiResource derives from the slug. */
    public function routeParameter(): string
    {
        return str_replace('-', '_', Naming::snake($this->name));
    }

    /** Split on commas that are not inside parentheses or square brackets. @return list<string> */
    private static function splitSpec(string $spec): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        foreach (str_split($spec) as $char) {
            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            }
            if ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }
        $parts[] = $current;

        return $parts;
    }
}
