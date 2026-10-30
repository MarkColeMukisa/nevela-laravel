<?php

namespace Nevela\Laravel\Http;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator as Validation;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;
use Nevela\Laravel\Access\Visibility;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Support\Field;
use Nevela\Laravel\Support\Html;
use Nevela\Laravel\Support\Phone;
use Nevela\Laravel\Support\SchemaFacts;
use Nevela\Laravel\Support\Slug;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Base for generated resource requests.
 *
 * Input uses the descriptor's camelCase field names (what Flare's client sends); `values()`
 * maps the validated input onto snake_case columns for Eloquent.
 *
 * - POST: rules as written.
 * - PUT (replace): rules as written, and optional fields left out are cleared to null.
 * - PATCH: every rule becomes "sometimes", so only the fields sent are validated/changed.
 * - Keys that aren't fields fail validation instead of being silently dropped.
 */
abstract class ResourceRequest extends FormRequest
{
    /** Keys Flare may echo back that are never writable. */
    protected const READ_ONLY = ['id', 'createdAt', 'updatedAt'];

    /**
     * The lines that were sent and passed, for saveItems(): each one's values by column,
     * and the id of the line it changes, or null for a new one. Null when none were sent.
     *
     * @var array{values: list<array<string, mixed>>, ids: list<string|null>}|null
     */
    private ?array $checkedItems = null;

    /** @return array<string, mixed> */
    abstract public function rules(): array;

    /**
     * The resource this one's lines are, when it has some: an order's items. A generated
     * request with lines says which, and they are sent as "items".
     *
     * @return array{request: class-string<self>, model: class-string<Model>, field: string, column: string}|null
     */
    protected function lineItems(): ?array
    {
        return null;
    }

    /** @return array<string, string> field => column */
    abstract protected function columns(): array;

    /**
     * Fields that hold rich text. A generated request with any says which.
     *
     * @return list<string>
     */
    protected function rich(): array
    {
        return [];
    }

    /**
     * Phone fields held to one country, and which: phone:tel(UG). A generated request with
     * any says which.
     *
     * @return array<string, string> field => country
     */
    protected function phones(): array
    {
        return [];
    }

    /**
     * What a field is when a create leaves it out. A generated request with any says which.
     *
     * @return array<string, mixed> field => value
     */
    protected function defaults(): array
    {
        return [];
    }

    /**
     * Fields made from another when a create leaves them empty: a slug from a title. A
     * generated request with any says which, and where to look for the ones taken.
     *
     * @return array<string, array{from: string, table: string, column: string}>
     */
    protected function made(): array
    {
        return [];
    }

    /**
     * Fields copied from a record this one points at (an order item's price, from its
     * product). The model makes the copy; what is sent for one is left out. A generated
     * request with any says which.
     *
     * @return list<string>
     */
    protected function copied(): array
    {
        return [];
    }

    /**
     * Fields whose values go together once only: one enrolment for each student and
     * course. A generated request with any says which, in what table, and what to say.
     *
     * @return list<array{fields: list<string>, table: string, message: string}>
     */
    protected function together(): array
    {
        return [];
    }

    /**
     * Fields that apply for some values of another only: a billing cycle, for a recurring
     * project. A generated request with any says which, for what, and what to say.
     *
     * @return array<string, array{on: string, values: list<string|bool>, required: bool, message: string}>
     */
    protected function conditions(): array
    {
        return [];
    }

    /**
     * Whether each conditional field applies, by field. What it depends on is the value
     * sent for it, or, left out of a change, the record's own.
     *
     * @param  array<string, mixed>  $data  by field
     * @return array<string, bool>
     */
    private function applying(array $data, ?Model $record = null): array
    {
        $columns = $this->columns();
        $applies = [];
        foreach ($this->conditions() as $field => $condition) {
            $on = $condition['on'];
            $value = array_key_exists($on, $data) ? $data[$on] : ($record !== null ? $record->getAttribute($columns[$on]) : ($this->defaults()[$on] ?? null));
            $applies[$field] = Field::holds($value, $condition['values']);
        }

        return $applies;
    }

    /**
     * What is said of the required fields left empty where they apply, by field. One the
     * change leaves out keeps the record's own value.
     *
     * @param  array<string, mixed>  $data  by field
     * @param  list<string>  $skip  Fields refused for something else already
     * @return array<string, string>
     */
    private function unmet(array $data, ?Model $record = null, array $skip = []): array
    {
        $columns = $this->columns();
        $conditions = $this->conditions();
        $found = [];
        foreach ($this->applying($data, $record) as $field => $applies) {
            if (! $applies || ! $conditions[$field]['required'] || in_array($field, $skip, true)) {
                continue;
            }
            $value = array_key_exists($field, $data) ? $data[$field] : $record?->getAttribute($columns[$field]);
            if ($value === null || $value === '' || $value === []) {
                $found[$field] = $conditions[$field]['message'];
            }
        }

        return $found;
    }

    /**
     * The values to save with the fields that don't apply emptied: a project made one-off
     * loses its billing cycle, whether or not the change sent one.
     *
     * @param  array<string, mixed>  $values  by column
     * @param  array<string, mixed>  $data  by field
     * @return array<string, mixed>
     */
    private function emptied(array $values, array $data, ?Model $record = null): array
    {
        $columns = $this->columns();
        foreach ($this->applying($data, $record) as $field => $applies) {
            if (! $applies) {
                $values[$columns[$field]] = null;
            }
        }

        return $values;
    }

    /**
     * What `together()` refuses of these values, by the field it is said on: the first of
     * its set. A set with a field still empty isn't checked: two empty values are not the
     * same, to the database either. The trash is looked in too, as the index covers it.
     *
     * @param  array<string, mixed>  $values  by field
     * @param  list<string>  $skip  Sets that name one of these are left out: a line's owner, filled in later
     * @return array<string, string>
     */
    private function clashes(array $values, ?Model $record = null, array $skip = []): array
    {
        $columns = $this->columns();
        $found = [];
        foreach ($this->together() as $set) {
            if (array_intersect($set['fields'], $skip) !== []) {
                continue;
            }
            $query = DB::table($set['table']);
            foreach ($set['fields'] as $field) {
                // A change that leaves a field out keeps the record's own value for it.
                $value = array_key_exists($field, $values) ? $values[$field] : $record?->getAttribute($columns[$field]);
                if ($value === null || $value === '' || ! is_scalar($value)) {
                    continue 2;
                }
                $query->where($columns[$field], $value);
            }
            if ($record !== null) {
                $query->where($record->getKeyName(), '!=', $record->getKey());
            }
            $other = $query->first();
            if ($other === null) {
                continue;
            }
            $found[$set['fields'][0]] = isset($other->deleted_at)
                ? 'There is one with these in the trash. Restore it, or delete it for good to use them again.'
                : $set['message'];
        }

        return $found;
    }

    /** The record a request changes: the one its address names. Null for a create. */
    private function record(): ?Model
    {
        foreach ($this->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model) {
                return $parameter;
            }
        }

        return null;
    }

    /**
     * The record whose values stand for what a change leaves out. A replace (PUT) is the
     * whole record, so what it leaves out is its default or empty, as values() saves it.
     */
    private function beforeChange(): ?Model
    {
        return $this->isMethod('PUT') ? null : $this->record();
    }

    /**
     * Fill what a create left empty and can be made, and keep a later save from emptying
     * it: once made, a slug is an address, and an address doesn't move.
     *
     * @param  array<string, mixed>  $values  by column
     * @param  array<string, true>  $taken  Values made for earlier rows of the same batch
     * @return array<string, mixed>
     */
    private function make(array $values, bool $creating, array &$taken = []): array
    {
        $columns = $this->columns();
        foreach ($this->copied() as $field) {
            unset($values[$columns[$field] ?? $field]);
        }
        foreach ($this->made() as $definition) {
            $column = $definition['column'];
            $empty = ! isset($values[$column]) || $values[$column] === '';
            if (! $empty) {
                $taken[(string) $values[$column]] = true;
            } elseif ($creating) {
                $values[$column] = Slug::free($definition['table'], $column, (string) ($values[$columns[$definition['from']] ?? ''] ?? ''), $taken);
            } else {
                unset($values[$column]);
            }
        }

        return $values;
    }

    /**
     * A create from some of the fields only: a public form's, where nobody is asked who
     * they are and so only what the descriptor lists is taken.
     *
     * The rules are this request's own. A key that isn't listed is refused the way an
     * unknown one is, and a field left out takes its default, or is left to the column.
     *
     * @param  list<string>  $fields  The fields that may be sent
     * @param  array<string, mixed>  $input
     * @return array{values: array<string, mixed>, issues: list<array{path: string, message: string}>}
     */
    public function forFields(array $fields, array $input): array
    {
        $input = $this->prepared($input);
        $issues = [];
        foreach (array_keys($input) as $key) {
            if (! in_array($key, $fields, true)) {
                $issues[] = ['path' => (string) $key, 'message' => 'Unknown field.'];
            }
        }
        $validator = Validation::make($input, array_intersect_key(self::withoutTrashed($this->rules()), array_flip($fields)));
        if ($validator->fails()) {
            self::explainTrashed($validator, $input);
            array_push($issues, ...FlareErrors::issues($validator->errors()->toArray()));
        }
        foreach ($issues === [] ? $this->clashes($validator->validated()) + $this->unmet($validator->validated()) : [] as $field => $message) {
            $issues[] = ['path' => $field, 'message' => $message];
        }
        if ($issues !== []) {
            return ['values' => [], 'issues' => $issues];
        }

        $validated = $validator->validated();
        $defaults = $this->defaults();
        $values = [];
        foreach ($this->columns() as $field => $column) {
            if (array_key_exists($field, $validated)) {
                $values[$column] = $validated[$field];
            } elseif (array_key_exists($field, $defaults)) {
                $values[$column] = $defaults[$field];
            }
        }

        return ['values' => $this->make($this->emptied($values, $validated), true), 'issues' => []];
    }

    /**
     * Rich text is cleaned before anything looks at it: what is checked for being there,
     * and for being short enough, is the HTML that would be stored. A phone number is put
     * in the one form it is kept in, so 0772 123456 and +256772123456 are the same number
     * to the unique check too.
     */
    protected function prepareForValidation(): void
    {
        $fields = array_flip([...$this->rich(), ...array_keys($this->phones())]);
        $this->merge(array_intersect_key($this->prepared($this->all()), $fields));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> The input with its rich text cleaned (one with nothing left to read is empty) and its numbers in their one form. A number that isn't one is left as sent, for the rule to say so.
     */
    private function prepared(array $input): array
    {
        foreach ($this->rich() as $field) {
            if (is_string($input[$field] ?? null)) {
                $html = Html::clean($input[$field]);
                $input[$field] = $html === '' ? null : $html;
            }
        }
        foreach ($this->phones() as $field => $country) {
            if (is_string($input[$field] ?? null) && ($number = Phone::normalise($input[$field], $country)) !== null) {
                $input[$field] = $number;
            }
        }

        return $input;
    }

    /**
     * A record someone may not see is not there for them: 404, as for one that never was,
     * rather than "not allowed", which would say it exists.
     */
    protected function failedAuthorization(): void
    {
        $record = $this->record();
        if ($record !== null && ! Visibility::allows($this->user(), $record)) {
            throw new NotFoundHttpException;
        }
        parent::failedAuthorization();
    }

    protected function validationRules()
    {
        $rules = self::visibleOnly(self::withoutTrashed(parent::validationRules()));
        if (! $this->isMethod('PATCH')) {
            return $rules;
        }

        return array_map(fn ($rule) => ['sometimes', ...(is_array($rule) ? $rule : explode('|', $rule))], $rules);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $known = array_keys($this->columns());
            if ($this->lineItems() !== null) {
                $known[] = 'items';
                $this->checkItems($validator);
            }
            // A file field is sent back with its details beside it (imageFile for image, galleryFiles for a gallery);
            // a client that echoes a record shouldn't be refused for it.
            $computed = [...array_map(fn (string $field) => $field.'File', $known), ...array_map(fn (string $field) => $field.'Files', $known)];
            foreach (array_keys($this->json()->all() ?: $this->request->all()) as $key) {
                if (! in_array($key, $known, true) && ! in_array($key, self::READ_ONLY, true) && ! in_array($key, $computed, true)) {
                    $validator->errors()->add((string) $key, 'Unknown field.');
                }
            }
            self::explainTrashed($validator, $validator->getData());
            // A field already refused for itself isn't also said to clash.
            foreach ($this->clashes($validator->getData(), $this->record(), array_keys($validator->errors()->messages())) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
            foreach ($this->unmet($validator->getData(), $this->beforeChange(), array_keys($validator->errors()->messages())) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });
    }

    /**
     * The lines sent with the record, each checked by the rules for creating one of them,
     * less the field that points back here: that is filled in when they are saved.
     *
     * A line sent with an id changes that line, which has to be one of this record's. The
     * list is the record's lines whole: saveItems() removes the ones left out of it.
     */
    private function checkItems(Validator $validator): void
    {
        $lines = $this->lineItems();
        $data = $this->json()->all() ?: $this->request->all();
        if ($lines === null || ! array_key_exists('items', $data)) {
            return; // Not sent: the lines stay as they are.
        }
        $rows = $data['items'];
        if (! is_array($rows) || ! array_is_list($rows)) {
            $validator->errors()->add('items', 'The lines are sent as a list.');

            return;
        }
        $most = max(1, (int) config('nevela.bulk_max', 500));
        if (count($rows) > $most) {
            $validator->errors()->add('items', 'That is '.number_format(count($rows))." lines. At most {$most} can be saved at once.");

            return;
        }

        // The lines the record has now. A new record has none, so no line may name one.
        $parent = collect($this->route()?->parameters() ?? [])->first(fn ($value) => $value instanceof Model);
        $model = $lines['model'];
        $existing = $parent instanceof Model
            ? $model::query()->where($lines['column'], $parent->getKey())->pluck((new $model)->getKeyName())->map(fn ($id) => (string) $id)->all()
            : [];

        $wrong = false;
        $ids = [];
        foreach ($rows as $index => $row) {
            $id = is_array($row) && isset($row['id']) && is_scalar($row['id']) ? (string) $row['id'] : null;
            if ($id !== null && ! in_array($id, $existing, true)) {
                $validator->errors()->add("items.{$index}.id", 'This is not one of its lines. Leave the id out to add a new one.');
                $wrong = true;
            }
            $ids[] = $id;
        }
        $checked = (new $lines['request'])->many($rows, $lines['field']);
        foreach ($checked['problems'] as $problem) {
            foreach ($problem['issues'] as $issue) {
                $validator->errors()->add('items.'.($problem['row'] - 1).($issue['path'] === '' ? '' : '.'.$issue['path']), $issue['message']);
            }
        }
        if (! $wrong && $checked['problems'] === []) {
            $this->checkedItems = ['values' => $checked['values'], 'ids' => $ids];
        }
    }

    /**
     * Save the lines that were sent with the record, once the record itself is saved: in
     * the same transaction, which the generated controller opens. Lines sent with an id
     * are changed, the others added, and the record's lines left out of the list removed
     * for good. They were part of the record, not records anyone will look for in the trash.
     *
     * Then, if the record's model has an `itemsSaved()` method, it is called: the place to
     * work out a total from the lines and store it.
     */
    public function saveItems(Model $parent): void
    {
        $lines = $this->lineItems();
        if ($lines === null || $this->checkedItems === null) {
            return;
        }
        ['model' => $model, 'column' => $column] = $lines;
        $owned = fn () => $model::query()->where($column, $parent->getKey());

        $kept = [];
        $changed = false;
        foreach ($this->checkedItems['values'] as $index => $values) {
            $values[$column] = $parent->getKey();
            $id = $this->checkedItems['ids'][$index] ?? null;
            $line = $id === null ? null : $owned()->whereKey($id)->first();
            if ($line === null) {
                $line = $model::create($values);
                $changed = true;
            } else {
                $line->update($values);
                $changed = $changed || $line->wasChanged();
            }
            $kept[] = $line->getKey();
        }
        foreach ($owned()->whereKeyNot($kept)->get() as $line) {
            method_exists($line, 'forceDelete') ? $line->forceDelete() : $line->delete();
            $changed = true;
        }

        // A change to its lines is a change to the record, and is shown as one.
        if ($changed && ! $parent->wasRecentlyCreated) {
            $parent->touch();
        }
        if (method_exists($parent, 'itemsSaved')) {
            $parent->itemsSaved();
        }
    }

    /**
     * Whether a table has a trash, for validation. SchemaFacts keeps a yes; a no is kept
     * here, for this request only, because an import checks every one of its rows against
     * the same tables and would otherwise ask the database again for each.
     */
    private static function hasTrash(string $table): bool
    {
        $without = request()->attributes->get('nevela.without-trash', []);
        if (isset($without[$table])) {
            return false;
        }
        if (SchemaFacts::hasColumn($table, 'deleted_at')) {
            return true;
        }
        request()->attributes->set('nevela.without-trash', [...$without, $table => true]);

        return false;
    }

    /**
     * A relation points at a record that is there, and one in the trash is not: nothing
     * lists it and its own page answers 404. Laravel's exists rule looks at the table,
     * where a deleted record still is, so it is told to leave those out.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private static function withoutTrashed(array $rules): array
    {
        foreach ($rules as $set) {
            foreach (is_array($set) ? $set : [] as $rule) {
                if (! $rule instanceof Exists) {
                    continue;
                }
                // "exists:categories,id": the rule keeps its table to itself.
                $table = (string) strtok(substr((string) $rule, strlen('exists:')), ',');
                if (self::hasTrash($table)) {
                    $rule->withoutTrashed();
                }
            }
        }

        return $rules;
    }

    /**
     * A relation points at a record the person saving may see. Where the model it points
     * at has a visibleTo scope, the exists rule takes only those: a teacher can't move an
     * enrolment into another teacher's course by sending its id. Refused with the rule's
     * own message, the one for an id that doesn't exist, which gives nothing away.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private static function visibleOnly(array $rules): array
    {
        $user = request()->user();
        if ($user === null) {
            return $rules;
        }
        $models = [];
        foreach (Nevela::all() as $descriptor) {
            $models[$descriptor->table] = "App\\Models\\{$descriptor->name}";
        }
        foreach ($rules as $set) {
            foreach (is_array($set) ? $set : [] as $rule) {
                if (! $rule instanceof Exists) {
                    continue;
                }
                $model = $models[(string) strtok(substr((string) $rule, strlen('exists:')), ',')] ?? null;
                if ($model === null || ! class_exists($model) || ! Visibility::narrows($model)) {
                    continue;
                }
                $instance = new $model;
                $rule->where(fn ($query) => $query->whereIn($instance->getKeyName(), Visibility::narrow($model::query(), $user)->select($instance->getQualifiedKeyName())->toBase()));
            }
        }

        return $rules;
    }

    /**
     * A unique value can be held by a record nobody can see: one in the trash. "Already
     * taken" would send someone looking through a list it isn't in, so say where it is.
     * The same goes for a relation to a record that is in the trash.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{path: string, message: string}> The messages that were replaced
     */
    private static function explainTrashed(Validator $validator, array $data): array
    {
        $replaced = [];
        foreach ($validator->failed() as $field => $rules) {
            // What Laravel gives for a failed unique rule: the table and the column it looked in.
            [$table, $column] = array_pad($rules['Unique'] ?? [], 2, null);
            $value = $data[$field] ?? null;
            if (! is_string($table) || ! is_string($column) || ! is_scalar($value) || $column === 'NULL') {
                continue;
            }
            if (! self::hasTrash($table) || ! DB::table($table)->where($column, $value)->whereNotNull('deleted_at')->exists()) {
                continue;
            }
            $message = 'This belongs to a record in the trash. Restore that record, or delete it for good to use the value again.';
            $validator->errors()->forget($field);
            $validator->errors()->add($field, $message);
            $replaced[] = ['path' => (string) $field, 'message' => $message];
        }
        foreach ($validator->failed() as $field => $rules) {
            // And for a failed exists rule: the table and the column, then what else it asked.
            [$table, $column] = array_pad($rules['Exists'] ?? [], 2, null);
            $value = $data[$field] ?? null;
            if (! is_string($table) || ! is_string($column) || ! is_scalar($value) || ! self::hasTrash($table)) {
                continue;
            }
            try {
                $trashed = DB::table($table)->where($column, $value)->whereNotNull('deleted_at')->exists();
            } catch (\Throwable) {
                continue; // not something that could be an id at all
            }
            if ($trashed) {
                $message = 'This one is in the trash. Restore it, or choose another.';
                $validator->errors()->forget($field);
                $validator->errors()->add($field, $message);
                $replaced[] = ['path' => (string) $field, 'message' => $message];
            }
        }

        return $replaced;
    }

    /**
     * Many rows, each checked by the rules for creating one.
     *
     * The same rules as a single create, on purpose: a second way into the table with its
     * own idea of what is valid would let through a row the form then refuses to save.
     * Rows are numbered from 1, as a person counts them.
     *
     * Two rows of one batch can't share a unique value either. The database would refuse
     * the second, but only after the first was written and with no row number to show.
     *
     * @param  list<mixed>  $rows
     * @param  string|null  $owner  For lines: the field that points at the record they are lines of. It is filled in when they are saved, so it is neither asked for nor taken.
     * @return array{values: list<array<string, mixed>>, problems: list<array{row: int, issues: list<array{path: string, message: string}>}>}
     */
    public function many(array $rows, ?string $owner = null): array
    {
        $rules = self::visibleOnly(self::withoutTrashed($this->rules()));
        $columns = $this->columns();
        $defaults = $this->defaults();
        $known = array_keys($columns);
        if ($owner !== null) {
            unset($rules[$owner], $columns[$owner], $defaults[$owner]);
        }
        $computed = [...array_map(fn (string $field) => $field.'File', $known), ...array_map(fn (string $field) => $field.'Files', $known)];
        $unique = array_keys(array_filter($rules, fn ($rule) => is_array($rule) && array_filter($rule, fn ($one) => $one instanceof Unique) !== []));

        $values = [];
        $problems = [];
        $taken = [];
        $made = [];
        foreach (array_values($rows) as $index => $row) {
            $number = $index + 1;
            if (! is_array($row) || array_is_list($row)) {
                // An empty JSON object arrives as an empty list; anything else here is not fields at all.
                $problems[] = ['row' => $number, 'issues' => [['path' => '', 'message' => $row === [] ? 'This row is empty.' : 'This row is not a record.']]];

                continue;
            }
            $issues = [];
            foreach (array_keys($row) as $key) {
                if (! in_array($key, $known, true) && ! in_array($key, self::READ_ONLY, true) && ! in_array($key, $computed, true)) {
                    $issues[] = ['path' => (string) $key, 'message' => 'Unknown field.'];
                }
            }
            $row = $this->prepared($row);
            $validator = Validation::make($row, $rules);
            if ($validator->fails()) {
                self::explainTrashed($validator, $row);
                array_push($issues, ...FlareErrors::issues($validator->errors()->toArray()));
            }
            foreach ($unique as $field) {
                $value = $row[$field] ?? null;
                if (! is_scalar($value) || (string) $value === '') {
                    continue;
                }
                $mark = $field."\0".$value;
                if (isset($taken[$mark])) {
                    $issues[] = ['path' => $field, 'message' => "The same as row {$taken[$mark]}. Each one needs its own."];
                } else {
                    $taken[$mark] = $number;
                }
            }
            // The same for fields that go together: one row of the batch has them first.
            foreach ($this->together() as $set) {
                $together = array_map(fn (string $field) => $row[$field] ?? null, $set['fields']);
                if (in_array($owner, $set['fields'], true) || array_filter($together, fn ($value) => ! is_scalar($value) || (string) $value === '') !== []) {
                    continue;
                }
                $mark = implode("\0", [...$set['fields'], ...array_map('strval', $together)]);
                if (isset($taken[$mark])) {
                    $issues[] = ['path' => $set['fields'][0], 'message' => "The same as row {$taken[$mark]}. Each one needs its own."];
                } else {
                    $taken[$mark] = $number;
                }
            }
            if ($issues === []) {
                foreach ($this->clashes($validator->validated(), null, $owner === null ? [] : [$owner]) + $this->unmet($validator->validated()) as $field => $message) {
                    $issues[] = ['path' => $field, 'message' => $message];
                }
            }
            if ($issues !== []) {
                $problems[] = ['row' => $number, 'issues' => $issues];

                continue;
            }

            $validated = $validator->validated();
            $mapped = [];
            foreach ($columns as $field => $column) {
                if (array_key_exists($field, $validated)) {
                    $mapped[$column] = $validated[$field];
                } elseif (array_key_exists($field, $defaults)) {
                    $mapped[$column] = $defaults[$field];
                }
            }
            // Two rows with one title get two slugs: what was made for an earlier row is remembered.
            $values[] = $this->make($this->emptied($mapped, $validated), true, $made);
        }

        return ['values' => $values, 'problems' => $problems];
    }

    /** Validated input keyed by column, ready for create()/update(). @return array<string, mixed> */
    public function values(): array
    {
        $validated = $this->validated();
        $defaults = $this->defaults();
        $values = [];
        foreach ($this->columns() as $field => $column) {
            if (array_key_exists($field, $validated)) {
                $values[$column] = $validated[$field];
            } elseif (array_key_exists($field, $defaults) && ! $this->isMethod('PATCH')) {
                // Left out of a create, or of a replace, it is what it usually is.
                $values[$column] = $defaults[$field];
            } elseif ($this->isMethod('PUT')) {
                $values[$column] = null;
            }
        }

        return $this->make($this->emptied($values, $validated, $this->beforeChange()), $this->isMethod('POST'));
    }
}
