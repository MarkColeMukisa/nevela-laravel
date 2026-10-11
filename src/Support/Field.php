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
        'text' => ['rich'],
        'float' => ['money', 'percent', 'rating'],
        'int' => ['percent', 'rating'],
    ];

    /** What a file field may accept, in Flare's categories. The MIME types are in Media\FileTypes. */
    public const ACCEPTS = ['any', 'image', 'pdf', 'video', 'audio', 'text', 'csv', 'document', 'spreadsheet', 'archive'];

    /** Shorthand types accepted by --fields, mapped to kind + format. */
    private const ALIASES = [
        'string' => ['string', null], 'text' => ['text', null], 'richtext' => ['text', 'rich'],
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
     * @param  int|null  $maxLength  string and text: the most characters it holds, where that isn't the usual 255 or 65,535
     * @param  bool  $private  file: kept where the web server doesn't reach, and handed only to someone who may view the record
     * @param  string|int|float|bool|null  $default  What the field is when a create leaves it out. Also the column's default.
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
        public readonly ?int $maxLength = null,
        public readonly bool $private = false,
        public readonly string|int|float|bool|null $default = null,
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
        if ($maxLength !== null && (! in_array($kind, ['string', 'text'], true) || $format === 'color' || $maxLength < 1)) {
            throw new InvalidArgumentException("\"{$name}\" can't have a length of {$maxLength}: a length is a whole number of 1 or more, on a string or text field.");
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
        if ($default !== null) {
            $fits = match ($kind) {
                'string', 'text' => is_string($default) && ! ($kind === 'text' && $format === 'rich'),
                'int' => is_int($default),
                'float' => is_int($default) || is_float($default),
                'boolean' => is_bool($default),
                'enum' => is_string($default) && in_array($default, $options, true),
                default => false,
            };
            if (! $fits) {
                throw new InvalidArgumentException("The default of \"{$name}\" has to be a value the field could hold".($kind === 'enum' ? ': one of '.implode(', ', $options).'.' : (in_array($kind, ['string', 'text', 'int', 'float', 'boolean'], true) ? '.' : ", and a {$kind} field can't have one.")));
            }
            if ($unique) {
                throw new InvalidArgumentException("\"{$name}\" is unique, so only one record could ever have its default.");
            }
        }
        if ($private && $kind !== 'file') {
            throw new InvalidArgumentException("\"{$name}\" can't be private: that is for file fields. Who may see a record's other fields is its policy's to say.");
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
     *   excerpt:string(500)               a length other than the usual 255
     *   body:richtext                     formatted text, kept as HTML that has been cleaned
     *   category:belongsTo(Category)      stored as categoryId
     *   image:image                       an optimised image; image:image(product) names a profile
     *   manual:file(pdf|document)         any other upload
     *   cv:file(pdf|private)              one only those who may view the record can fetch
     *   status:enum(pending|paid)=pending what it is when a create leaves it out
     *
     * Grit's and Flare's spellings are understood too: see normalise().
     */
    public static function parse(string $token): self
    {
        $written = trim($token);
        // status:enum(pending|paid)=pending: what the field is when a create leaves it out.
        $default = null;
        if (($at = strpos($written, '=')) !== false) {
            $default = trim(substr($written, $at + 1));
            $written = rtrim(substr($written, 0, $at));
        }
        if ($default !== null) {
            $field = self::parse($written);
            $value = match ($field->kind) {
                'int' => filter_var($default, FILTER_VALIDATE_INT) === false ? $default : (int) $default,
                'float' => is_numeric($default) ? (float) $default : $default,
                'boolean' => match (strtolower($default)) {
                    'true', '1', 'yes' => true,
                    'false', '0', 'no' => false,
                    default => $default,
                },
                default => $default,
            };

            return self::fromArray($field->name, ['default' => $value] + $field->toArray());
        }
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
            // cv:file(pdf|private): "private" among what it takes is how it is kept, not a kind of file.
            $accept = array_values(array_diff($list, ['private']));

            return new self($name, 'file', null, $required, $unique, accept: $accept ?: ['any'], private: count($accept) !== count($list));
        }
        if (! isset(self::ALIASES[$type])) {
            throw new InvalidArgumentException("Unknown type \"{$type}\" on \"{$name}\". Use one of: ".implode(', ', array_keys(self::ALIASES)).', enum(a|b), belongsTo(Resource), image, file(pdf|…).');
        }
        [$kind, $format] = self::ALIASES[$type];
        // excerpt:string(500): a length of its own.
        $maxLength = in_array($kind, ['string', 'text'], true) && $format !== 'color' && ctype_digit($args) ? (int) $args : null;

        return new self($name, $kind, $format, $required, $unique, maxLength: $maxLength);
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
            isset($data['maxLength']) ? (int) $data['maxLength'] : null,
            (bool) ($data['private'] ?? false),
            $data['default'] ?? null,
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
            'maxLength' => $this->maxLength,
            'private' => $this->private ?: null,
            'default' => $this->default,
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

    /** Whether this is text with formatting: written in an editor, kept as HTML, cleaned on the way in and out. */
    public function isRich(): bool
    {
        return $this->kind === 'text' && $this->format === 'rich';
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
