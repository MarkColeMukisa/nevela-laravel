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

    /** @var list<array{field: string, column: string, value: string|int|float|bool|null}> */
    public array $filters = [];

    /** @param array<string, mixed> $params */
    public static function parse(Descriptor $resource, array $params, int $defaultPerPage = 25, int $maxPerPage = 100): self
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
            if ($field === null || ! $field->filterable()) {
                $query->issue($param, "Can't filter by \"{$key}\".");

                continue;
            }
            $coerced = self::coerce($field, is_array($value) ? null : $value);
            if ($coerced instanceof Issue) {
                $query->issue($param, $coerced->message);

                continue;
            }
            $query->filters[] = ['field' => $key, 'column' => $field->column(), 'value' => $coerced];
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
            return $field->required ? new Issue('This field is never empty.') : null;
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
