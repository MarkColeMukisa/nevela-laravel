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
     * @param  string|null  $items  The resource its lines are, e.g. "OrderItem": edited in this one's form and saved with it. That resource belongs to this one.
     * @param  list<list<string>>  $unique  Fields whose values go together once only, by name: [["studentId", "courseId"]] is one enrolment for each student and course
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
        public readonly ?string $items = null,
        public readonly array $unique = [],
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
            if ($field->from !== null && str_contains($field->from, '.')) {
                $relation = explode('.', $field->from)[0];
                if (! isset($fields[$relation]) || $fields[$relation]->kind !== 'belongsTo') {
                    throw new InvalidArgumentException("{$name}: \"{$field->name}\" is copied from \"{$field->from}\", and \"{$relation}\" has to be a relation of {$name}, such as {$relation}:belongsTo(…).");
                }

                continue;
            }
            if ($field->from !== null && (! isset($fields[$field->from]) || ! in_array($fields[$field->from]->kind, ['string', 'text'], true))) {
                throw new InvalidArgumentException("{$name}: \"{$field->name}\" is made from \"{$field->from}\", which has to be a string field of {$name}.");
            }
            foreach ($field->when as $on => $values) {
                $other = $fields[$on] ?? null;
                if ($other === null || ! in_array($other->kind, ['enum', 'boolean'], true)) {
                    throw new InvalidArgumentException("{$name}: \"{$field->name}\" applies for some values of \"{$on}\", which has to be an enum or a yes/no field of {$name}.");
                }
                if ($other->conditional()) {
                    throw new InvalidArgumentException("{$name}: \"{$field->name}\" applies for some values of \"{$on}\", which only sometimes applies itself. Make it depend on the field \"{$on}\" depends on.");
                }
                foreach ($values as $value) {
                    $fits = $other->kind === 'boolean' ? is_bool($value) : is_string($value) && in_array($value, $other->options, true);
                    if (! $fits) {
                        $shown = is_bool($value) ? var_export($value, true) : $value;
                        throw new InvalidArgumentException("{$name}: \"{$field->name}\" applies when \"{$on}\" is {$shown}, which it can't be. Use ".($other->kind === 'boolean' ? 'true or false' : 'one of: '.implode(', ', $other->options)).'.');
                    }
                }
            }
        }
        if ($publish !== null && (! isset($fields[$publish]) || $fields[$publish]->kind !== 'datetime' || $fields[$publish]->required)) {
            throw new InvalidArgumentException("{$name}: \"publish\" names the field that says when a record is published. It has to be an optional datetime: {$publish}:datetime?");
        }
        if ($items !== null && (! preg_match('/^[A-Z][A-Za-z0-9]*$/', $items) || $items === $name)) {
            throw new InvalidArgumentException("{$name}: \"items\" names the resource its lines are, such as \"OrderItem\": another resource, in PascalCase.");
        }
        foreach ($unique as $set) {
            if (! is_array($set) || ! array_is_list($set) || count($set) < 2 || count(array_unique($set)) !== count($set)) {
                throw new InvalidArgumentException("{$name}: each set in \"unique\" is two or more of its fields, such as [\"studentId\", \"courseId\"]. One field alone is unique with \"unique\": true on the field.");
            }
            foreach ($set as $field) {
                if (! is_string($field) || ! isset($fields[$field])) {
                    throw new InvalidArgumentException("{$name}: \"unique\" names \"".(is_string($field) ? $field : json_encode($field))."\", which is not one of its fields.");
                }
                // A database can't index a long text or say two files are the same.
                if (in_array($fields[$field]->kind, ['text', 'file'], true)) {
                    throw new InvalidArgumentException("{$name}: \"{$field}\" is a {$fields[$field]->kind} field, which can't be part of a unique set.");
                }
            }
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
            $data['items'] ?? null,
            array_values((array) ($data['unique'] ?? [])),
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
            'items' => $this->items,
            'fields' => array_map(fn (Field $f) => $f->toArray(), $this->fields),
            'unique' => $this->unique ?: null,
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

    /**
     * The fields a set in `--unique` names, as they are called: "student,course" are
     * studentId and courseId when those are relations, as they are in the descriptor.
     *
     * @param  array<string, Field>  $fields
     * @return list<string>
     */
    public static function uniqueSet(array $fields, string $spec): array
    {
        $set = [];
        foreach (array_filter(array_map('trim', explode(',', $spec)), fn (string $name) => $name !== '') as $name) {
            $set[] = match (true) {
                isset($fields[$name]) => $name,
                isset($fields["{$name}Id"]) && $fields["{$name}Id"]->kind === 'belongsTo' => "{$name}Id",
                default => $name, // refused by the constructor, which says it isn't a field
            };
        }

        return $set;
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
