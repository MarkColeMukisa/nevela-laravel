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

    /** At most this many files in a field that holds several: gallery:files(image). */
    public const MAX_FILES = 20;

    /**
     * @param  list<string>  $options  enum values
     * @param  string|null  $target  belongsTo: the resource this points at, e.g. "Category"
     * @param  list<string>  $accept  file: the categories it takes, e.g. ["image"]
     * @param  string|null  $profile  file: the image profile uploads are optimised with (config nevela.uploads.profiles)
     * @param  int|null  $maxLength  string and text: the most characters it holds, where that isn't the usual 255 or 65,535
     * @param  bool  $private  file: kept where the web server doesn't reach, and handed only to someone who may view the record
     * @param  string|int|float|bool|null  $default  What the field is when a create leaves it out. Also the column's default.
     * @param  string|null  $from  slug: the field it is made from when a create leaves it empty, e.g. "title". Any other: a relation's field it is copied from, e.g. "productId.price"
     * @param  array<string, string>  $optionLabels  enum: what each value is called where people read it. The value is what is stored.
     * @param  string|null  $currency  money: an ISO 4217 code, "UGX". The app's (nevela.currency) unless set.
     * @param  string|null  $auto  string: the prefix of the number Laravel gives each new record, e.g. "INV" for INV-0001
     * @param  bool|string|null  $list  Its column in the dashboard's table: true shown, "hidden" off until someone turns it on, false left out. Null leaves it to listing().
     * @param  string|null  $country  tel: the country its numbers are of, "UG", kept as +256772123456. Any country's when not set.
     * @param  array<string, list<string|bool>>  $when  The field it applies for, and the values: ["kind" => ["recurring"]]. Empty when it always does. Where it doesn't, it is empty; where it does, it is required if the field is.
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
        public readonly ?string $from = null,
        public readonly array $optionLabels = [],
        public readonly ?string $currency = null,
        public readonly bool|string|null $list = null,
        public readonly ?string $auto = null,
        public readonly ?string $country = null,
        public readonly array $when = [],
        public readonly bool $multiple = false,
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
        if ($optionLabels !== []) {
            if ($kind !== 'enum') {
                throw new InvalidArgumentException("\"{$name}\" can't have option labels: they are for an enum field's values.");
            }
            foreach ($optionLabels as $value => $text) {
                if (! in_array((string) $value, $options, true)) {
                    throw new InvalidArgumentException("\"{$name}\" has a label for \"{$value}\", which is not one of its values: ".implode(', ', $options).'.');
                }
                if (! is_string($text) || trim($text) === '' || mb_strlen($text) > 255) {
                    throw new InvalidArgumentException("The label for \"{$value}\" on \"{$name}\" has to be some words, at most 255 characters.");
                }
            }
        }
        if ($currency !== null) {
            if ($kind !== 'float' || $format !== 'money') {
                throw new InvalidArgumentException("\"{$name}\" can't have a currency: that is for a money field ({$name}:money).");
            }
            if (! self::isCurrency($currency)) {
                throw new InvalidArgumentException("\"{$currency}\" on \"{$name}\" is not a currency. Use its three-letter ISO 4217 code, in capitals: UGX, USD, EUR.");
            }
        }
        if ($country !== null) {
            if ($kind !== 'string' || $format !== 'tel') {
                throw new InvalidArgumentException("\"{$name}\" can't have a country: that is for a phone number ({$name}:tel({$country})).");
            }
            if (! Phone::known($country)) {
                throw new InvalidArgumentException("\"{$country}\" on \"{$name}\" is not a country Nevela knows the numbers of. Use one of: ".implode(', ', array_keys(Phone::COUNTRIES)).", or tel alone for any country's.");
            }
            // +256772123456 is how every number is kept, so the column has to hold one.
            $kept = 1 + strlen(Phone::COUNTRIES[$country]['code']) + Phone::COUNTRIES[$country]['digits'];
            if ($maxLength !== null && $maxLength < $kept) {
                throw new InvalidArgumentException("\"{$name}\" can't have a length of {$maxLength}: its numbers are kept as {$kept} characters, such as ".Phone::normalise(Phone::COUNTRIES[$country]['example'], $country).'.');
            }
            // A default is saved without passing a request, so it is written the way numbers are kept.
            if ($default !== null && (! is_string($default) || Phone::normalise($default, $country) !== $default)) {
                throw new InvalidArgumentException("The default of \"{$name}\" has to be a number of that country as it is kept, such as ".Phone::normalise(Phone::COUNTRIES[$country]['example'], $country).'.');
            }
        }
        if ($list !== null && ! is_bool($list) && $list !== 'hidden') {
            throw new InvalidArgumentException("\"list\" on \"{$name}\" is true (a column in the table), \"hidden\" (a column that starts off) or false (no column).");
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
        if ($auto !== null) {
            if ($kind !== 'string' || $format !== null) {
                throw new InvalidArgumentException("\"{$name}\" can't be numbered: that is for a string, such as {$name}:auto(INV).");
            }
            if (! preg_match('/^[A-Za-z][A-Za-z0-9]{0,9}$/', $auto)) {
                throw new InvalidArgumentException("The prefix of \"{$name}\" is letters and digits, starting with a letter, at most 10: {$name}:auto(INV).");
            }
            if (! $unique) {
                throw new InvalidArgumentException("\"{$name}\" is numbered, so no two records share a number: it has to be unique.");
            }
        }
        if ($from !== null && str_contains($from, '.')) {
            // price:money(product.price): copied from the product the record points at.
            if (! preg_match('/^[a-z][A-Za-z0-9]*Id\.[a-z][A-Za-z0-9]*$/', $from)) {
                throw new InvalidArgumentException("\"{$name}\" is copied from a field of a record it points at, written relation.field: {$name}:money(product.price).");
            }
            if (in_array($kind, ['file', 'belongsTo'], true) || $unique || $auto !== null) {
                throw new InvalidArgumentException("\"{$name}\" can't be copied from another record: a copy is a plain value, and not unique.");
            }
        } elseif ($from !== null) {
            if ($kind !== 'string' || $format !== 'slug') {
                throw new InvalidArgumentException("\"{$name}\" can't be made from another field: that is for a slug ({$name}:slug({$from})).");
            }
            if (! preg_match('/^[a-z][A-Za-z0-9]*$/', $from) || $from === $name) {
                throw new InvalidArgumentException("\"{$name}\" is made from a field of the same resource, named as it is there: {$name}:slug(title).");
            }
        }
        if ($when !== []) {
            $on = array_key_first($when);
            $values = $when[$on];
            if (count($when) > 1 || ! is_string($on) || ! preg_match('/^[a-z][A-Za-z0-9]*$/', $on) || $on === $name) {
                throw new InvalidArgumentException("\"{$name}\" applies for one other field of the same resource, named as it is there: {$name}:… if kind == recurring.");
            }
            if (! is_array($values) || ! array_is_list($values) || $values === [] || array_filter($values, fn ($value) => ! is_bool($value) && (! is_string($value) || $value === '')) !== []) {
                throw new InvalidArgumentException("\"{$name}\" applies for some values of \"{$on}\": one, or several with | between them, such as if {$on} == recurring|retainer.");
            }
            // A required relation is one the seeder, the trash and a delete count on being there.
            if ($kind === 'belongsTo') {
                throw new InvalidArgumentException("\"{$name}\" can't apply only sometimes: it is a relation. Make it optional instead: {$name}:belongsTo({$target})?");
            }
            // A numbered, made or defaulted field is always filled.
            if ($auto !== null || $from !== null || $default !== null) {
                $what = $auto !== null ? 'numbered' : ($from !== null ? 'made or copied from another field' : 'given a default');
                throw new InvalidArgumentException("\"{$name}\" can't apply only sometimes: it is {$what}, and so always has a value.");
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
            if ($multiple && $private) {
                throw new InvalidArgumentException("\"{$name}\" can't be both several files and private: a private file is one, fetched through its record. Make it {$name}:file(…|private), or several that aren't private.");
            }
        }
        if ($multiple && $kind !== 'file') {
            throw new InvalidArgumentException("\"{$name}\" can't hold several: that is for files, {$name}:files(image).");
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
     *   gallery:files(image)              several, up to 20; gallery:images is the same
     *   manual:file(pdf|document)         any other upload
     *   cv:file(pdf|private)              one only those who may view the record can fetch
     *   status:enum(pending|paid)=pending what it is when a create leaves it out
     *   timeline:enum(asap=As soon as possible|month=Within a month)   what each value is called
     *   slug:slug(title)!                 made from the title when left empty
     *   phone:tel(UG)                     a Ugandan number, kept as +256772123456
     *   cycle:enum(monthly|annually) if kind == recurring   only for a recurring one, and needed there
     *
     * Grit's and Flare's spellings are understood too: see normalise().
     */
    public static function parse(string $token): self
    {
        $written = trim($token);
        // cycle:enum(monthly|annually) if kind == recurring: a field that only sometimes applies, in Grit's words.
        if (preg_match('/^(.*\S)\s+if\s+([A-Za-z][A-Za-z0-9_]*)\s*==?\s*(\S.*)$/', $written, $condition)) {
            $field = self::parse($condition[1]);
            $values = array_map(
                fn (string $value) => match (strtolower($value)) {
                    'true' => true,
                    'false' => false,
                    default => $value,
                },
                array_values(array_filter(array_map('trim', explode('|', $condition[3])), fn (string $value) => $value !== '')),
            );

            return self::fromArray($field->name, ['when' => [Naming::camel($condition[2]) => $values]] + $field->toArray());
        }
        // status:enum(pending|paid)=pending: what the field is when a create leaves it out.
        $default = null;
        // The default comes after the type, so an "=" inside its brackets (a value's label) isn't it.
        $close = strrpos($written, ')');
        if (($at = strpos($written, '=', $close === false ? 0 : $close)) !== false) {
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
        // Grit's number:string:auto:INV is number:auto(INV).
        $written = (string) preg_replace('/^([^:]+):string:auto(?::([^:?!]*))?([?!]{0,2})$/', '$1:auto($2)$3', trim($written));
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
            $values = [];
            $labels = [];
            foreach ($list as $item) {
                [$value, $text] = array_pad(array_map('trim', explode('=', $item, 2)), 2, null);
                $values[] = $value;
                if ($text !== null && $text !== '') {
                    $labels[$value] = $text;
                }
            }

            return new self($name, 'enum', null, $required, $unique, $values, optionLabels: $labels);
        }
        if (in_array($type, ['belongsTo', 'belongs_to', 'belongsto'], true)) {
            // "category" and "categoryId" both mean the column category_id.
            $name = str_ends_with($name, 'Id') ? $name : $name.'Id';

            return new self($name, 'belongsTo', null, $required, $unique, target: $list[0] ?? Naming::pascal(substr($name, 0, -2)));
        }
        if ($type === 'image') {
            return new self($name, 'file', null, $required, $unique, accept: ['image'], profile: $list[0] ?? null);
        }
        if ($type === 'file' || $type === 'files') {
            // cv:file(pdf|private): "private" among what it takes is how it is kept, not a kind of file.
            $accept = array_values(array_diff($list, ['private']));

            // gallery:files(image): several, in Grit's word.
            return new self($name, 'file', null, $required, $unique, accept: $accept ?: ['any'], private: count($accept) !== count($list), multiple: $type === 'files');
        }
        if ($type === 'images') {
            return new self($name, 'file', null, $required, $unique, accept: ['image'], profile: $list[0] ?? null, multiple: true);
        }
        if ($type === 'auto') {
            if ($args === '') {
                throw new InvalidArgumentException("\"{$name}\" is numbered with a prefix of your choosing: {$name}:auto(INV) makes INV-0001, INV-0002 and on.");
            }

            return new self($name, 'string', null, true, true, auto: $args);
        }
        if (! isset(self::ALIASES[$type])) {
            throw new InvalidArgumentException("Unknown type \"{$type}\" on \"{$name}\". Use one of: ".implode(', ', array_keys(self::ALIASES)).', enum(a|b), belongsTo(Resource), image, file(pdf|…), files(image).');
        }
        [$kind, $format] = self::ALIASES[$type];
        // price:money(product.price): copied from the record it points at.
        if (preg_match('/^([A-Za-z][A-Za-z0-9_]*)\.([A-Za-z][A-Za-z0-9_]*)$/', $args, $copy)) {
            $relation = Naming::camel($copy[1]);

            return new self($name, $kind, $format, $required, $unique, from: (str_ends_with($relation, 'Id') ? $relation : $relation.'Id').'.'.Naming::camel($copy[2]));
        }
        // slug:slug(title)!: made from the title when a create leaves it empty.
        if ($format === 'slug' && $args !== '' && ! ctype_digit($args)) {
            return new self($name, $kind, $format, $required, $unique, from: Naming::camel($args));
        }
        // phone:tel(UG): one country's numbers. (tel(20) is a length, as for any string.)
        if ($format === 'tel' && $args !== '' && ! ctype_digit($args)) {
            return new self($name, $kind, $format, $required, $unique, country: strtoupper($args));
        }
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
            (bool) ($data['unique'] ?? isset($data['auto'])),
            array_values($data['options'] ?? []),
            $data['label'] ?? null,
            $data['target'] ?? null,
            array_values($data['accept'] ?? []),
            $data['profile'] ?? null,
            isset($data['maxLength']) ? (int) $data['maxLength'] : null,
            (bool) ($data['private'] ?? false),
            $data['default'] ?? null,
            $data['from'] ?? null,
            array_map('strval', (array) ($data['optionLabels'] ?? [])),
            $data['currency'] ?? null,
            $data['list'] ?? null,
            $data['auto'] ?? null,
            $data['country'] ?? null,
            // "when": {"kind": "recurring"}, or several values in a list.
            array_map(fn ($values) => is_array($values) ? array_values($values) : [$values], (array) ($data['when'] ?? [])),
            (bool) ($data['multiple'] ?? false),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'kind' => $this->kind,
            'format' => $this->format,
            'options' => $this->options ?: null,
            'optionLabels' => $this->optionLabels ?: null,
            'currency' => $this->currency,
            'country' => $this->country,
            'multiple' => $this->multiple ?: null,
            'when' => $this->when === [] ? null : array_map(fn (array $values) => count($values) === 1 ? $values[0] : $values, $this->when),
            'target' => $this->target,
            'accept' => $this->accept ?: null,
            'profile' => $this->profile,
            'maxLength' => $this->maxLength,
            'private' => $this->private ?: null,
            'default' => $this->default,
            'from' => $this->from,
            'auto' => $this->auto,
            'required' => $this->required ? null : false,
            'unique' => $this->unique ?: null,
            'label' => $this->label,
            'list' => $this->list,
        ], fn ($v) => $v !== null);
    }

    /**
     * Its column in the dashboard's table: "shown", "hidden" (in the Columns menu, off until
     * turned on) or "none". Long text and files that aren't pictures start hidden, since a
     * paragraph in a row stretches the table; anything else is left to the dashboard, which
     * shows the first few. "auto" is that.
     */
    public function listing(): string
    {
        return match (true) {
            $this->list === true => 'shown',
            $this->list === false => 'none',
            $this->list === 'hidden' => 'hidden',
            $this->kind === 'text',
            $this->kind === 'string' && $this->format === null && $this->maxLength !== null && $this->maxLength > 255,
            $this->kind === 'file' && (! $this->isImage() || $this->private) => 'hidden',
            default => 'auto',
        };
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

    /**
     * What an enum value is called where people read it: its label, or the value made
     * readable as the dashboard does it ("on_hold" → "On hold"). An app's emails and PDFs
     * say the same words as its dashboard this way.
     */
    public function optionLabel(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (isset($this->optionLabels[$value])) {
            return $this->optionLabels[$value];
        }
        $words = trim((string) preg_replace('/[_-]+/', ' ', $value));

        return $words === '' ? $value : mb_strtoupper(mb_substr($words, 0, 1)).mb_substr($words, 1);
    }

    /** Whether a string is a currency's code as ISO 4217 writes it: three capital letters. */
    public static function isCurrency(mixed $code): bool
    {
        return is_string($code) && preg_match('/^[A-Z]{3}$/', $code) === 1;
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

    /** Whether it applies only for some values of another field: cycle if kind == recurring. */
    public function conditional(): bool
    {
        return $this->when !== [];
    }

    /** Whether every record has it: required, and not only for some. The column of one that isn't is nullable. */
    public function alwaysRequired(): bool
    {
        return $this->required && $this->when === [];
    }

    /**
     * Whether the value of the field a conditional one depends on is one it applies for.
     * A yes/no comes back from some databases as 1 and 0, and from a form as "true". One
     * left empty is a no, as its column's default and the dashboard's switch have it.
     *
     * @param  list<string|bool>  $wanted
     */
    public static function holds(mixed $value, array $wanted): bool
    {
        foreach ($wanted as $one) {
            $same = is_bool($one)
                ? filter_var($value === null || $value === '' ? false : $value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === $one
                : $value !== null && is_scalar($value) && (string) $value === $one;
            if ($same) {
                return true;
            }
        }

        return false;
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
