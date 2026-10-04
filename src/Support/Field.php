<?php

namespace Nevela\Laravel\Support;

use InvalidArgumentException;

/**
 * One field of a resource, using Flare's field kinds so the same descriptor drives
 * Laravel (columns, rules, casts) and the Flare dashboard (widgets, columns, filters).
 */
final class Field
{
    public const KINDS = ['string', 'text', 'int', 'float', 'boolean', 'date', 'datetime', 'enum', 'belongsTo', 'file'];

    public const FORMATS = [
        'string' => ['email', 'url', 'tel', 'slug', 'color'],
        'float' => ['money', 'percent', 'rating'],
        'int' => ['percent', 'rating'],
    ];

    /** What a file field may accept, in Flare's categories. The MIME types are in Media\FileTypes. */
    public const ACCEPTS = ['any', 'image', 'pdf', 'video', 'audio', 'text', 'csv', 'document', 'spreadsheet', 'archive'];

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
     * @param  string|null  $target  belongsTo: the resource this points at, e.g. "Category"
     * @param  list<string>  $accept  file: the categories it takes, e.g. ["image"]
     * @param  string|null  $profile  file: the image profile uploads are optimised with (config nevela.uploads.profiles)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly ?string $format = null,
        public readonly bool $required = true,
        public readonly bool $unique = false,
        public readonly array $options = [],
        public readonly ?string $label = null,
        public readonly ?string $target = null,
        public readonly array $accept = [],
        public readonly ?string $profile = null,
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
        if ($kind === 'belongsTo') {
            if ($target === null || ! preg_match('/^[A-Z][A-Za-z0-9]*$/', $target)) {
                throw new InvalidArgumentException("\"{$name}\" needs the resource it belongs to, e.g. category:belongsTo(Category).");
            }
            if (! str_ends_with($name, 'Id') || $name === 'Id') {
                throw new InvalidArgumentException("A belongsTo field holds an id, so its name ends in \"Id\": {$name}Id.");
            }
        }
        if ($kind === 'file') {
            if ($accept === []) {
                throw new InvalidArgumentException("File field \"{$name}\" needs what it accepts, e.g. {$name}:image or {$name}:file(pdf|image).");
            }
            foreach ($accept as $category) {
                if (! in_array($category, self::ACCEPTS, true)) {
                    throw new InvalidArgumentException("\"{$category}\" is not something \"{$name}\" can accept. Use: ".implode(', ', self::ACCEPTS).'.');
                }
            }
            if ($unique) {
                throw new InvalidArgumentException("File field \"{$name}\" can't be unique.");
            }
            if ($profile !== null && ! preg_match('/^[a-z][a-z0-9-]*$/', $profile)) {
                throw new InvalidArgumentException("Image profile \"{$profile}\" on \"{$name}\" may only use lower-case letters, digits and -.");
            }
        }
    }

    /**
     * Parse one `name:type` token. Suffix `?` = optional (nullable), `!` = unique; both may be combined.
     *
     *   status:enum(draft|published)
     *   category:belongsTo(Category)      stored as categoryId
     *   image:image                       an optimised image; image:image(product) names a profile
     *   manual:file(pdf|document)         any other upload
     *
     * Grit's and Flare's spellings are understood too: see normalise().
     */
    public static function parse(string $token): self
    {
        $written = trim($token);
        $token = self::normalise($written);
        if (! preg_match('/^([A-Za-z][A-Za-z0-9_]*):([A-Za-z_]+)(?:\(([^)]*)\))?([?!]{0,2})$/', $token, $m)) {
            throw new InvalidArgumentException("Invalid field \"{$written}\". Expected name:type, e.g. price:money, status:enum(draft|live)?, image:image or category:belongsTo(Category).");
        }
        [, $name, $type, $args, $suffix] = $m + [3 => '', 4 => ''];
        $name = Naming::camel($name);
        $required = ! str_contains($suffix, '?');
        $unique = str_contains($suffix, '!');
        $list = array_values(array_filter(array_map('trim', explode('|', $args)), fn ($v) => $v !== ''));

        if ($type === 'enum') {
            return new self($name, 'enum', null, $required, $unique, $list);
        }
        if (in_array($type, ['belongsTo', 'belongs_to', 'belongsto'], true)) {
            // "category" and "categoryId" both mean the column category_id.
            $name = str_ends_with($name, 'Id') ? $name : $name.'Id';

            return new self($name, 'belongsTo', null, $required, $unique, target: $list[0] ?? Naming::pascal(substr($name, 0, -2)));
        }
        if ($type === 'image') {
            return new self($name, 'file', null, $required, $unique, accept: ['image'], profile: $list[0] ?? null);
        }
        if ($type === 'file') {
            return new self($name, 'file', null, $required, $unique, accept: $list ?: ['any']);
        }
        if (! isset(self::ALIASES[$type])) {
            throw new InvalidArgumentException("Unknown type \"{$type}\" on \"{$name}\". Use one of: ".implode(', ', array_keys(self::ALIASES)).', enum(a|b), belongsTo(Resource), image, file(pdf|…).');
        }
        [$kind, $format] = self::ALIASES[$type];

        return new self($name, $kind, $format, $required, $unique);
    }

    /**
     * The other ways people write a field, turned into Nevela's own.
     *
     * Grit puts what a type takes after a second colon, and Flare in square brackets, so
     * someone coming from either types what they know:
     *
     *   image:file:image          image:file[image]        image:file:[image]     → image:file(image)
     *   category:belongs_to:Category                                              → category:belongs_to(Category)
     *   status:enum:draft|live    docs:file:[pdf, image]                          → …(draft|live), …(pdf|image)
     */
    private static function normalise(string $token): string
    {
        // Spaces around the punctuation don't mean anything: "file: [ image ]".
        $token = (string) preg_replace('/\s*([:\[\]])\s*/', '$1', $token);
        if (preg_match('/^([^:()\[\]]+):([A-Za-z_]+):?\[([^\[\]()]*)\]([?!]{0,2})$/', $token, $m)
            || preg_match('/^([^:()\[\]]+):([A-Za-z_]+):([^:()\[\]?!]+)([?!]{0,2})$/', $token, $m)) {
            $list = implode('|', array_filter(array_map('trim', preg_split('/[,|]/', $m[3])), fn ($value) => $value !== ''));

            return "{$m[1]}:{$m[2]}({$list}){$m[4]}";
        }

        return $token;
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
            $data['target'] ?? null,
            array_values($data['accept'] ?? []),
            $data['profile'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'kind' => $this->kind,
            'format' => $this->format,
            'options' => $this->options ?: null,
            'target' => $this->target,
            'accept' => $this->accept ?: null,
            'profile' => $this->profile,
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
        // "categoryId" reads as "Category", which is what the picker shows.
        return $this->label ?? Naming::humanize($this->kind === 'belongsTo' ? substr($this->name, 0, -2) : $this->name);
    }

    /** belongsTo: the name of the relation on the model, e.g. "category" for categoryId. */
    public function relation(): string
    {
        return substr($this->name, 0, -2);
    }

    /** Whether this is a file field that takes images only, and so is optimised and shown as a picture. */
    public function isImage(): bool
    {
        return $this->kind === 'file' && $this->accept === ['image'];
    }

    public function sortable(): bool
    {
        return ! in_array($this->kind, ['text', 'file'], true);
    }

    public function filterable(): bool
    {
        return ! in_array($this->kind, ['text', 'file'], true);
    }

    public function searchable(): bool
    {
        return $this->kind === 'string';
    }
}
