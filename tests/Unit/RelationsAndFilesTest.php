<?php

namespace Nevela\Laravel\Tests\Unit;

use InvalidArgumentException;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Fake;
use Nevela\Laravel\Support\Field;
use Nevela\Laravel\Support\ListQuery;
use PHPUnit\Framework\TestCase;

final class RelationsAndFilesTest extends TestCase
{
    private function category(): Descriptor
    {
        return Descriptor::fromSpec('Category', 'name:string, slug:slug!, image:image?');
    }

    private function product(): Descriptor
    {
        return Descriptor::fromSpec('Product', 'name:string, price:money, image:image(product)?, category:belongsTo(Category), manual:file(pdf|document)?');
    }

    public function test_a_relation_is_written_as_the_resource_it_points_at_and_stored_as_an_id(): void
    {
        $field = Field::parse('category:belongsTo(Category)');

        $this->assertSame(['categoryId', 'belongsTo', 'Category', 'category_id', 'category', 'Category'], [$field->name, $field->kind, $field->target, $field->column(), $field->relation(), $field->label()]);
        // The id form and the snake_case form mean the same field; the target can be left to the name.
        $this->assertSame('categoryId', Field::parse('categoryId:belongsTo(Category)')->name);
        $this->assertSame('Category', Field::parse('category:belongs_to')->target);
        $this->assertSame('ParentCategory', Field::parse('parent_category:belongsTo?')->target);
        $this->assertFalse(Field::parse('category:belongsTo(Category)?')->required);
        $this->assertSame(['kind' => 'belongsTo', 'target' => 'Category'], $field->toArray());
        $this->assertEquals($field, Field::fromArray('categoryId', $field->toArray()));
    }

    public function test_an_image_is_a_file_field_that_takes_images_and_may_name_a_profile(): void
    {
        $image = Field::parse('image:image(product)?');
        $manual = Field::parse('manual:file(pdf|document)');

        $this->assertSame(['file', ['image'], 'product', false, true], [$image->kind, $image->accept, $image->profile, $image->required, $image->isImage()]);
        $this->assertSame([['pdf', 'document'], null, false], [$manual->accept, $manual->profile, $manual->isImage()]);
        $this->assertSame(['any'], Field::parse('attachment:file')->accept);
        $this->assertFalse($image->sortable() || $image->filterable() || $image->searchable());
        $this->assertEquals($image, Field::fromArray('image', $image->toArray()));
    }

    public function test_grits_and_flares_ways_of_writing_a_field_mean_the_same_thing(): void
    {
        $image = Field::parse('image:image?');
        foreach (['image:file:image?', 'image:file:[image]?', 'image:file[image]?', 'image:file(image)?', 'image:file: [ image ]?'] as $spelling) {
            $this->assertEquals($image, Field::parse($spelling), $spelling);
        }
        $this->assertTrue(Field::parse('image:file:image')->isImage());
        $this->assertSame(['pdf', 'image'], Field::parse('docs:file:[pdf, image]')->accept);
        $this->assertSame(['pdf', 'image'], Field::parse('docs:file:pdf|image')->accept);
        $this->assertSame('product', Field::parse('image:image:product')->profile);

        $category = Field::parse('category:belongsTo(Category)');
        foreach (['category:belongs_to:Category', 'category:belongsTo:Category', 'category_id:belongs_to:Category', 'categoryId:belongsTo[Category]'] as $spelling) {
            $this->assertEquals($category, Field::parse($spelling), $spelling);
        }
        $this->assertFalse(Field::parse('category:belongs_to:Category?')->required);
        $this->assertSame(['draft', 'live'], Field::parse('status:enum:draft|live')->options);
        $this->assertSame(['draft', 'live'], Field::parse('status:enum:[draft, live]!')->options);

        // In a whole list of fields, a comma inside brackets doesn't end the field.
        $resource = Descriptor::fromSpec('Product', 'name:string, image:file:image?, docs:file:[pdf, image]?, category:belongs_to:Category');
        $this->assertSame(['name', 'image', 'docs', 'categoryId'], array_keys($resource->fields));
        $this->assertSame(['pdf', 'image'], $resource->fields['docs']->accept);
    }

    public function test_fields_that_make_no_sense_are_refused_with_what_to_write_instead(): void
    {
        foreach ([
            'photo:file(pictures)' => 'not something "photo" can accept',
            'image:image!' => "can't be unique",
            'image:image(Big_One)' => 'may only use lower-case letters',
        ] as $token => $message) {
            try {
                Field::parse($token);
                $this->fail("{$token} was accepted");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $this->expectExceptionMessage('ends in "Id"');
        new Field('category', 'belongsTo', target: 'Category');
    }

    public function test_the_migration_links_the_tables_and_the_request_checks_both(): void
    {
        $generator = new ResourceGenerator;
        $all = [$this->category(), $this->product()];

        $migration = $generator->migration($this->product(), $all);
        $this->assertStringContainsString("\$table->foreignUuid('category_id')->index()->constrained('categories');", $migration);
        $this->assertStringContainsString("\$table->string('image', 512)->nullable();", $migration);

        // An optional parent lets go of its children instead of blocking the delete.
        $optional = Descriptor::fromSpec('Product', 'name:string, category:belongsTo(Category)?');
        $this->assertStringContainsString("\$table->foreignUuid('category_id')->nullable()->index()->constrained('categories')->nullOnDelete();", $generator->migration($optional, $all));

        $request = $generator->request($this->product(), $all);
        $this->assertStringContainsString("'categoryId' => ['required', 'uuid', Rule::exists('categories', 'id')],", $request);
        $this->assertStringContainsString("'image' => ['nullable', 'string', 'max:512', new \\Nevela\\Laravel\\Rules\\UploadKey('Product', 'image')],", $request);
        $this->assertStringContainsString("'categoryId' => 'category_id'", $request);
    }

    public function test_a_relation_is_generated_on_both_ends(): void
    {
        $generator = new ResourceGenerator;
        $all = [$this->category(), $this->product()];

        $product = $generator->model($this->product(), $all);
        $this->assertStringContainsString('public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo', $product);
        $this->assertStringContainsString("return \$this->belongsTo(Category::class, 'category_id');", $product);

        $category = $generator->model($this->category(), $all);
        $this->assertStringContainsString('public function products(): \Illuminate\Database\Eloquent\Relations\HasMany', $category);
        $this->assertStringContainsString("return \$this->hasMany(Product::class, 'category_id');", $category);
        // The relations are inside the generated block, so they follow the descriptor.
        $this->assertLessThan(strpos($category, 'nevela:generated:end'), strpos($category, 'function products'));

        $this->assertStringContainsString('categoryId: field.belongsTo("Category"),', $generator->typescript($this->product(), $all));
        $this->assertStringContainsString('image: field.file(["image"], { required: false, list: true }),', $generator->typescript($this->product(), $all));
        $this->assertStringContainsString('manual: field.file(["pdf","document"], { required: false }),', $generator->typescript($this->product(), $all));
        $this->assertStringContainsString('products: field.hasMany("Product", { foreignKey: "categoryId" }),', $generator->typescript($this->category(), $all));

        // Without the other resources nothing is assumed about them.
        $this->assertStringNotContainsString('hasMany', $generator->typescript($this->category()));
    }

    public function test_a_parent_with_children_answers_409_and_one_whose_children_are_optional_does_not(): void
    {
        $generator = new ResourceGenerator;
        $controller = $generator->controller($this->category(), [$this->category(), $this->product()]);

        $this->assertStringContainsString('public function destroy(Category $category): Response|JsonResponse', $controller);
        $this->assertStringContainsString("\\App\\Models\\Product::query()->where('category_id', \$category->getKey())->count()", $controller);
        $this->assertStringContainsString("'1 product belongs to this category. Move or delete it first.'", $controller);
        $this->assertStringContainsString('products belong to this category. Move or delete them first."], 409);', $controller);

        $optional = Descriptor::fromSpec('Product', 'name:string, category:belongsTo(Category)?');
        $this->assertStringContainsString('public function destroy(Category $category): Response'."\n", $generator->controller($this->category(), [$this->category(), $optional]));
    }

    public function test_two_relations_to_the_same_resource_get_different_names(): void
    {
        $user = Descriptor::fromSpec('Member', 'name:string');
        $task = Descriptor::fromSpec('Task', 'title:string, owner:belongsTo(Member), reviewer:belongsTo(Member)?');
        $children = (new ResourceGenerator)->children($user, [$user, $task]);

        $this->assertSame(['tasks', 'tasksAsReviewer'], array_column($children, 'name'));
        $this->assertSame(['ownerId', 'reviewerId'], array_map(fn ($child) => $child['field']->name, $children));
    }

    public function test_a_file_comes_back_with_its_details_beside_its_key(): void
    {
        $resource = (new ResourceGenerator)->resource($this->product());

        $this->assertStringContainsString("'image' => \$this->image,", $resource);
        $this->assertStringContainsString("'imageFile' => \\Nevela\\Laravel\\Nevela::file(\$this->image),", $resource);
        $this->assertStringContainsString("'categoryId' => \$this->category_id,", $resource);
    }

    public function test_a_list_can_be_filtered_by_a_relation_but_not_by_a_file(): void
    {
        $query = ListQuery::parse($this->product(), ['filter' => ['categoryId' => 'abc', 'image' => 'x'], 'sort' => 'image']);

        $this->assertSame([['field' => 'categoryId', 'column' => 'category_id', 'value' => 'abc']], $query->filters);
        $this->assertSame(['sort', 'filter[image]'], array_column($query->issues, 'param'));
    }

    public function test_seeded_rows_draw_relations_and_files_from_what_exists(): void
    {
        $row = Fake::row(Descriptor::fromSpec('Product', 'name:string, image:image, category:belongsTo(Category)'), 1, ['categoryId' => ['c1', 'c2'], 'image' => ['products/image/a.webp']]);

        $this->assertContains($row['category_id'], ['c1', 'c2']);
        $this->assertSame('products/image/a.webp', $row['image']);
        // Nothing to draw from: left empty, not invented.
        $this->assertNull(Fake::row(Descriptor::fromSpec('Product', 'name:string, image:image'), 1)['image']);

        // A category is named like one, with a slug to match.
        $category = Fake::row($this->category(), 2);
        $this->assertSame(['Outdoor', 'outdoor-2'], [$category['name'], $category['slug']]);
    }
}
