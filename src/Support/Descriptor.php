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
    /** What a record's id can be: a UUID, which is what Nevela makes, or the auto-incrementing integer of `$table->id()`. */
    public const ID_TYPES = ['uuid', 'int'];

    /**
     * @param  array<string, Field>  $fields  keyed by field name, in declaration order
     * @param  string  $idType  "uuid" or "int". Chosen when the resource is made: changing it later means migrating the table yourself.
     * @param  bool  $adopted  The table was there before Nevela, so no migration is written to create it
     * @param  array<string, Action>  $actions  What can be done to a record or to the list beyond create, edit and delete, by name
     * @param  PublicAccess|null  $public  What of it anyone may read or create without signing in. Nothing, when null.
     * @param  string|null  $publish  The date-and-time field that says when a record is published: empty is a draft, a time to come is scheduled
     * @param  string|null  $blog  Where on the site its public records are a blog, e.g. "/blog". The pages are written by `nevela:blog`.
     */
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
        public readonly string $idType = 'uuid',
        public readonly bool $adopted = false,
        public readonly array $actions = [],
        public readonly ?PublicAccess $public = null,
        public readonly ?string $publish = null,
        public readonly ?string $blog = null,
    ) {
        if (! preg_match('/^[A-Z][A-Za-z0-9]*$/', $name)) {
            throw new InvalidArgumentException('Resource name must be PascalCase, e.g. Product or BlogPost.');
        }
        if (! in_array($idType, self::ID_TYPES, true)) {
            throw new InvalidArgumentException("\"{$idType}\" is not an id type. Use uuid or int.");
        }
        if ($fields === []) {
            throw new InvalidArgumentException("Resource {$name} needs at least one field.");
        }
        if ($titleField !== null && ! isset($fields[$titleField])) {
            throw new InvalidArgumentException("titleField \"{$titleField}\" is not a field of {$name}.");
        }
        $keys = [];
        foreach ($actions as $action) {
            foreach (array_keys($action->when) as $field) {
                if (! isset($fields[$field])) {
                    throw new InvalidArgumentException("Action \"{$action->name}\" of {$name} depends on \"{$field}\", which is not one of its fields.");
                }
            }
            // sendNow and send_now would be the same permission.
            if (isset($keys[$action->key()])) {
                throw new InvalidArgumentException("{$name} has two actions that come to \"{$action->key()}\".");
            }
            $keys[$action->key()] = true;
        }
        $public?->check($name, $fields);
        foreach ($fields as $field) {
            if ($field->from !== null && (! isset($fields[$field->from]) || ! in_array($fields[$field->from]->kind, ['string', 'text'], true))) {
                throw new InvalidArgumentException("{$name}: \"{$field->name}\" is made from \"{$field->from}\", which has to be a string field of {$name}.");
            }
        }
        if ($publish !== null && (! isset($fields[$publish]) || $fields[$publish]->kind !== 'datetime' || $fields[$publish]->required)) {
            throw new InvalidArgumentException("{$name}: \"publish\" names the field that says when a record is published. It has to be an optional datetime: {$publish}:datetime?");
        }
        if ($blog !== null) {
            if (! preg_match('#^/[a-z0-9][a-z0-9/-]*$#', $blog)) {
                throw new InvalidArgumentException("{$name}: \"blog\" is where the blog is on the site, such as \"/blog\".");
            }
            if ($public?->read === null || $public->by === null) {
                throw new InvalidArgumentException("{$name}: a blog reads its posts publicly, each by a unique field. Give it \"public\": { \"read\": { \"by\": \"slug\", \"fields\": […] } }.");
            }
        }
    }

    /** Build from a name and a `--fields` string: "name:string, price:money, status:enum(draft|live)?". */
    public static function fromSpec(string $name, string $spec, ?string $icon = null, ?string $group = null, string $idType = 'uuid'): self
    {
        $fields = [];
        foreach (array_filter(array_map('trim', self::splitSpec($spec))) as $token) {
            $field = Field::parse($token);
            if (isset($fields[$field->name])) {
                throw new InvalidArgumentException("Field \"{$field->name}\" is declared twice.");
            }
            $fields[$field->name] = $field;
        }

        return self::make(Naming::pascal($name), $fields, icon: $icon, group: $group, idType: $idType);
    }

    /** @param array<string, Field> $fields */
    public static function make(string $name, array $fields, ?string $icon = null, ?string $group = null, ?string $titleField = null, string $idType = 'uuid'): self
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
            $idType,
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
        $actions = [];
        foreach ((array) ($data['actions'] ?? []) as $key => $action) {
            $actions[(string) $key] = Action::fromArray((string) $key, (array) $action);
        }

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
            (string) ($data['id'] ?? 'uuid'),
            (bool) ($data['adopted'] ?? false),
            $actions,
            isset($data['public']) ? PublicAccess::fromArray((array) $data['public']) : null,
            $data['publish'] ?? null,
            $data['blog'] ?? null,
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
            // Left out when they are what every descriptor written before them means.
            'id' => $this->idType === 'uuid' ? null : $this->idType,
            'adopted' => $this->adopted ?: null,
            'slug' => $this->slug,
            'label' => $this->label,
            'pluralLabel' => $this->pluralLabel,
            'icon' => $this->icon,
            'group' => $this->group,
            'titleField' => $this->titleField,
            'publish' => $this->publish,
            'blog' => $this->blog,
            'fields' => array_map(fn (Field $f) => $f->toArray(), $this->fields),
            'actions' => $this->actions ? array_map(fn (Action $a) => $a->toArray(), $this->actions) : null,
            'public' => $this->public?->toArray(),
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

    /**
     * This resource with only some of its fields: what a list of it is sorted, filtered
     * and searched by when the list is the public one.
     *
     * @param  list<string>  $fields
     */
    public function only(array $fields): self
    {
        return new self($this->name, array_intersect_key($this->fields, array_flip($fields)), $this->table, $this->slug, $this->label, $this->pluralLabel, $this->icon, $this->group, null, $this->idType, $this->adopted);
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
