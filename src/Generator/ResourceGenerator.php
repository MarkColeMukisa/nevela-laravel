<?php

namespace Nevela\Laravel\Generator;

use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Field;
use Nevela\Laravel\Support\Naming;

/**
 * Turns descriptors into files. Pure: no Laravel, no filesystem — Writer does the I/O.
 *
 * Laravel output is ordinary Laravel code (model, migration, form request, API resource,
 * controller, policy, routes). The web output is a Flare `.resource.ts` so JB's dashboard
 * renders the resource without knowing Laravel is behind it.
 */
final class ResourceGenerator
{
    private const M = '// '.Writer::START;

    private const E = '// '.Writer::END;

    /** @return list<GeneratedFile> */
    public function forResource(Descriptor $d, ?string $timestamp = null): array
    {
        $timestamp ??= date('Y_m_d_His');
        $api = GeneratedFile::TARGET_API;

        return [
            new GeneratedFile($api, "app/Models/{$d->name}.php", $this->model($d)),
            new GeneratedFile($api, "database/migrations/{$timestamp}_create_{$d->table}_table.php", $this->migration($d), GeneratedFile::MODE_ONCE, "database/migrations/*_create_{$d->table}_table.php"),
            new GeneratedFile($api, "app/Http/Requests/Nevela/{$d->name}Request.php", $this->request($d)),
            new GeneratedFile($api, "app/Http/Resources/Nevela/{$d->name}Resource.php", $this->resource($d)),
            new GeneratedFile($api, "app/Http/Controllers/Api/{$d->name}Controller.php", $this->controller($d)),
            new GeneratedFile($api, "app/Policies/{$d->name}Policy.php", $this->policy($d), GeneratedFile::MODE_ONCE),
            new GeneratedFile(GeneratedFile::TARGET_WEB, 'resources/'.Naming::kebab($d->name).'.resource.ts', $this->typescript($d)),
            ...$this->pages($d),
        ];
    }

    /**
     * The dashboard pages for a resource, in Flare's layout: list, new, detail and edit,
     * each with its loading skeleton. They only name the descriptor — Flare's components
     * render everything from it — so they are written once and are yours afterwards.
     *
     * @return list<GeneratedFile>
     */
    public function pages(Descriptor $d): array
    {
        $var = Naming::camel($d->name).'Resource';
        $import = "import {$var} from \"@/resources/".Naming::kebab($d->name).'.resource";';
        $fields = count($d->fields);
        $columns = min(6, $fields);
        $rows = $fields + 2;
        $formLoading = <<<TSX
        import { FormSkeleton, PageHeaderSkeleton } from "@/components/dashboard/skeletons";

        export default function Loading() {
          return (
            <>
              <PageHeaderSkeleton actions={0} />
              <FormSkeleton fields={{$fields}} />
            </>
          );
        }

        TSX;

        $pages = [
            'page.tsx' => <<<TSX
            import { PageHeader } from "@/components/dashboard/page-header";
            import { ResourceStats } from "@/components/dashboard/resource-stats";
            import { ResourceTable } from "@/components/dashboard/resource-table";
            import type { SearchParams } from "@/components/dashboard/query";
            {$import}

            export const metadata = { title: {$var}.pluralLabel };

            export default async function {$d->name}ListPage({ searchParams }: { searchParams: Promise<SearchParams> }) {
              return (
                <>
                  <PageHeader title={{$var}.pluralLabel} crumbs={[{ label: "Dashboard", href: "/dashboard" }, { label: {$var}.pluralLabel }]} />
                  <ResourceStats resource={{$var}} />
                  <ResourceTable resource={{$var}} searchParams={await searchParams} />
                </>
              );
            }

            TSX,
            'loading.tsx' => <<<TSX
            import { ResourceListSkeleton } from "@/components/dashboard/skeletons";

            export default function Loading() {
              return <ResourceListSkeleton columns={{$columns}} />;
            }

            TSX,
            'new/page.tsx' => <<<TSX
            import { ResourceFormPage } from "@/components/dashboard/resource-form-page";
            {$import}

            export default function New{$d->name}Page() {
              return <ResourceFormPage resource={{$var}} />;
            }

            TSX,
            'new/loading.tsx' => $formLoading,
            '[id]/page.tsx' => <<<TSX
            import { RecordDetail } from "@/components/dashboard/record-detail";
            {$import}

            export default async function {$d->name}DetailPage({ params }: { params: Promise<{ id: string }> }) {
              return <RecordDetail resource={{$var}} id={(await params).id} />;
            }

            TSX,
            '[id]/loading.tsx' => <<<TSX
            import { DetailSkeleton, PageHeaderSkeleton } from "@/components/dashboard/skeletons";

            export default function Loading() {
              return (
                <>
                  <PageHeaderSkeleton />
                  <DetailSkeleton rows={{$rows}} />
                </>
              );
            }

            TSX,
            '[id]/edit/page.tsx' => <<<TSX
            import { ResourceFormPage } from "@/components/dashboard/resource-form-page";
            {$import}

            export default async function Edit{$d->name}Page({ params }: { params: Promise<{ id: string }> }) {
              return <ResourceFormPage resource={{$var}} id={(await params).id} />;
            }

            TSX,
            '[id]/edit/loading.tsx' => $formLoading,
        ];

        $files = [];
        foreach ($pages as $path => $contents) {
            $header = "// Generated once by Nevela for {$d->name}. This file is yours.\n";
            $files[] = new GeneratedFile(GeneratedFile::TARGET_WEB, "app/dashboard/{$d->slug}/{$path}", $header.$contents, GeneratedFile::MODE_ONCE);
        }

        return $files;
    }

    /**
     * `php nevela <command>` at the top of the project, so nobody has to `cd` into the
     * Laravel app first. It hands the command to artisan in the same process: the arguments
     * arrive exactly as they were typed, with no second round of shell quoting.
     *
     * @param  string  $api  The Laravel app's folder relative to the project root, e.g. "apps/api"
     */
    public function launcher(string $api): GeneratedFile
    {
        $api = trim(str_replace('\\', '/', $api), '/');
        $m = self::M;
        $e = self::E;

        return new GeneratedFile(GeneratedFile::TARGET_ROOT, 'nevela', <<<PHP
        #!/usr/bin/env php
        <?php

        // Run Nevela from the top of the project: php nevela <command>
        // Maintained by `php artisan nevela:generate`. Add your own shortcuts below the block.
        {$m}
        \$api = __DIR__.'/{$api}';
        \$args = array_slice(\$_SERVER['argv'], 1);
        \$name = array_shift(\$args) ?? 'help';

        // What you type => the artisan command it runs.
        \$nevela = ['resource', 'generate', 'seed', 'user', 'update', 'dev', 'status', 'version'];
        \$artisan = ['migrate', 'tinker', 'test', 'serve'];
        if (in_array(\$name, ['--version', '-v', '-V'], true)) {
            \$name = 'version';
        }

        if (in_array(\$name, \$nevela, true)) {
            // A new resource is no use until its table exists, so make it in the same step.
            if (\$name === 'resource' && ! in_array('--no-migrate', \$args, true)) {
                \$args[] = '--migrate';
            }
            \$args = array_values(array_diff(\$args, ['--no-migrate']));
            \$command = ['nevela:'.\$name, ...\$args];
        } elseif (in_array(\$name, \$artisan, true)) {
            \$command = [\$name, ...\$args];
        } elseif (\$name === 'artisan') {
            \$command = \$args; // anything else: php nevela artisan route:list
        } else {
            echo <<<'HELP'

              Nevela, from the top of your project.

              php nevela dev                   run the API and the dashboard
              php nevela status                check versions, migrations, users and the dashboard
              php nevela resource Product --fields="name:string, price:money" --seed
                                               add a resource, create its table, fill it
              php nevela generate              regenerate after editing a descriptor
              php nevela seed Product          fill a resource with records
              php nevela user                  create someone who can sign in
              php nevela update                update Nevela and the dashboard
              php nevela version               which Nevela this is

              php nevela migrate               php artisan migrate
              php nevela artisan <command>     any other artisan command


            HELP;
            exit(in_array(\$name, ['help', '--help', '-h'], true) ? 0 : 1);
        }

        if (! is_file(\$api.'/artisan')) {
            fwrite(STDERR, "There is no Laravel app at {\$api}.\\n");
            exit(1);
        }

        chdir(\$api);
        \$_SERVER['argv'] = \$argv = ['artisan', ...\$command];
        \$_SERVER['argc'] = \$argc = count(\$argv);

        require \$api.'/artisan';
        {$e}

        PHP);
    }

    /**
     * The web app's registry of every descriptor: what the dashboard's sidebar, home page
     * and stores are built from.
     *
     * @param  list<Descriptor>  $all
     */
    public function registry(array $all): GeneratedFile
    {
        usort($all, fn (Descriptor $a, Descriptor $b) => strcmp($a->name, $b->name));
        $imports = [];
        $names = [];
        foreach ($all as $d) {
            $var = Naming::camel($d->name).'Resource';
            $imports[] = "import {$var} from \"./".Naming::kebab($d->name).'.resource";';
            $names[] = $var;
        }
        $list = implode(', ', $names);
        // An app with no resources yet still needs the registry to exist and be empty.
        $body = $names === []
            ? 'export const resources = [] as const;'
            : implode("\n", $imports)."\n\nexport { {$list} };\nexport const resources = [{$list}] as const;";
        $m = self::M;
        $e = self::E;

        return new GeneratedFile(GeneratedFile::TARGET_WEB, 'resources/index.ts', <<<TS
        // Registry of every resource descriptor (maintained by `php artisan nevela:generate`).
        {$m}
        {$body}
        {$e}

        TS);
    }

    /** @param list<Descriptor> $all */
    public function routes(array $all): GeneratedFile
    {
        usort($all, fn (Descriptor $a, Descriptor $b) => strcmp($a->slug, $b->slug));
        $lines = [];
        foreach ($all as $d) {
            $controller = "\\App\\Http\\Controllers\\Api\\{$d->name}Controller::class";
            $lines[] = "Route::get('{$d->slug}/_stats', [{$controller}, 'stats'])->name('{$d->slug}.stats');";
            $lines[] = "Route::apiResource('{$d->slug}', {$controller});";
        }
        $body = implode("\n", $lines);
        $m = self::M;
        $e = self::E;

        return new GeneratedFile(GeneratedFile::TARGET_API, 'routes/nevela.php', <<<PHP
        <?php

        use Illuminate\Support\Facades\Route;

        // Loaded by Nevela under the prefix and middleware in config/nevela.php
        // (default: /api, auth:sanctum). Add your own routes below the generated block.
        {$m}
        {$body}
        {$e}

        PHP);
    }

    public function model(Descriptor $d): string
    {
        $fillable = implode(', ', array_map(fn (Field $f) => "'{$f->column()}'", $d->fields));
        $casts = [];
        foreach ($d->fields as $f) {
            $cast = match ($f->kind) {
                'int' => 'integer',
                'float' => 'float',
                'boolean' => 'boolean',
                'date' => 'date:Y-m-d',
                'datetime' => 'datetime',
                default => null,
            };
            if ($cast) {
                $casts[] = "            '{$f->column()}' => '{$cast}',";
            }
        }
        $castBlock = $casts ? implode("\n", $casts) : '';
        $m = self::M;
        $e = self::E;

        return <<<PHP
        <?php

        namespace App\Models;

        use Illuminate\Database\Eloquent\Concerns\HasUuids;
        use Illuminate\Database\Eloquent\Model;

        class {$d->name} extends Model
        {
            use HasUuids;

            protected \$table = '{$d->table}';

            {$m}
            protected \$fillable = [{$fillable}];

            protected function casts(): array
            {
                return [
        {$castBlock}
                ];
            }
            {$e}

            // Relationships, scopes and accessors go here — outside the generated block.
        }

        PHP;
    }

    public function migration(Descriptor $d): string
    {
        $columns = [];
        foreach ($d->fields as $f) {
            $c = $f->column();
            $line = match (true) {
                $f->kind === 'text' => "\$table->text('{$c}')",
                $f->kind === 'int' => "\$table->integer('{$c}')",
                $f->kind === 'float' && $f->format === 'money' => "\$table->decimal('{$c}', 12, 2)",
                $f->kind === 'float' => "\$table->double('{$c}')",
                $f->kind === 'boolean' => "\$table->boolean('{$c}')->default(false)",
                $f->kind === 'date' => "\$table->date('{$c}')",
                $f->kind === 'datetime' => "\$table->dateTime('{$c}')",
                $f->format === 'url' => "\$table->string('{$c}', 2048)",
                default => "\$table->string('{$c}')",
            };
            if (! $f->required) {
                $line .= '->nullable()';
            }
            if ($f->unique) {
                $line .= '->unique()';
            } elseif ($f->kind === 'enum') {
                $line .= '->index()';
            }
            $columns[] = "            {$line};";
        }
        $cols = implode("\n", $columns);

        return <<<PHP
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        // Generated once by Nevela. Later descriptor changes need a new migration of your own.
        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('{$d->table}', function (Blueprint \$table) {
                    \$table->uuid('id')->primary();
        {$cols}
                    \$table->timestamps();
                });
            }

            public function down(): void
            {
                Schema::dropIfExists('{$d->table}');
            }
        };

        PHP;
    }

    public function request(Descriptor $d): string
    {
        $var = $d->variable();
        $param = $d->routeParameter();
        $rules = [];
        $columns = [];
        foreach ($d->fields as $f) {
            $rules[] = "            '{$f->name}' => [".implode(', ', $this->rules($d, $f)).'],';
            $columns[] = "'{$f->name}' => '{$f->column()}'";
        }
        $ruleLines = implode("\n", $rules);
        $columnMap = implode(', ', $columns);
        $m = self::M;
        $e = self::E;

        return <<<PHP
        <?php

        namespace App\Http\Requests\Nevela;

        use App\Models\\{$d->name};
        use Illuminate\Support\Facades\Gate;
        use Illuminate\Validation\Rule;
        use Nevela\Laravel\Http\ResourceRequest;

        /**
         * Validation for creating (POST), replacing (PUT) and patching (PATCH) a {$d->label}.
         * PATCH makes every rule "sometimes"; PUT clears optional fields left out.
         */
        class {$d->name}Request extends ResourceRequest
        {
            public function authorize(): bool
            {
                \${$var} = \$this->route('{$param}');

                return \${$var} instanceof {$d->name}
                    ? Gate::allows('update', \${$var})
                    : Gate::allows('create', {$d->name}::class);
            }

            {$m}
            public function rules(): array
            {
                \${$var} = \$this->route('{$param}');

                return [
        {$ruleLines}
                ];
            }

            protected function columns(): array
            {
                return [{$columnMap}];
            }
            {$e}
        }

        PHP;
    }

    /** @return list<string> PHP expressions */
    private function rules(Descriptor $d, Field $f): array
    {
        $rules = [$f->required ? "'required'" : "'nullable'"];
        $type = match (true) {
            $f->format === 'email' => ["'email'", "'max:255'"],
            $f->format === 'url' => ["'url'", "'max:2048'"],
            $f->format === 'tel' => ["'string'", "'max:32'"],
            $f->format === 'slug' => ["'string'", "'alpha_dash'", "'max:255'"],
            $f->format === 'color' => ["'string'", "'regex:/^#[0-9a-fA-F]{6}$/'"],
            $f->kind === 'string' => ["'string'", "'max:255'"],
            $f->kind === 'text' => ["'string'", "'max:65535'"],
            $f->kind === 'int' && $f->format === 'percent' => ["'integer'", "'between:0,100'"],
            $f->kind === 'int' => ["'integer'"],
            $f->kind === 'float' && $f->format === 'percent' => ["'numeric'", "'between:0,100'"],
            $f->kind === 'float' => ["'numeric'"],
            $f->kind === 'boolean' => ["'boolean'"],
            $f->kind === 'date' => ["'date_format:Y-m-d'"],
            $f->kind === 'datetime' => ["'date'"],
            $f->kind === 'enum' => ['Rule::in(['.implode(', ', array_map(fn ($o) => "'{$o}'", $f->options)).'])'],
        };
        $rules = [...$rules, ...$type];
        if ($f->unique) {
            $rules[] = "Rule::unique('{$d->table}', '{$f->column()}')->ignore(\${$d->variable()})";
        }

        return $rules;
    }

    public function resource(Descriptor $d): string
    {
        $lines = [];
        foreach ($d->fields as $f) {
            $value = match ($f->kind) {
                'date' => "\$this->{$f->column()}?->format('Y-m-d')",
                'datetime' => "\$this->{$f->column()}?->toJSON()",
                default => "\$this->{$f->column()}",
            };
            $lines[] = "            '{$f->name}' => {$value},";
        }
        $body = implode("\n", $lines);
        $m = self::M;
        $e = self::E;

        return <<<PHP
        <?php

        namespace App\Http\Resources\Nevela;

        use Illuminate\Http\Request;
        use Illuminate\Http\Resources\Json\JsonResource;

        /** A {$d->label} as Flare's client expects it: camelCase keys, unwrapped. */
        class {$d->name}Resource extends JsonResource
        {
            public static \$wrap = null;

            public function toArray(Request \$request): array
            {
                return [
                    'id' => \$this->id,
                    {$m}
        {$body}
                    {$e}
                    'createdAt' => \$this->created_at?->toJSON(),
                    'updatedAt' => \$this->updated_at?->toJSON(),
                ];
            }
        }

        PHP;
    }

    public function controller(Descriptor $d): string
    {
        $var = $d->variable();
        $m = self::M;
        $e = self::E;

        return <<<PHP
        <?php

        namespace App\Http\Controllers\Api;

        use App\Http\Controllers\Controller;
        use App\Http\Requests\Nevela\\{$d->name}Request;
        use App\Http\Resources\Nevela\\{$d->name}Resource;
        use App\Models\\{$d->name};
        use Illuminate\Http\JsonResponse;
        use Illuminate\Http\Request;
        use Illuminate\Http\Response;
        use Illuminate\Support\Facades\Gate;
        use Nevela\Laravel\Nevela;

        /**
         * {$d->pluralLabel} over Flare's REST contract:
         * GET /{$d->slug}?page&perPage&sort=-createdAt&q&filter[field] → { data, meta }.
         */
        class {$d->name}Controller extends Controller
        {
            {$m}
            public function index(Request \$request): JsonResponse
            {
                Gate::authorize('viewAny', {$d->name}::class);

                return Nevela::list({$d->name}::query(), '{$d->name}', \$request, {$d->name}Resource::class);
            }

            public function stats(Request \$request): JsonResponse
            {
                Gate::authorize('viewAny', {$d->name}::class);

                return Nevela::stats({$d->name}::query(), '{$d->name}', \$request);
            }

            public function store({$d->name}Request \$request): JsonResponse
            {
                \${$var} = {$d->name}::create(\$request->values());

                return (new {$d->name}Resource(\${$var}->refresh()))
                    ->response()
                    ->setStatusCode(201)
                    ->header('Location', \$request->url().'/'.\${$var}->getKey());
            }

            public function show({$d->name} \${$var}): {$d->name}Resource
            {
                Gate::authorize('view', \${$var});

                return new {$d->name}Resource(\${$var});
            }

            public function update({$d->name}Request \$request, {$d->name} \${$var}): {$d->name}Resource
            {
                \${$var}->update(\$request->values());

                return new {$d->name}Resource(\${$var}->refresh());
            }

            public function destroy({$d->name} \${$var}): Response
            {
                Gate::authorize('delete', \${$var});
                \${$var}->delete();

                return response()->noContent();
            }
            {$e}
        }

        PHP;
    }

    public function policy(Descriptor $d): string
    {
        $var = $d->variable();

        return <<<PHP
        <?php

        namespace App\Policies;

        use App\Models\\{$d->name};
        use App\Models\User;

        /**
         * Who may do what with {$d->pluralLabel}. Generated once — this file is yours.
         *
         * Default: any signed-in user (routes already require auth:sanctum). Tighten this
         * before production, e.g. `return in_array(\$user->role, ['admin', 'staff']);`.
         */
        class {$d->name}Policy
        {
            public function viewAny(User \$user): bool
            {
                return true;
            }

            public function view(User \$user, {$d->name} \${$var}): bool
            {
                return true;
            }

            public function create(User \$user): bool
            {
                return true;
            }

            public function update(User \$user, {$d->name} \${$var}): bool
            {
                return true;
            }

            public function delete(User \$user, {$d->name} \${$var}): bool
            {
                return true;
            }
        }

        PHP;
    }

    public function typescript(Descriptor $d): string
    {
        $j = fn (mixed $v) => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $config = [
            "  name: {$j($d->name)},",
            "  table: {$j($d->table)},",
            "  slug: {$j($d->slug)},",
            "  label: {$j($d->label)},",
            "  pluralLabel: {$j($d->pluralLabel)},",
        ];
        if ($d->icon) {
            $config[] = "  icon: {$j($d->icon)},";
        }
        if ($d->group) {
            $config[] = "  group: {$j($d->group)},";
        }
        if ($d->titleField) {
            $config[] = "  titleField: {$j($d->titleField)},";
        }
        $fields = [];
        foreach ($d->fields as $f) {
            $options = array_filter([
                'required' => $f->required ? null : 'false',
                'unique' => $f->unique ? 'true' : null,
                'label' => $f->label ? $j($f->label) : null,
                'format' => in_array($f->format, ['money', 'percent', 'rating'], true) ? $j($f->format) : null,
            ]);
            $opts = $options ? '{ '.implode(', ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($options), $options)).' }' : '';
            $call = match (true) {
                $f->kind === 'enum' => "field.enum({$j($f->options)}".($opts ? ", {$opts}" : '').')',
                $f->kind === 'string' && $f->format !== null => "field.{$f->format}({$opts})",
                default => "field.{$f->kind}({$opts})",
            };
            $fields[] = "    {$f->name}: {$call},";
        }
        $configLines = implode("\n", $config);
        $fieldLines = implode("\n", $fields);
        $file = Naming::kebab($d->name);
        $m = self::M;
        $e = self::E;

        return <<<TS
        // Generated by Nevela from nevela/resources/{$file}.json in the Laravel app.
        // Laravel owns validation, authorization and persistence; this descriptor tells the
        // Flare dashboard how to render the resource. Change fields in the JSON and run
        // `php artisan nevela:generate`. Display-only options may go below the block.
        import { defineResource, field } from "@flaredev/core";

        export default defineResource({
          {$m}
        {$configLines}
          fields: {
        {$fieldLines}
          },
          {$e}
        });

        TS;
    }
}
