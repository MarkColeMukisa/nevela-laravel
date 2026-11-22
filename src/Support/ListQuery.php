<?php

namespace Nevela\Laravel\Support;

/**
 * Flare's list query, parsed and checked against a descriptor.
 *
 *   ?page=2&perPage=25&sort=-createdAt&q=ada&filter[status]=live
 *
 * Pure on purpose: what's allowed is decided here, and applying it to an Eloquent
 * builder (ResourceQuery) is a thin step on top. Unknown or unsortable fields are
 * reported as issues — the API answers 400 — rather than silently ignored.
 */
final class ListQuery
{
    /** @var list<array{param: string, message: string}> */
    public array $issues = [];

    public int $page = 1;

    public int $perPage;

    /** @var array{field: string, column: string, direction: 'asc'|'desc'} */
    public array $sort = ['field' => 'createdAt', 'column' => 'created_at', 'direction' => 'desc'];

    public ?string $q = null;

    /** The three things a record with a publish date can be. */
    public const PUBLISHED = ['published', 'draft', 'scheduled'];

    /** The filter that isn't a field: whether a record's address has been proved ("confirm"). */
    public const CONFIRMED = 'confirmed';

    /** filter[field][operator]: what each kind of field can be asked, beyond being equal to one value. */
    public const OPERATORS = [
        'string' => ['in', 'contains'],
        'int' => ['in', 'gt', 'lt', 'between'],
        'float' => ['in', 'gt', 'lt', 'between'],
        'enum' => ['in'],
        'belongsTo' => ['in'],
        'date' => ['gt', 'lt', 'between'],
        'datetime' => ['gt', 'lt', 'between'],
        'boolean' => [],
    ];

    /** The kinds whose values never hold a comma, so filter[field]=a,b can mean "a or b". */
    public const COMMA_LISTS = ['enum', 'belongsTo', 'int'];

    /** The times every record has, filtered by range only: filter[createdAt][gt]=2026-11-01. */
    public const TIMESTAMPS = ['createdAt' => 'created_at', 'updatedAt' => 'updated_at'];

    /** The most values "in" takes: enough for any screen, well short of what a database allows in one query. */
    public const MAX_IN = 100;

    /**
     * A filter on the field a resource is published by carries `state` in place of a value.
     * One with an operator carries `op`: "in" with a list of values, "range" with a list of
     * [comparison, value] (a date given for a time stands for the whole day), "contains"
     * with words to find. One without is equal to its value.
     *
     * @var list<array{field: string, column: string, value: mixed, state?: string, confirmed?: bool, op?: 'in'|'range'|'contains'}>
     */
    public array $filters = [];

    /** @var list<'items'> ?include=items: each record with its lines. */
    public array $include = [];

    /**
     * @param  array<string, mixed>  $params
     * @param  string  $timezone  The app's: what a date or a time without an offset is read in.
     * @param  bool  $timestamps  Whether createdAt and updatedAt can be filtered by. Not on the public list, whose records don't show them.
     */
    public static function parse(Descriptor $resource, array $params, int $defaultPerPage = 25, int $maxPerPage = 100, string $timezone = 'UTC', bool $timestamps = true): self
    {
        $query = new self;
        $query->perPage = $defaultPerPage;

        if (isset($params['page'])) {
            $page = filter_var($params['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $page === false ? $query->issue('page', 'Must be a whole number of 1 or more.') : $query->page = $page;
        }

        if (isset($params['perPage'])) {
            $perPage = filter_var($params['perPage'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $maxPerPage]]);
            $perPage === false ? $query->issue('perPage', "Must be between 1 and {$maxPerPage}.") : $query->perPage = $perPage;
        }

        if (isset($params['sort']) && $params['sort'] !== '') {
            $raw = (string) $params['sort'];
            $direction = str_starts_with($raw, '-') ? 'desc' : 'asc';
            $key = ltrim($raw, '-');
            $column = self::sortableColumn($resource, $key);
            $column === null
                ? $query->issue('sort', "Can't sort by \"{$key}\".")
                : $query->sort = ['field' => $key, 'column' => $column, 'direction' => $direction];
        }

        if (isset($params['q']) && is_string($params['q']) && trim($params['q']) !== '') {
            $query->q = mb_substr(trim($params['q']), 0, 200);
        }

        $filters = $params['filter'] ?? [];
        if (! is_array($filters)) {
            $query->issue('filter', 'Use filter[field]=value.');
            $filters = [];
        }
        foreach ($filters as $key => $value) {
            $param = "filter[{$key}]";
            $field = $resource->fields[$key] ?? null;
            // filter[publishedAt]=draft: not a date to match, but which of the three it is.
            if ($field !== null && $resource->publish === $key) {
                $state = is_string($value) ? strtolower($value) : '';
                in_array($state, self::PUBLISHED, true)
                    ? $query->filters[] = ['field' => $key, 'column' => $field->column(), 'value' => null, 'state' => $state]
                    : $query->issue($param, 'Must be one of: '.implode(', ', self::PUBLISHED).'.');

                continue;
            }
            // filter[confirmed]=true: whether the address was proved, for a resource whose form asks.
            if ($field === null && $key === self::CONFIRMED && $resource->public?->confirm !== null) {
                $proved = is_array($value) ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $proved === null
                    ? $query->issue($param, 'Must be true or false.')
                    : $query->filters[] = ['field' => $key, 'column' => 'confirmed_at', 'value' => null, 'confirmed' => $proved];

                continue;
            }
            // filter[createdAt][gt]=2026-11-01: when it was made or last changed, by range.
            if ($field === null && $timestamps && isset(self::TIMESTAMPS[$key])) {
                is_array($value)
                    ? $query->operators($param, $key, self::TIMESTAMPS[$key], 'datetime', null, $value, $timezone)
                    : $query->issue($param, "Use filter[{$key}][gt], [lt] or [between].");

                continue;
            }
            if ($field === null || ! $field->filterable()) {
                $query->issue($param, "Can't filter by \"{$key}\".");

                continue;
            }
            if (is_array($value)) {
                // filter[status][]=a&filter[status][]=b: one of these.
                array_is_list($value)
                    ? $query->operators($param, $key, $field->column(), $field->kind, $field, ['in' => $value], $timezone)
                    : $query->operators($param, $key, $field->column(), $field->kind, $field, $value, $timezone);

                continue;
            }
            // filter[status]=a,b: one of these, where a value can't hold a comma of its own.
            if (is_string($value) && str_contains($value, ',') && in_array($field->kind, self::COMMA_LISTS, true)) {
                $query->operators($param, $key, $field->column(), $field->kind, $field, ['in' => $value], $timezone);

                continue;
            }
            $coerced = self::coerce($field, $value);
            if ($coerced instanceof Issue) {
                $query->issue($param, $coerced->message);

                continue;
            }
            $query->filters[] = ['field' => $key, 'column' => $field->column(), 'value' => $coerced];
        }

        $include = $params['include'] ?? null;
        if ($include !== null && $include !== '') {
            foreach (is_string($include) ? array_filter(array_map('trim', explode(',', $include))) : [null] as $name) {
                match (true) {
                    $name !== 'items' => $query->issue('include', 'Only "items" can be included.'),
                    $resource->items === null => $query->issue('include', "{$resource->pluralLabel} have no lines to include."),
                    default => $query->include = ['items'],
                };
            }
        }

        return $query;
    }

    public function ok(): bool
    {
        return $this->issues === [];
    }

    private function issue(string $param, string $message): void
    {
        $this->issues[] = ['param' => $param, 'message' => $message];
    }

    /**
     * filter[price][gt]=10&filter[price][lt]=100: each operator one more thing the records
     * have to be. Every one is checked; a field is never asked what its kind can't answer.
     *
     * @param  array<array-key, mixed>  $operators
     */
    private function operators(string $param, string $key, string $column, string $kind, ?Field $field, array $operators, string $timezone): void
    {
        $allowed = self::OPERATORS[$kind] ?? [];
        foreach ($operators as $op => $value) {
            $at = "{$param}[{$op}]";
            if (! in_array($op, $allowed, true)) {
                $this->issue($at, $allowed === []
                    ? "\"{$key}\" is matched by one value only: filter[{$key}]=…"
                    : "Can't use \"{$op}\" on \"{$key}\". Use ".implode(', ', $allowed).'.');

                continue;
            }
            if ($op === 'contains') {
                $words = is_string($value) ? trim($value) : '';
                $words === ''
                    ? $this->issue($at, 'Give the words to find.')
                    : $this->filters[] = ['field' => $key, 'column' => $column, 'op' => 'contains', 'value' => mb_substr($words, 0, 200)];

                continue;
            }
            $values = self::listOf($value);
            if ($op === 'in') {
                if ($values === [] || count($values) > self::MAX_IN) {
                    $this->issue($at, 'Give between 1 and '.self::MAX_IN.' values, separated by commas.');

                    continue;
                }
                $coerced = [];
                foreach ($values as $one) {
                    $one = $field === null || $one === 'null' ? new Issue('Each has to be a value; leave out "null".') : self::coerce($field, $one);
                    if ($one instanceof Issue) {
                        $this->issue($at, $one->message);

                        continue 2;
                    }
                    $coerced[] = $one;
                }
                // Each once, by what it is: "01" and "1" are two references, not one.
                $unique = [];
                foreach ($coerced as $one) {
                    $unique[get_debug_type($one).':'.var_export($one, true)] = $one;
                }
                $this->filters[] = ['field' => $key, 'column' => $column, 'op' => 'in', 'value' => array_values($unique)];

                continue;
            }
            // gt, lt, between: compared, so numbers and dates only.
            if ($op === 'between' && count($values) !== 2) {
                $this->issue($at, 'Give two values, the lowest first, separated by a comma.');

                continue;
            }
            if ($op !== 'between' && count($values) !== 1) {
                $this->issue($at, 'Give one value.');

                continue;
            }
            $bounds = [];
            foreach ($values as $i => $one) {
                $bound = self::bound($kind, $one, $timezone);
                if ($bound instanceof Issue) {
                    $this->issue($at, $bound->message);

                    continue 2;
                }
                $bounds[$i] = $bound;
            }
            // A whole day as the end runs to the next midnight: noon on the 2nd to the 2nd is a range.
            if ($op === 'between' && ($bounds[1]['day'] ? $bounds[0]['from'] >= $bounds[1]['next'] : $bounds[0]['from'] > $bounds[1]['until'])) {
                $this->issue($at, 'The first has to be no later or larger than the second.');

                continue;
            }
            // A day stands for all of it: after it is from the next one, before it is before it starts.
            $this->filters[] = ['field' => $key, 'column' => $column, 'op' => 'range', 'value' => match ($op) {
                'gt' => [[$bounds[0]['day'] ? '>=' : '>', $bounds[0]['day'] ? $bounds[0]['next'] : $bounds[0]['from']]],
                'lt' => [['<', $bounds[0]['from']]],
                default => [
                    ['>=', $bounds[0]['from']],
                    $bounds[1]['day'] ? ['<', $bounds[1]['next']] : ['<=', $bounds[1]['until']],
                ],
            }];
        }
    }

    /**
     * "a,b", or a list of values sent as filter[field][op][]=…
     *
     * @return list<string>
     */
    private static function listOf(mixed $value): array
    {
        $values = is_array($value) ? $value : (is_scalar($value) ? explode(',', (string) $value) : []);
        $values = array_map(fn ($one) => is_scalar($one) ? trim((string) $one) : '', $values);

        return array_values(array_filter($values, fn (string $one) => $one !== ''));
    }

    /**
     * One end of a range. A number is itself. A date on a date field is that day; on a time
     * field it is the whole day, so it also says where the next begins. A time is read in
     * the app's timezone unless it carries an offset, and given back in it.
     *
     * @return array{from: int|float|string|\DateTimeImmutable, until: int|float|string|\DateTimeImmutable, day: bool, next: string|\DateTimeImmutable|null}|Issue
     */
    private static function bound(string $kind, string $value, string $timezone): array|Issue
    {
        if ($kind === 'int' || $kind === 'float') {
            $number = $kind === 'int'
                ? (filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : null)
                : (is_numeric($value) ? (float) $value : null);

            return $number === null
                ? new Issue($kind === 'int' ? 'Must be a whole number.' : 'Must be a number.')
                : ['from' => $number, 'until' => $number, 'day' => false, 'next' => null];
        }
        $zone = new \DateTimeZone($timezone);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            if ($kind === 'date') {
                return ['from' => $value, 'until' => $value, 'day' => false, 'next' => null];
            }
            $start = new \DateTimeImmutable($value.' 00:00:00', $zone);

            return ['from' => $start, 'until' => $start, 'day' => true, 'next' => $start->modify('+1 day')];
        }
        if ($kind === 'date') {
            return new Issue('Must be a date, written YYYY-MM-DD.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/', $value) !== 1) {
            return new Issue('Must be a date (YYYY-MM-DD) or a time (ISO 8601).');
        }
        try {
            $time = new \DateTimeImmutable($value, $zone);
            // The 30th of February is read as the 2nd of March, with a warning; it isn't a date.
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                return new Issue('Must be a date (YYYY-MM-DD) or a time (ISO 8601).');
            }
            $time = $time->setTimezone($zone);
        } catch (\Exception) {
            return new Issue('Must be a date (YYYY-MM-DD) or a time (ISO 8601).');
        }

        return ['from' => $time, 'until' => $time, 'day' => false, 'next' => null];
    }

    private static function sortableColumn(Descriptor $resource, string $key): ?string
    {
        return match (true) {
            $key === 'createdAt' => 'created_at',
            $key === 'updatedAt' => 'updated_at',
            isset($resource->fields[$key]) && $resource->fields[$key]->sortable() => $resource->fields[$key]->column(),
            default => null,
        };
    }

    private static function coerce(Field $field, mixed $value): string|int|float|bool|null|Issue
    {
        if ($value === null || $value === '' || $value === 'null') {
            return $field->alwaysRequired() ? new Issue('This field is never empty.') : null;
        }
        $value = (string) $value;

        return match ($field->kind) {
            'boolean' => match (strtolower($value)) {
                'true', '1' => true,
                'false', '0' => false,
                default => new Issue('Use true or false.'),
            },
            'int' => filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : new Issue('Must be a whole number.'),
            'float' => is_numeric($value) ? (float) $value : new Issue('Must be a number.'),
            'enum' => in_array($value, $field->options, true) ? $value : new Issue('Must be one of: '.implode(', ', $field->options).'.'),
            default => $value,
        };
    }
}
