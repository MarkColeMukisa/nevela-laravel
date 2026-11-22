<?php

namespace Nevela\Laravel\Support;

/**
 * The API as an OpenAPI 3.1 document, written from the descriptors (GET _nevela/openapi.json).
 *
 * A descriptor says outright what a tool reading controllers would have to guess: each
 * field's kind, whether it is required, its choices, what may be filtered and sorted, who
 * may do what, and the actions. So the document is made from them, by the same rules the
 * generated code follows, and a test checks it against the app's real routes and answers.
 *
 * Only what the descriptors make is described: every resource's routes, its uploads, its
 * actions and its public side, and enough of signing in to get a token. Pure: no router,
 * no database, so it can be tested with any descriptors.
 */
final class OpenApi
{
    public const VERSION = '3.1.0';

    /** @var array<string, Descriptor> by name */
    private array $byName = [];

    /** @var array<string, mixed> */
    private array $schemas = [];

    /** @var array<string, mixed> */
    private array $paths = [];

    /**
     * @param  list<Descriptor>  $descriptors
     * @param  string  $server  Where the API is: the app's address and Nevela's prefix, "https://api.example.com/api"
     */
    public function __construct(
        private readonly array $descriptors,
        private readonly string $server,
        private readonly string $title = 'API',
        private readonly string $version = '1.0.0',
        private readonly int $perPage = 25,
        private readonly int $maxPerPage = 100,
        private readonly int $bulkMax = 500,
        private readonly ?string $currency = null,
        private readonly bool $auth = true,
    ) {
        foreach ($descriptors as $d) {
            $this->byName[$d->name] = $d;
        }
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        $this->schemas = self::shared();
        $this->paths = [];
        if ($this->auth) {
            $this->signIn();
        }
        $all = $this->descriptors;
        usort($all, fn (Descriptor $a, Descriptor $b) => strcmp($a->slug, $b->slug));
        $tags = [];
        foreach ($all as $d) {
            $tags[] = array_filter(['name' => $d->pluralLabel, 'description' => $d->group === null ? null : "In {$d->group}."]);
            $this->resource($d);
            if ($d->public !== null) {
                $this->open($d);
            }
        }
        ksort($this->schemas);
        $this->uniqueOperationIds();

        return [
            'openapi' => self::VERSION,
            'info' => [
                'title' => $this->title,
                'version' => $this->version,
                'description' => 'Written from the app\'s resource descriptors by Nevela. Lists answer { data, meta }; errors answer { error }, with "issues" when there is more to say.',
            ],
            'servers' => [['url' => rtrim($this->server, '/')]],
            'security' => [['bearer' => []]],
            'tags' => $this->auth ? [['name' => 'Signing in'], ...$tags] : $tags,
            'paths' => $this->paths,
            'components' => [
                'securitySchemes' => [
                    'bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'The token POST /auth/token answers, as "Authorization: Bearer <token>".'],
                ],
                'schemas' => $this->schemas,
                'responses' => self::responses(),
            ],
        ];
    }

    // ------------------------------------------------------------------ a resource, signed in

    private function resource(Descriptor $d): void
    {
        $tag = [$d->pluralLabel];
        $name = $d->name;
        $lower = strtolower($d->label);
        [$a, $many] = [self::a($lower), mb_strtolower($d->pluralLabel)];
        $this->schemas[$name] = $this->record($d);
        $this->schemas["{$name}Input"] = $this->input($d, 'create');
        $this->schemas["{$name}Replace"] = $this->input($d, 'replace');
        $this->schemas["{$name}Patch"] = $this->input($d, 'patch');
        $id = ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => $this->idSchema($d), 'description' => "The {$lower}'s id."];
        $permission = fn (string $what) => ['x-nevela-permission' => "{$d->table}.{$what}"];
        $fixed = $d->fixed ? ' Not for a record that is fixed: once made, it changes only through its actions (403).' : '';

        $this->paths["/{$d->slug}"] = [
            'get' => [
                'tags' => $tag,
                'operationId' => "list{$name}",
                'summary' => "List {$many}",
                'description' => 'A page of the records the signed-in person may see.'.($d->owner !== null ? " Someone who may not edit {$d->pluralLabel} sees only their own, by \"{$d->owner}\"." : ''),
                'parameters' => $this->listParameters($d),
                'responses' => [
                    '200' => self::json('A page of them.', [
                        'type' => 'object',
                        'properties' => ['data' => ['type' => 'array', 'items' => self::ref($name)], 'meta' => self::ref('NevelaListMeta')],
                        'required' => ['data', 'meta'],
                        'additionalProperties' => false,
                    ]),
                    '400' => self::use('QueryRefused'),
                    '401' => self::use('SignIn'),
                    '403' => self::use('Forbidden'),
                ],
            ] + $permission('view'),
            'post' => [
                'tags' => $tag,
                'operationId' => "create{$name}",
                'summary' => "Create {$a}",
                'requestBody' => self::body(self::ref("{$name}Input")),
                'responses' => [
                    '201' => self::json("The {$lower} as it was saved.", self::ref($name), ['Location' => ['description' => 'Its address.', 'schema' => ['type' => 'string']]]),
                    '401' => self::use('SignIn'),
                    '403' => self::use('Forbidden'),
                    '409' => self::use('Taken'),
                    '422' => self::use('Invalid'),
                ],
            ] + $permission('create'),
        ];

        $this->paths["/{$d->slug}/{id}"] = [
            'parameters' => [$id],
            'get' => [
                'tags' => $tag,
                'operationId' => "show{$name}",
                'summary' => "Show {$a}",
                'responses' => [
                    '200' => self::json("The {$lower}.".($d->items !== null ? ' With its lines, as "items".' : ''), self::ref($name)),
                    '401' => self::use('SignIn'),
                    '403' => self::use('Forbidden'),
                    '404' => self::use('NotFound'),
                ],
            ] + $permission('view'),
            'put' => [
                'tags' => $tag,
                'operationId' => "replace{$name}",
                'summary' => "Replace {$a}",
                'description' => 'Every field, as it should be: an optional field left out is emptied. Send the updatedAt it was read with to be told, with a 409, if someone saved it since.'.$fixed,
                'requestBody' => self::body(self::ref("{$name}Replace")),
                'responses' => $this->changeResponses($d),
            ] + $permission('edit'),
            'patch' => [
                'tags' => $tag,
                'operationId' => "update{$name}",
                'summary' => "Change some of {$a}'s fields",
                'description' => 'Only the fields sent are checked and changed.'.$fixed,
                'requestBody' => self::body(self::ref("{$name}Patch")),
                'responses' => $this->changeResponses($d),
            ] + $permission('edit'),
            'delete' => [
                'tags' => $tag,
                'operationId' => "delete{$name}",
                'summary' => "Delete {$a}",
                'description' => 'It goes to the trash, from where it can be restored.'.$fixed,
                'responses' => [
                    '204' => ['description' => 'Deleted.'],
                    '401' => self::use('SignIn'),
                    '403' => self::use('Forbidden'),
                    '404' => self::use('NotFound'),
                    '409' => self::json('Other records belong to it: move or delete them first.', self::ref('NevelaError')),
                ],
            ] + $permission('delete'),
        ];

        $choices = array_keys(array_filter($d->fields, fn (Field $f) => in_array($f->kind, ['enum', 'boolean'], true)));
        $this->paths["/{$d->slug}/_stats"] = ['get' => [
            'tags' => $tag,
            'operationId' => "stats{$name}",
            'summary' => "Count {$many}",
            'description' => 'How many there are, how many were created in the last "days" and in the days before those, and, with "field", how many have each value of it.',
            'parameters' => array_values(array_filter([
                ['name' => 'days', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'default' => 7]],
                $choices === [] ? null : ['name' => 'field', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => $choices]],
            ])),
            'responses' => [
                '200' => self::json('The counts.', self::ref('NevelaStats')),
                '400' => self::use('QueryRefused'),
                '401' => self::use('SignIn'),
                '403' => self::use('Forbidden'),
            ],
        ] + $permission('view')];

        $this->paths["/{$d->slug}/_insights"] = ['get' => [
            'tags' => $tag,
            'operationId' => "insights{$name}",
            'summary' => "Chart {$many}",
            'description' => 'How many were created in each day, week or month, and how they split across each choice field. Takes the list\'s search and filters.',
            'parameters' => [
                ['name' => 'unit', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => array_keys(Insights::PERIODS), 'default' => 'day']],
                ...array_values(array_filter($this->listParameters($d), fn (array $p) => ! in_array($p['name'], ['page', 'perPage', 'sort'], true))),
            ],
            'responses' => [
                '200' => self::json('The series and the breakdowns.', self::ref('NevelaInsights')),
                '400' => self::use('QueryRefused'),
                '401' => self::use('SignIn'),
                '403' => self::use('Forbidden'),
            ],
        ] + $permission('view')];

        $this->paths["/{$d->slug}/_bulk"] = ['post' => [
            'tags' => $tag,
            'operationId' => "createMany{$name}",
            'summary' => "Create several {$many}",
            'description' => "Every row is checked before anything is saved, and they are saved together: a mistake in one row creates nothing. At most {$this->bulkMax} rows.",
            'requestBody' => self::body([
                'type' => 'object',
                'properties' => ['items' => ['type' => 'array', 'items' => self::ref("{$name}Input"), 'minItems' => 1, 'maxItems' => $this->bulkMax]],
                'required' => ['items'],
            ]),
            'responses' => [
                '201' => self::json('What was created.', [
                    'type' => 'object',
                    'properties' => ['created' => ['type' => 'integer'], 'data' => ['type' => 'array', 'items' => self::ref($name)]],
                    'required' => ['created', 'data'],
                    'additionalProperties' => false,
                ]),
                '401' => self::use('SignIn'),
                '403' => self::use('Forbidden'),
                '422' => self::json('Nothing was created: the rows that need fixing, by number from 1.', self::ref('NevelaRowsRefused')),
            ],
        ] + $permission('create')];

        foreach ($d->fields as $field) {
            if ($field->kind === 'file') {
                $this->upload($d, $field, "/_nevela/uploads/{$d->name}/{$field->name}", false);
            }
        }
        foreach ($d->actions as $action) {
            $this->action($d, $action);
        }
    }

    /** @return array<string, mixed> */
    private function changeResponses(Descriptor $d): array
    {
        $lower = strtolower($d->label);

        return [
            '200' => self::json("The {$lower} as it was saved.", self::ref($d->name)),
            '401' => self::use('SignIn'),
            '403' => self::use('Forbidden'),
            '404' => self::use('NotFound'),
            '409' => self::json('Someone else saved it after the updatedAt that was sent ("code": "CHANGED_SINCE_READ", with the record as it is now as "current"), or a value another record has.', self::ref('NevelaConflict')),
            '422' => self::use('Invalid'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function listParameters(Descriptor $d, bool $timestamps = true): array
    {
        $sortable = ['createdAt', 'updatedAt', ...array_keys(array_filter($d->fields, fn (Field $f) => $f->sortable()))];
        $sorts = [];
        foreach ($sortable as $key) {
            $sorts[] = $key;
            $sorts[] = "-{$key}";
        }
        $searched = array_keys(array_filter($d->fields, fn (Field $f) => $f->searchable()));
        $parameters = [
            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
            ['name' => 'perPage', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => $this->maxPerPage, 'default' => $this->perPage]],
            ['name' => 'sort', 'in' => 'query', 'description' => 'A field, newest or largest last; with "-" before it, first.', 'schema' => ['type' => 'string', 'enum' => $sorts, 'default' => '-createdAt']],
        ];
        if ($searched !== []) {
            $parameters[] = ['name' => 'q', 'in' => 'query', 'description' => 'Words to find in '.implode(', ', $searched).'.', 'schema' => ['type' => 'string', 'maxLength' => 200]];
        }
        foreach ($d->fields as $key => $field) {
            if ($d->publish === $key) {
                $parameters[] = ['name' => "filter[{$key}]", 'in' => 'query', 'description' => 'Whether it is published, a draft, or scheduled.', 'schema' => ['type' => 'string', 'enum' => ListQuery::PUBLISHED]];

                continue;
            }
            if (! $field->filterable()) {
                continue;
            }
            $schema = match ($field->kind) {
                'boolean' => ['type' => 'boolean'],
                'int' => ['type' => 'integer'],
                'float' => ['type' => 'number'],
                'enum' => ['type' => 'string', 'enum' => $field->options],
                default => ['type' => 'string'],
            };
            $parameters[] = array_filter([
                'name' => "filter[{$key}]",
                'in' => 'query',
                'description' => 'Only those where it is this.'
                    .($field->alwaysRequired() ? '' : ' "null" for those where it is empty.')
                    .(in_array($field->kind, ListQuery::COMMA_LISTS, true) ? ' Several, separated by commas, for those where it is any of them.' : ''),
                'schema' => $schema,
            ]);
            array_push($parameters, ...self::operatorParameters($key, $field->kind, $schema));
        }
        foreach ($timestamps ? ListQuery::TIMESTAMPS : [] as $key => $column) {
            array_push($parameters, ...self::operatorParameters($key, 'datetime', ['type' => 'string']));
        }
        if ($d->public?->confirm !== null) {
            $parameters[] = ['name' => 'filter[confirmed]', 'in' => 'query', 'description' => 'Whether the address has been proved.', 'schema' => ['type' => 'boolean']];
        }
        if ($d->items !== null) {
            $parameters[] = ['name' => 'include', 'in' => 'query', 'description' => 'Each record with its lines, as "items".', 'schema' => ['type' => 'string', 'enum' => ['items']]];
        }

        return $parameters;
    }

    /**
     * filter[field][op] for each operator the field's kind takes.
     *
     * @param  array<string, mixed>  $schema  What one of its values is
     * @return list<array<string, mixed>>
     */
    private static function operatorParameters(string $key, string $kind, array $schema): array
    {
        $dates = in_array($kind, ['date', 'datetime'], true);
        $one = $dates ? ['type' => 'string', 'description' => $kind === 'date' ? 'YYYY-MM-DD' : 'YYYY-MM-DD for the whole day, or an ISO 8601 time'] : $schema;
        $parameters = [];
        foreach (ListQuery::OPERATORS[$kind] ?? [] as $op) {
            $parameters[] = [
                'name' => "filter[{$key}][{$op}]",
                'in' => 'query',
                'description' => match ($op) {
                    'in' => 'Only those where it is any of these, separated by commas (at most '.ListQuery::MAX_IN.').',
                    'contains' => 'Only those where it contains these words, in any case.',
                    'gt' => $dates ? 'Only those after this.' : 'Only those larger than this.',
                    'lt' => $dates ? 'Only those before this.' : 'Only those smaller than this.',
                    default => 'Only those from the first to the second, both included, separated by a comma.',
                },
                'schema' => match ($op) {
                    'gt', 'lt' => array_diff_key($one, ['description' => true]),
                    'contains' => ['type' => 'string', 'maxLength' => 200],
                    default => ['type' => 'string'],
                },
            ];
        }

        return $parameters;
    }

    // ------------------------------------------------------------------ actions and uploads

    private function action(Descriptor $d, Action $action): void
    {
        $name = $d->name;
        $input = "{$name}".ucfirst($action->name).'Input';
        $this->schemas[$input] = $this->actionInput($action);
        $when = $action->when === [] ? '' : ' Only for a record where '.implode(' and ', array_map(
            fn (string $field, mixed $value) => "{$field} is ".(is_array($value) ? implode(' or ', array_map(fn ($v) => json_encode($v), $value)) : json_encode($value)),
            array_keys($action->when),
            $action->when,
        )).'; otherwise 409, "code": "NOT_APPLICABLE".';
        $common = [
            'tags' => [$d->pluralLabel],
            'x-nevela-permission' => "{$d->table}.{$action->key()}",
        ] + ($action->confirm !== null ? ['x-nevela-confirm' => $action->confirm] : []);
        $failed = self::json("{$action->label} didn't work, and nothing was changed.", self::ref('NevelaError'));

        if ($action->on === 'record') {
            $this->paths["/{$d->slug}/{id}/_actions/{$action->name}"] = ['post' => $common + [
                'operationId' => "run{$name}".ucfirst($action->name),
                'summary' => $action->label,
                'description' => trim(($action->confirm !== null ? "The dashboard asks first: \"{$action->confirm}\"" : '').$when),
                'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => $this->idSchema($d)]],
                'requestBody' => self::body(['type' => 'object', 'properties' => ['input' => self::ref($input)]], $action->fields === []),
                'responses' => [
                    '200' => self::json('Done: what to tell the person, and the record as it is now.', [
                        'type' => 'object',
                        'properties' => ['message' => ['type' => 'string'], 'record' => ['oneOf' => [self::ref($name), ['type' => 'null']]]],
                        'required' => ['message', 'record'],
                        'additionalProperties' => false,
                    ]),
                    '401' => self::use('SignIn'),
                    '403' => self::use('Forbidden'),
                    '404' => self::use('NotFound'),
                    '409' => self::json("It can't be done to this record as it is now.", self::ref('NevelaError')),
                    '422' => self::use('Invalid'),
                    '500' => $failed,
                ],
            ]];
        }
        if ($action->on === 'collection' || $action->many) {
            $many = $action->on === 'record';
            $this->paths["/{$d->slug}/_actions/{$action->name}"] = ['post' => $common + [
                'operationId' => "run{$name}".ucfirst($action->name).($many ? 'Many' : ''),
                'summary' => $many ? "{$action->label}, on several" : $action->label,
                'description' => $many ? 'On each record "ids" names. One it no longer applies to, or that has gone, is passed over and counted as skipped.' : ($action->confirm !== null ? "The dashboard asks first: \"{$action->confirm}\"" : ''),
                'requestBody' => self::body([
                    'type' => 'object',
                    'properties' => array_filter([
                        'ids' => $many ? ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 500] : null,
                        'input' => self::ref($input),
                    ]),
                    'required' => $many ? ['ids'] : [],
                ], ! $many && $action->fields === []),
                'responses' => [
                    '200' => self::json('Done.', [
                        'type' => 'object',
                        'properties' => array_filter([
                            'message' => ['type' => 'string'],
                            'done' => $many ? ['type' => 'integer'] : null,
                            'skipped' => $many ? ['type' => 'integer'] : null,
                        ]),
                        'required' => $many ? ['message', 'done', 'skipped'] : ['message'],
                        'additionalProperties' => false,
                    ]),
                    '401' => self::use('SignIn'),
                    '403' => self::use('Forbidden'),
                    '422' => self::use('Invalid'),
                    '500' => $failed,
                ],
            ]];
        }
    }

    /** @return array<string, mixed> */
    private function actionInput(Action $action): array
    {
        $properties = [];
        $required = [];
        foreach ($action->fields as $key => $field) {
            $properties[$key] = $this->value($field, ! $field->required, 'input');
            if ($field->required) {
                $required[] = $key;
            }
        }

        return array_filter(['type' => 'object', 'properties' => (object) $properties, 'required' => $required ?: null], fn ($v) => $v !== null);
    }

    private function upload(Descriptor $d, Field $field, string $path, bool $open): void
    {
        $accepts = implode(', ', $field->accept ?: ['any']);
        $what = $field->isImage() ? 'An image, optimised as it arrives' : "A file ({$accepts})";
        $answer = self::json('Stored. Put its "key" in the record\'s "'.$field->name.'".', self::ref('NevelaFile'));
        $tag = [$d->pluralLabel];
        $responses = [
            '201' => $answer,
            '403' => self::use('Forbidden'),
            '413' => self::json('Larger than the field takes, or than PHP takes from a form.', self::ref('NevelaError')),
            '422' => self::json('Not a kind of file the field takes.', self::ref('NevelaError')),
        ] + ($open ? ['429' => self::use('TooMany')] : ['401' => self::use('SignIn')]);
        $name = ['name' => 'name', 'in' => 'query', 'description' => 'The file\'s name, as it is shown. A form\'s part says it otherwise.', 'schema' => ['type' => 'string']];
        $security = $open ? ['security' => []] : ['x-nevela-permission' => "{$d->table}.create"];
        $suffix = $open ? 'Public' : '';

        $this->paths[$path] = [
            'put' => [
                'tags' => $tag,
                'operationId' => "upload{$suffix}{$d->name}".ucfirst($field->name),
                'summary' => 'Upload the '.mb_strtolower($field->label()),
                'description' => "{$what}, as the request's body.",
                'parameters' => [$name],
                'requestBody' => ['required' => true, 'content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'contentEncoding' => 'binary']]]],
                'responses' => $responses,
            ] + $security,
            'post' => [
                'tags' => $tag,
                'operationId' => "upload{$suffix}{$d->name}".ucfirst($field->name).'Form',
                'summary' => 'Upload the '.mb_strtolower($field->label()).', as a form',
                'description' => "{$what}, as a form's \"file\" part, with \"_method\" PUT: PHP reads a form only from a POST. A phone's way of sending one. PHP's own limits (upload_max_filesize, post_max_size) apply first.",
                'parameters' => [$name],
                'requestBody' => ['required' => true, 'content' => ['multipart/form-data' => ['schema' => [
                    'type' => 'object',
                    'properties' => ['_method' => ['type' => 'string', 'enum' => ['PUT']], 'file' => ['type' => 'string', 'contentEncoding' => 'binary']],
                    'required' => ['_method', 'file'],
                ]]]],
                'responses' => $responses,
            ] + $security,
        ];
    }

    // ------------------------------------------------------------------ the public side

    private function open(Descriptor $d): void
    {
        $public = $d->public;
        $tag = [$d->pluralLabel];
        $lower = strtolower($d->label);
        [$a, $many] = [self::a($lower), mb_strtolower($d->pluralLabel)];
        $throttle = fn (string $limit) => ' At most '.str_replace(',', ' a ', $limit).' minute from one address, then 429.';
        if ($public->read !== null) {
            $this->schemas["Public{$d->name}"] = $this->publicRecord($d);
            $seen = $d->only($public->read);
            $by = $public->by === null
                ? ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => $this->idSchema($d)]
                : ['name' => 'id', 'in' => 'path', 'required' => true, 'description' => "Its {$public->by}.", 'schema' => ['type' => 'string']];
            $this->paths["/public/{$d->slug}"]['get'] = [
                'tags' => $tag,
                'operationId' => "listPublic{$d->name}",
                'summary' => "List {$many}, signed out",
                'description' => 'Only the fields anyone may read, which are also all that can be sorted, filtered and searched by.'.$throttle($public->readThrottle),
                'security' => [],
                'parameters' => $this->listParameters($seen, false),
                'responses' => [
                    '200' => self::json('A page of them.', [
                        'type' => 'object',
                        'properties' => ['data' => ['type' => 'array', 'items' => self::ref("Public{$d->name}")], 'meta' => self::ref('NevelaListMeta')],
                        'required' => ['data', 'meta'],
                        'additionalProperties' => false,
                    ]),
                    '400' => self::use('QueryRefused'),
                    '429' => self::use('TooMany'),
                ],
            ];
            $this->paths["/public/{$d->slug}/{id}"] = ['get' => [
                'tags' => $tag,
                'operationId' => "showPublic{$d->name}",
                'summary' => "Show {$a}, signed out",
                'description' => trim($throttle($public->readThrottle)),
                'security' => [],
                'parameters' => [$by],
                'responses' => [
                    '200' => self::json("The {$lower}.", self::ref("Public{$d->name}")),
                    '404' => self::use('NotFound'),
                    '429' => self::use('TooMany'),
                ],
            ]];
        }
        if ($public->create !== null) {
            $this->schemas["Public{$d->name}Input"] = $this->publicInput($d);
            $quiet = $public->read === null || $public->protected() || $public->confirm !== null;
            $this->paths["/public/{$d->slug}"]['post'] = [
                'tags' => $tag,
                'operationId' => "createPublic{$d->name}",
                'summary' => "Send {$a}, signed out",
                'description' => 'A public form.'.($public->protected() ? ' It is protected: GET /public/_nevela/form/'.$d->slug.' first, for what to send with it. A sending it turns away is answered as if it had worked.' : '').($public->confirm !== null ? " The address in \"{$public->confirm}\" is sent a link to prove it." : '').$throttle($public->createThrottle),
                'security' => [],
                'requestBody' => self::body(self::ref("Public{$d->name}Input")),
                'responses' => array_filter([
                    '201' => $quiet ? ['description' => 'Received. Nothing is said back.'] : self::json('Received: what anyone may read of it.', self::ref("Public{$d->name}")),
                    '422' => self::use('Invalid'),
                    '429' => self::use('TooMany'),
                ]),
            ];
            foreach ($public->create as $field) {
                if ($d->fields[$field]->kind === 'file') {
                    $this->upload($d, $d->fields[$field], "/public/_nevela/uploads/{$d->name}/{$field}", true);
                    $this->upload($d, $d->fields[$field], "/public/{$d->slug}/_uploads/{$field}", true);
                    // Two addresses, one upload: the second is the form's own.
                    foreach (['put', 'post'] as $method) {
                        $this->paths["/public/{$d->slug}/_uploads/{$field}"][$method]['operationId'] .= 'Here';
                    }
                }
            }
            if ($public->protected()) {
                $this->paths["/public/_nevela/form/{$d->slug}"] = ['get' => [
                    'tags' => $tag,
                    'operationId' => "formPublic{$d->name}",
                    'summary' => "What the {$lower} form needs, signed out",
                    'description' => 'A token saying when the form was handed out, the field a person never sees, and whether the robot check is on. Ask again after each attempt.',
                    'security' => [],
                    'responses' => [
                        '200' => self::json('What to send with the form.', ['type' => 'object']),
                        '429' => self::use('TooMany'),
                    ],
                ]];
            }
            if ($public->confirm !== null) {
                $this->paths["/public/_nevela/confirm/{$d->slug}/{id}/{key}"] = ['post' => [
                    'tags' => $tag,
                    'operationId' => "confirmPublic{$d->name}",
                    'summary' => 'Prove an address',
                    'description' => 'What the link in the email does.',
                    'security' => [],
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'key', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => self::json('Proved.', ['type' => 'object', 'properties' => ['confirmed' => ['type' => 'boolean']], 'required' => ['confirmed']]),
                        '404' => self::json('The link is no longer good.', self::ref('NevelaError')),
                        '429' => self::use('TooMany'),
                    ],
                ]];
            }
        }
    }

    // ------------------------------------------------------------------ schemas of records

    /** A record as the API answers with it: every key always there, nothing else. */
    private function record(Descriptor $d): array
    {
        $properties = ['id' => $this->idSchema($d)];
        foreach ($d->fields as $key => $field) {
            $properties[$key] = $this->value($field, ! $field->alwaysRequired(), 'output');
            if ($field->kind === 'file') {
                $file = self::ref($field->private ? 'NevelaPrivateFile' : 'NevelaFile');
                $field->multiple
                    ? $properties["{$key}Files"] = ['type' => 'array', 'items' => ['oneOf' => [$file, ['type' => 'null']]], 'description' => 'Each file\'s details, in the order of the keys.']
                    : $properties["{$key}File"] = ['oneOf' => [$file, ['type' => 'null']], 'description' => 'The file\'s details: what a page needs to show it.'];
            }
        }
        $lines = $this->lines($d);
        if ($lines !== null) {
            $properties['items'] = ['type' => 'array', 'items' => self::ref($lines['resource']->name), 'description' => 'Its lines. On a record\'s own page, in what a save answers, and in a list asked for them with ?include=items.'];
        }
        if ($d->public?->confirm !== null) {
            $properties['confirmedAt'] = ['type' => ['string', 'null'], 'format' => 'date-time', 'description' => 'When the address was proved. Empty until it is.'];
        }
        $properties['createdAt'] = ['type' => ['string', 'null'], 'format' => 'date-time', 'readOnly' => true];
        $properties['updatedAt'] = ['type' => ['string', 'null'], 'format' => 'date-time', 'readOnly' => true, 'description' => 'Send it back with a change to be told if someone saved it since.'];

        return array_filter([
            'type' => 'object',
            'title' => $d->label,
            'properties' => $properties,
            'required' => array_values(array_diff(array_keys($properties), ['items'])),
            'additionalProperties' => false,
            'x-nevela-fixed' => $d->fixed ?: null,
        ], fn ($v) => $v !== null);
    }

    /** @return array<string, mixed> */
    private function publicRecord(Descriptor $d): array
    {
        $properties = ['id' => $this->idSchema($d)];
        foreach ($d->public->read as $key) {
            $field = $d->fields[$key];
            $properties[$key] = $this->value($field, ! $field->alwaysRequired(), 'output');
            if ($field->kind === 'file') {
                $properties["{$key}File"] = ['oneOf' => [self::ref('NevelaFile'), ['type' => 'null']]];
            }
        }

        return ['type' => 'object', 'title' => "{$d->label}, as anyone may read it", 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    /**
     * What a create, a replace or a patch takes. Required is what the generated request
     * requires: a field every record has, that nothing fills in when it is left out.
     *
     * @param  'create'|'replace'|'patch'  $how
     * @return array<string, mixed>
     */
    private function input(Descriptor $d, string $how, ?string $without = null): array
    {
        // A line sent with its id is that line, changed; one without is a new line.
        $properties = $without === null ? [] : ['id' => ['description' => 'The line\'s id, to change a line it has. Left out, the line is new.'] + $this->idSchema($d)];
        $required = [];
        foreach ($d->fields as $key => $field) {
            if ($key === $without) {
                continue; // a line's link to the record it is a line of, which saving it sets
            }
            $needed = self::required($d, $field);
            $properties[$key] = $this->value($field, ! $needed || $how === 'patch', 'input');
            if ($needed && $how !== 'patch') {
                $required[] = $key;
            }
        }
        $lines = $without === null ? $this->lines($d) : null;
        if ($lines !== null) {
            $lineSchema = "{$lines['resource']->name}Line";
            $this->schemas[$lineSchema] = $this->input($lines['resource'], 'create', $lines['field']);
            $properties['items'] = ['type' => 'array', 'items' => self::ref($lineSchema), 'description' => 'Its lines, all of them: sent with a change, they replace the ones it had.'];
        }
        if ($how !== 'create' && $without === null) {
            $properties['updatedAt'] = ['type' => 'string', 'format' => 'date-time', 'description' => 'The updatedAt the record was read with. If someone has saved it since, nothing is changed and the answer is a 409.'];
        }

        return array_filter([
            'type' => 'object',
            'properties' => $properties,
            'required' => $required ?: null,
            'description' => 'A field that isn\'t one of these is refused. id, createdAt, updatedAt and a file\'s details sent back as they came are let through.',
        ], fn ($v) => $v !== null);
    }

    /** @return array<string, mixed> */
    private function publicInput(Descriptor $d): array
    {
        $properties = [];
        $required = [];
        foreach ($d->public->create as $key) {
            $field = $d->fields[$key];
            $needed = self::required($d, $field);
            $properties[$key] = $this->value($field, ! $needed, 'input');
            if ($needed) {
                $required[] = $key;
            }
        }

        return array_filter(['type' => 'object', 'properties' => $properties, 'required' => $required ?: null], fn ($v) => $v !== null);
    }

    /** Whether a create has to send it: the rule ResourceGenerator writes "required" by. */
    public static function required(Descriptor $d, Field $field): bool
    {
        return $field->alwaysRequired() && $field->from === null && $field->auto === null && $d->owner !== $field->name && $field->whenLeftOut() === null;
    }

    /**
     * A field's value. Going out, as the generated JsonResource writes it; coming in, as the
     * generated request checks it.
     *
     * @param  'input'|'output'  $way
     * @return array<string, mixed>
     */
    private function value(Field $field, bool $nullable, string $way): array
    {
        $in = $way === 'input';
        $schema = match (true) {
            $field->kind === 'file' && $field->multiple => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => Field::MAX_FILES, 'description' => 'The keys uploads answered, in order.'],
            $field->kind === 'file' => ['type' => 'string', 'maxLength' => $in ? 512 : null, 'description' => 'The key an upload to this field answered.'],
            $field->kind === 'belongsTo' => $this->foreignKey($field, $in),
            $field->kind === 'json' => ['type' => ['object', 'array']],
            $field->kind === 'boolean' => ['type' => 'boolean'],
            $field->kind === 'date' => ['type' => 'string', 'format' => 'date'],
            $field->kind === 'datetime' => ['type' => 'string', 'format' => 'date-time'],
            $field->kind === 'enum' => ['type' => 'string', 'enum' => $field->options],
            $field->kind === 'int' => ['type' => 'integer'] + self::bounds($field),
            $field->kind === 'float' => ['type' => 'number'] + self::bounds($field),
            $field->kind === 'text' => ['type' => 'string', 'maxLength' => $in ? ($field->maxLength ?? 65535) : null, 'contentMediaType' => $field->isRich() ? 'text/html' : null],
            default => $this->text($field, $in),
        };
        $schema = array_filter($schema, fn ($v) => $v !== null);
        $schema['title'] = $field->label();
        if ($field->format === 'money') {
            $currency = $field->currency ?? $this->currency;
            $schema['description'] = 'An amount of money'.($currency !== null && $currency !== '' ? " in {$currency}" : '').'.';
        }
        if ($field->optionLabels !== []) {
            $schema['x-enum-descriptions'] = array_map(fn (string $option) => $field->optionLabel($option), $field->options);
        }
        if ($field->when !== []) {
            $on = array_key_first($field->when);
            $schema['description'] = trim(($schema['description'] ?? '').' Only when '.$on.' is '.implode(' or ', array_map(fn ($v) => json_encode($v), $field->when[$on])).'; empty otherwise.');
        }
        if ($in && $field->unique) {
            $schema['description'] = trim(($schema['description'] ?? '').' No two records have the same.');
        }
        if ($in && $field->whenLeftOut() !== null) {
            $schema['default'] = $field->whenLeftOut();
        }
        if ($nullable) {
            $schema = self::nullable($schema);
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private function text(Field $field, bool $in): array
    {
        $max = fn (int $usual) => $in ? ($field->maxLength ?? $usual) : null;

        return match ($field->format) {
            'email' => ['type' => 'string', 'format' => 'email', 'maxLength' => $max(255)],
            'url' => ['type' => 'string', 'format' => 'uri', 'maxLength' => $max(2048)],
            'tel' => ['type' => 'string', 'maxLength' => $max(32), 'description' => 'A phone number'.($field->country !== null ? " of {$field->country}" : '').', kept as +256772123456.'],
            'slug' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]+$', 'maxLength' => $max(255)],
            'color' => ['type' => 'string', 'pattern' => '^#[0-9a-fA-F]{6}$'],
            'domain' => ['type' => 'string', 'description' => 'A host name: example.com.'],
            'country' => ['type' => 'string', 'pattern' => '^[A-Za-z]{2}$', 'description' => 'An ISO 3166 country code: UG.'],
            'time' => ['type' => 'string', 'pattern' => '^([01][0-9]|2[0-3]):[0-5][0-9]$'],
            default => ['type' => 'string', 'maxLength' => $max(255)],
        };
    }

    /** @return array<string, mixed> */
    private function foreignKey(Field $field, bool $in): array
    {
        $target = $this->byName[(string) $field->target] ?? null;
        $integers = $target?->idType === 'int';
        $about = "The id of a {$field->target}.";

        // An integer id is a string in what the API answers; coming in, either is taken.
        return match (true) {
            $integers && $in => ['type' => ['string', 'integer'], 'pattern' => '^[0-9]{1,18}$', 'description' => $about],
            $integers => ['type' => 'string', 'pattern' => '^[0-9]{1,18}$', 'description' => $about],
            default => ['type' => 'string', 'format' => 'uuid', 'description' => $about],
        };
    }

    /** @return array<string, int> */
    private static function bounds(Field $field): array
    {
        return match ($field->format) {
            'percent' => ['minimum' => 0, 'maximum' => 100],
            'rating' => ['minimum' => 1, 'maximum' => $field->mostStars()],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function idSchema(Descriptor $d): array
    {
        return $d->idType === 'int'
            ? ['type' => 'string', 'pattern' => '^[0-9]{1,18}$']
            : ['type' => 'string', 'format' => 'uuid'];
    }

    /** @return array{resource: Descriptor, field: string}|null */
    private function lines(Descriptor $d): ?array
    {
        $lines = $d->items === null ? null : ($this->byName[$d->items] ?? null);
        foreach ($lines?->fields ?? [] as $key => $field) {
            if ($field->kind === 'belongsTo' && $field->target === $d->name) {
                return ['resource' => $lines, 'field' => $key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function nullable(array $schema): array
    {
        if (isset($schema['enum'])) {
            $schema['enum'][] = null;
        }
        $schema['type'] = [...(array) $schema['type'], 'null'];

        return $schema;
    }

    // ------------------------------------------------------------------ signing in

    private function signIn(): void
    {
        $this->paths['/auth/token'] = ['post' => [
            'tags' => ['Signing in'],
            'operationId' => 'signIn',
            'summary' => 'Sign in with an email and password',
            'description' => 'Answers a token to send as "Authorization: Bearer <token>", and a refresh token for a new one when it runs out (POST /auth/refresh). With a second step set up, answers "twoFactor": true and a challenge instead. Slowed down after a few attempts (429).',
            'security' => [],
            'requestBody' => self::body([
                'type' => 'object',
                'properties' => ['email' => ['type' => 'string', 'format' => 'email'], 'password' => ['type' => 'string'], 'deviceName' => ['type' => 'string', 'maxLength' => 255]],
                'required' => ['email', 'password'],
            ]),
            'responses' => [
                '200' => self::json('A second step is needed.', ['type' => 'object', 'properties' => ['twoFactor' => ['type' => 'boolean'], 'challenge' => ['type' => 'string'], 'methods' => ['type' => 'array', 'items' => ['type' => 'string']]]]),
                '201' => self::json('Signed in.', self::ref('NevelaToken')),
                '401' => self::json('The email and password don\'t match.', self::ref('NevelaError')),
                '403' => self::json('The email isn\'t verified yet.', self::ref('NevelaError')),
                '422' => self::use('Invalid'),
                '429' => self::use('TooMany'),
            ],
        ]];
        $this->paths['/auth/me'] = ['get' => [
            'tags' => ['Signing in'],
            'operationId' => 'me',
            'summary' => 'Who is signed in',
            'responses' => [
                '200' => self::json('The signed-in person, with their roles and permissions.', ['type' => 'object']),
                '401' => self::use('SignIn'),
            ],
        ]];
    }

    // ------------------------------------------------------------------ what every resource shares

    /** @return array<string, mixed> */
    private static function shared(): array
    {
        $issue = fn (string $where) => [
            'type' => 'object',
            'properties' => [$where => ['type' => 'string'], 'message' => ['type' => 'string']],
            'required' => [$where, 'message'],
        ];

        return [
            'NevelaError' => [
                'type' => 'object',
                'properties' => ['error' => ['type' => 'string', 'description' => 'Written to be shown to the person.'], 'code' => ['type' => 'string'], 'detail' => ['type' => 'string']],
                'required' => ['error'],
            ],
            'NevelaValidationError' => [
                'type' => 'object',
                'properties' => ['error' => ['type' => 'string'], 'issues' => ['type' => 'array', 'items' => $issue('path'), 'description' => 'Each field\'s problem, by its name ("items.0.quantity" for a line\'s).']],
                'required' => ['error', 'issues'],
            ],
            'NevelaQueryError' => [
                'type' => 'object',
                'properties' => ['error' => ['type' => 'string'], 'issues' => ['type' => 'array', 'items' => $issue('param')]],
                'required' => ['error', 'issues'],
            ],
            'NevelaRowsRefused' => [
                'type' => 'object',
                'properties' => [
                    'error' => ['type' => 'string'],
                    'rows' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['row' => ['type' => 'integer'], 'issues' => ['type' => 'array', 'items' => $issue('path')]], 'required' => ['row', 'issues']]],
                ],
                'required' => ['error'],
            ],
            'NevelaConflict' => [
                'type' => 'object',
                'properties' => [
                    'error' => ['type' => 'string'],
                    'code' => ['type' => 'string', 'enum' => ['CHANGED_SINCE_READ']],
                    'field' => ['type' => 'string', 'description' => 'The field whose value another record has.'],
                    'current' => ['type' => 'object', 'description' => 'The record as it is now.'],
                ],
                'required' => ['error'],
            ],
            'NevelaListMeta' => [
                'type' => 'object',
                'properties' => ['page' => ['type' => 'integer'], 'perPage' => ['type' => 'integer'], 'total' => ['type' => 'integer'], 'totalPages' => ['type' => 'integer']],
                'required' => ['page', 'perPage', 'total', 'totalPages'],
                'additionalProperties' => false,
            ],
            'NevelaStats' => [
                'type' => 'object',
                'properties' => [
                    'total' => ['type' => 'integer'],
                    'current' => ['type' => 'integer', 'description' => 'Created in the last "days".'],
                    'previous' => ['type' => 'integer', 'description' => 'Created in the "days" before those.'],
                    'values' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer'], 'description' => 'How many have each value of "field".'],
                ],
                'required' => ['total', 'current', 'previous', 'values'],
                'additionalProperties' => false,
            ],
            'NevelaInsights' => [
                'type' => 'object',
                'properties' => [
                    'total' => ['type' => 'integer'],
                    'unit' => ['type' => 'string', 'enum' => array_keys(Insights::PERIODS)],
                    'series' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'breakdown' => ['type' => 'array', 'items' => [
                        'type' => 'object',
                        'properties' => [
                            'field' => ['type' => 'string'],
                            'kind' => ['type' => 'string', 'enum' => ['enum', 'boolean']],
                            'slices' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['value' => ['type' => 'string'], 'count' => ['type' => 'integer']], 'required' => ['value', 'count'], 'additionalProperties' => false]],
                        ],
                        'required' => ['field', 'kind', 'slices'],
                        'additionalProperties' => false,
                    ]],
                ],
                'required' => ['total', 'unit', 'series', 'breakdown'],
                'additionalProperties' => false,
            ],
            'NevelaFile' => [
                'type' => 'object',
                'description' => 'A stored file. An image also has its size in pixels and its smaller renditions.',
                'properties' => [
                    'key' => ['type' => 'string'],
                    'url' => ['type' => ['string', 'null'], 'format' => 'uri'],
                    'private' => ['type' => 'boolean'],
                    'name' => ['type' => ['string', 'null']],
                    'mime' => ['type' => ['string', 'null']],
                    'size' => ['type' => ['integer', 'null']],
                    'width' => ['type' => ['integer', 'null']],
                    'height' => ['type' => ['integer', 'null']],
                    'optimised' => ['type' => ['boolean', 'null']],
                    'renditions' => ['type' => 'object', 'additionalProperties' => [
                        'type' => 'object',
                        'properties' => ['url' => ['type' => 'string'], 'width' => ['type' => ['integer', 'null']], 'height' => ['type' => ['integer', 'null']], 'size' => ['type' => ['integer', 'null']]],
                        'required' => ['url'],
                    ]],
                ],
                'required' => ['key', 'url'],
                'additionalProperties' => false,
            ],
            'NevelaPrivateFile' => [
                'type' => 'object',
                'description' => 'A file only someone who may view the record can fetch, from "url". Its key opens nothing.',
                'properties' => [
                    'url' => ['type' => 'string', 'format' => 'uri'],
                    'key' => ['type' => 'string'],
                    'private' => ['type' => 'boolean'],
                    'name' => ['type' => ['string', 'null']],
                    'mime' => ['type' => ['string', 'null']],
                    'size' => ['type' => ['integer', 'null']],
                ],
                'required' => ['url'],
                'additionalProperties' => false,
            ],
            'NevelaToken' => [
                'type' => 'object',
                'properties' => [
                    'token' => ['type' => 'string'],
                    'expiresAt' => ['type' => 'string', 'format' => 'date-time'],
                    'refreshToken' => ['type' => ['string', 'null']],
                    'refreshExpiresAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'session' => ['type' => 'string'],
                    'user' => ['type' => 'object'],
                ],
                'required' => ['token', 'expiresAt'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function responses(): array
    {
        return [
            'SignIn' => self::json('Not signed in, or the token has run out.', self::ref('NevelaError')),
            'Forbidden' => self::json('Signed in, without the permission this takes.', self::ref('NevelaError')),
            'NotFound' => self::json('No such record, or not one this person may see.', self::ref('NevelaError')),
            'Invalid' => self::json('What was sent needs fixing: each problem by field.', self::ref('NevelaValidationError')),
            'QueryRefused' => self::json('A query parameter that can\'t be used: each by name.', self::ref('NevelaQueryError')),
            'Taken' => self::json('A value another record has.', self::ref('NevelaConflict')),
            'TooMany' => self::json('Too many requests from one address: wait a minute.', self::ref('NevelaError')),
        ];
    }

    /**
     * No two operations with one id, which OpenAPI forbids and client generators trip on.
     * The names are made not to meet (an action's start "run"), but resource names are the
     * app's own: a PublicFoo's list and Foo's public list would both be listPublicFoo.
     */
    private function uniqueOperationIds(): void
    {
        $seen = [];
        foreach ($this->paths as $path => $item) {
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                if (! isset($item[$method]['operationId'])) {
                    continue;
                }
                $id = $item[$method]['operationId'];
                for ($n = 2; isset($seen[$id]); $n++) {
                    $id = $item[$method]['operationId'].$n;
                }
                $seen[$id] = true;
                $this->paths[$path][$method]['operationId'] = $id;
            }
        }
    }

    /** "a product", "an order". */
    private static function a(string $noun): string
    {
        return (preg_match('/^[aeiou]/i', $noun) ? 'an ' : 'a ').$noun;
    }

    /** @return array<string, string> */
    private static function ref(string $schema): array
    {
        return ['$ref' => "#/components/schemas/{$schema}"];
    }

    /** @return array<string, string> */
    private static function use(string $response): array
    {
        return ['$ref' => "#/components/responses/{$response}"];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    private static function json(string $description, array $schema, array $headers = []): array
    {
        return array_filter(['description' => $description, 'headers' => $headers ?: null, 'content' => ['application/json' => ['schema' => $schema]]]);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function body(array $schema, bool $optional = false): array
    {
        return ['required' => ! $optional, 'content' => ['application/json' => ['schema' => $schema]]];
    }
}
