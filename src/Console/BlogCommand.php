<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Nevela\Laravel\Generator\BlogPages;
use Nevela\Laravel\Generator\GeneratedFile;
use Nevela\Laravel\Generator\Writer;
use Nevela\Laravel\Media\Uploads;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Naming;

/**
 * A blog for the site: posts written in the dashboard, and /blog to read them at.
 *
 *   php artisan nevela:blog                          a Post resource, and the pages
 *   php artisan nevela:blog --resource=Article …     the pages only, reading a resource the app has
 *
 * Everything a blog is made of is already a resource's to have: rich text, an image, a
 * slug, a date it is published on, and public reading. This puts them together, and
 * writes the pages that show the result.
 */
final class BlogCommand extends Command
{
    /** The resource this makes when the app has none to read. */
    public const RESOURCE = 'Post';

    public const PATH = '/blog';

    protected $signature = 'nevela:blog
        {--resource= : Read the posts from a resource you already have, and write only the pages}
        {--title= : That resource\'s field for a post\'s title. Default: title}
        {--body= : Its field for the post itself. Default: body}
        {--excerpt= : Its field for a sentence or two about the post, if it has one}
        {--image= : Its field for a picture, if it has one}
        {--published= : Its field for when a post was published. Default: the one the resource is published by}
        {--sample : Add three posts to start with. For development: refused in production}
        {--no-migrate : Don\'t create the posts table yet}';

    protected $description = 'Give the site a blog: a Post resource to write in, and /blog to read it at';

    public function handle(): int
    {
        Nevela::forget();
        try {
            $all = Nevela::all();
            $given = $this->option('resource');
            [$descriptor, $fields, $created] = $given === null
                ? $this->ours($all)
                : [...$this->theirs((string) $given, $all), false];
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $dir = rtrim(config('nevela.descriptors_path'), '/\\');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        // The descriptor says where the blog is, so the site's header can link to it.
        file_put_contents($dir.DIRECTORY_SEPARATOR.Naming::kebab($descriptor->name).'.json', $descriptor->toJson());
        Nevela::forget();
        if (GenerateCommand::generate($this, [$descriptor], Nevela::all(), false) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $web = config('nevela.web_path');
        if (! $web || ! is_dir($web)) {
            $this->components->warn('There is no web app at nevela.web_path, so the pages were not written. Run this again when there is.');
        } else {
            foreach ((new Writer([GeneratedFile::TARGET_WEB => $web]))->write(BlogPages::files($descriptor, $fields)) as $row) {
                $path = str_replace('\\', '/', substr($row['path'], strlen(rtrim($web, '/\\')) + 1));
                $this->components->twoColumnDetail("../web/{$path}", $row['status'] === 'created' ? '<fg=green>created</>' : '<fg=gray>exists (yours)</>');
            }
        }

        $status = self::SUCCESS;
        if ($created && ! $this->option('no-migrate')) {
            $status = $this->call('migrate', ['--force' => true]);
        }
        if ($status === self::SUCCESS && $this->option('sample')) {
            $status = $this->sample($descriptor);
        }

        $this->newLine();
        $this->line('  The blog is at <fg=cyan>'.self::PATH.'</>, and '.$descriptor->pluralLabel.' is in the dashboard. A post is on the site once it has been published.');
        if (! $created) {
            $this->line('  Which records are public is the scope on <fg=cyan>app/Models/'.$descriptor->name.'.php</> (scopePublicQuery). The pages and <fg=cyan>lib/blog.ts</> are yours to change.');
        }

        return $status;
    }

    /**
     * The Post resource, made here: what a post is, that it is published by a date, and
     * that anyone may read a published one by its slug.
     *
     * @param  list<Descriptor>  $all
     * @return array{Descriptor, array<string, string|null>, bool}
     */
    private function ours(array $all): array
    {
        $fields = ['title' => 'title', 'body' => 'body', 'excerpt' => 'excerpt', 'image' => 'image', 'published' => 'publishedAt'];
        foreach ($all as $existing) {
            if ($existing->name === self::RESOURCE || $existing->table === 'posts') {
                // Run again on an app that has it: the pages are written if they aren't there, and nothing else changes.
                if ($existing->blog !== null) {
                    return [$existing, array_map(fn (?string $field) => $field !== null && isset($existing->fields[$field]) ? $field : null, $fields), false];
                }
                throw new InvalidArgumentException("There is a {$existing->name} resource already. To have the blog read it: php artisan nevela:blog --resource={$existing->name}");
            }
        }
        if ($this->tableExists('posts')) {
            throw new InvalidArgumentException('There is a posts table already, which is not a resource. Adopt it (php artisan nevela:adopt posts), then: php artisan nevela:blog --resource=Post');
        }

        $descriptor = Descriptor::fromArray([
            'name' => self::RESOURCE,
            'icon' => 'book',
            'publish' => 'publishedAt',
            'blog' => self::PATH,
            'fields' => [
                'title' => ['kind' => 'string'],
                'slug' => ['kind' => 'string', 'format' => 'slug', 'unique' => true, 'from' => 'title'],
                'excerpt' => ['kind' => 'string', 'maxLength' => 500, 'required' => false],
                'body' => ['kind' => 'text', 'format' => 'rich'],
                'image' => ['kind' => 'file', 'accept' => ['image'], 'profile' => 'cover', 'required' => false],
                'publishedAt' => ['kind' => 'datetime', 'required' => false],
            ],
            'public' => ['read' => ['by' => 'slug', 'fields' => ['title', 'slug', 'excerpt', 'body', 'image', 'publishedAt']]],
        ]);

        return [$descriptor, $fields, true];
    }

    /**
     * A resource the app already has, read as posts. Nothing about it is changed but the
     * note of where its blog is; what it lacks for a blog is said, and nothing is written.
     *
     * @param  list<Descriptor>  $all
     * @return array{Descriptor, array<string, string|null>}
     *
     * @throws InvalidArgumentException
     */
    private function theirs(string $name, array $all): array
    {
        $descriptor = null;
        foreach ($all as $candidate) {
            if (strcasecmp($candidate->name, $name) === 0) {
                $descriptor = $candidate;
            }
        }
        if ($descriptor === null) {
            throw new InvalidArgumentException("There is no resource called {$name}.");
        }
        $public = $descriptor->public;
        if ($public?->read === null) {
            throw new InvalidArgumentException("{$descriptor->name} can't be read publicly, and a blog is public. Add to its descriptor: \"public\": { \"read\": { \"by\": \"slug\", \"fields\": [ … ] } }");
        }
        if ($public->by === null) {
            throw new InvalidArgumentException("{$descriptor->name}'s public section doesn't say which unique field a record is fetched by, and a post's address is made from one. Add \"by\": \"slug\" to its \"read\".");
        }

        $fields = [];
        foreach (['title' => 'title', 'body' => 'body', 'excerpt' => null, 'image' => null, 'published' => $descriptor->publish] as $part => $default) {
            $asked = $this->option($part);
            // A column's name is understood as well as the field's: published_at, publishedAt.
            $field = $asked === null ? $default : Naming::camel((string) $asked);
            if ($field === null) {
                $fields[$part] = null;

                continue;
            }
            if (! isset($descriptor->fields[$field])) {
                throw new InvalidArgumentException("{$descriptor->name} has no field called {$field}. Name the one that is a post's {$part}: --{$part}=…");
            }
            if (! in_array($field, $public->read, true)) {
                throw new InvalidArgumentException("\"{$field}\" is not among the fields anyone may read of {$descriptor->name}, so the blog couldn't show it. Add it to \"public.read.fields\" in its descriptor.");
            }
            $fields[$part] = $field;
        }

        $data = $descriptor->toArray();
        $data['blog'] = self::PATH;

        return [Descriptor::fromArray($data), $fields];
    }

    /**
     * Three posts, so /blog has something on it the first time it is opened. Like the
     * sample users: for looking around a new app, and never for a live one.
     */
    private function sample(Descriptor $descriptor): int
    {
        if (app()->environment('production')) {
            $this->components->error('Sample posts are for development. This app is in production, so none were made.');

            return self::FAILURE;
        }
        $model = "App\\Models\\{$descriptor->name}";
        if ($descriptor->name !== self::RESOURCE || ! class_exists($model) || ! $this->tableExists($descriptor->table)) {
            $this->components->warn('Sample posts are for the Post resource this command makes, once its table is there.');

            return self::SUCCESS;
        }
        if ($model::query()->exists()) {
            return self::SUCCESS; // it has posts already; these would only be in the way
        }

        // A picture each, where pictures can be made here.
        $pictures = Uploads::placeholders($descriptor, $descriptor->fields['image'], 3);
        foreach (self::POSTS as $index => $post) {
            $model::create($post + ['image' => $pictures[$index] ?? null, 'published_at' => now()->subDays(1 + $index * 3)]);
        }
        $this->components->info('Added three sample posts. Edit or delete them under Posts in the dashboard.');

        return self::SUCCESS;
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false; // no database to ask yet
        }
    }

    /** @var list<array{title: string, slug: string, excerpt: string, body: string}> */
    private const POSTS = [
        [
            'title' => 'Welcome to your blog',
            'slug' => 'welcome-to-your-blog',
            'excerpt' => 'This post came with the app. It is here so the blog has something on it the first time you open it.',
            'body' => '<p>This post came with the app. It is here so the blog has something on it the first time you open it, and you can delete it whenever you like.</p><h2>Where posts are written</h2><p>Sign in and open <strong>Posts</strong> in the dashboard. A post has a title, an excerpt for the list, a picture and a body with headings, lists, links and quotes.</p><h2>Where they are read</h2><p>At <a href="/blog">/blog</a>, newest first, and each at an address of its own made from its title.</p>',
        ],
        [
            'title' => 'Drafts, publishing and scheduling',
            'slug' => 'drafts-publishing-and-scheduling',
            'excerpt' => 'A post is not on the site until it is published. Until then it is a draft, and only people who sign in can see it.',
            'body' => '<p>A post is not on the site until it is published. Until then it is a draft, and only people who sign in can see it.</p><ul><li><strong>Publish</strong> puts it on the site at once.</li><li><strong>Unpublish</strong> takes it off again and keeps it as a draft.</li><li>Give it a date and time still to come and it is <strong>scheduled</strong>: it appears by itself when that time arrives.</li></ul><p>The list in the dashboard says which of the three each post is, and can be filtered by it.</p>',
        ],
        [
            'title' => 'Making the blog yours',
            'slug' => 'making-the-blog-yours',
            'excerpt' => 'The pages that show the blog were written once, into your own app, and are yours to change.',
            'body' => '<p>The pages that show the blog were written once, into your own app, and are yours to change.</p><h3>What to open</h3><ol><li><strong>lib/blog.ts</strong> for the blog\'s title, its description and how many posts are on a page.</li><li><strong>app/blog</strong> for the list and for a post\'s own page.</li></ol><blockquote><p>There is an RSS feed at /blog/feed.xml, and every published post is in the sitemap.</p></blockquote>',
        ],
    ];
}
