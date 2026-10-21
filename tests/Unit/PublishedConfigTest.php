<?php

namespace Nevela\Laravel\Tests\Unit;

use Nevela\Laravel\Support\PublishedConfig;
use PHPUnit\Framework\TestCase;

final class PublishedConfigTest extends TestCase
{
    private const PACKAGE = [
        'prefix' => 'api',
        'middleware' => ['api', 'auth:sanctum'],
        'permissions' => [],
        'auth' => [
            'enabled' => true,
            'session' => ['access' => 15, 'idle' => 10080, 'absolute' => 43200, 'reuse_seconds' => 60],
            'social' => ['enabled' => false, 'google' => ['client_id' => null]],
        ],
        'rich_text' => [
            'tags' => ['p', 'a', 'strong'],
            'attributes' => ['a' => ['href']],
        ],
        'uploads' => [
            'disk' => 'public',
            'profiles' => [
                'default' => ['max' => [1600, 1600], 'quality' => 82, 'renditions' => ['thumb' => [400, 400, 'crop']]],
                'product' => ['quality' => 80, 'renditions' => ['thumb' => [300, 300, 'crop'], 'card' => [600, 600]]],
            ],
        ],
    ];

    public function test_a_section_the_app_has_keeps_the_settings_added_to_it_since(): void
    {
        // A file published before 0.10: an auth section with neither session nor social.
        $merged = PublishedConfig::merge(self::PACKAGE, ['auth' => ['enabled' => false]]);

        $this->assertFalse($merged['auth']['enabled']);
        $this->assertSame(self::PACKAGE['auth']['session'], $merged['auth']['session']);
        $this->assertSame(self::PACKAGE['auth']['social'], $merged['auth']['social']);
    }

    public function test_what_the_app_sets_wins_and_an_explicit_null_stays_null(): void
    {
        $merged = PublishedConfig::merge(self::PACKAGE, ['auth' => ['session' => ['idle' => null, 'access' => 5]]]);

        $this->assertNull($merged['auth']['session']['idle']);
        $this->assertSame(5, $merged['auth']['session']['access']);
        $this->assertSame(43200, $merged['auth']['session']['absolute']);
    }

    public function test_a_list_is_replaced_whole_not_merged_by_index(): void
    {
        $merged = PublishedConfig::merge(self::PACKAGE, ['middleware' => ['api'], 'rich_text' => ['tags' => ['p']]]);

        $this->assertSame(['api'], $merged['middleware']);
        $this->assertSame(['p'], $merged['rich_text']['tags']);
        $this->assertSame(['a' => ['href']], $merged['rich_text']['attributes']);
    }

    public function test_an_allowlist_the_app_narrowed_does_not_grow_again(): void
    {
        $merged = PublishedConfig::merge(self::PACKAGE, ['rich_text' => ['attributes' => ['abbr' => ['title']]]]);

        $this->assertSame(['abbr' => ['title']], $merged['rich_text']['attributes']);
    }

    public function test_an_image_profile_the_app_defines_is_its_own_and_the_others_stay(): void
    {
        $merged = PublishedConfig::merge(self::PACKAGE, ['uploads' => ['profiles' => [
            'product' => ['quality' => 70, 'renditions' => ['thumb' => [200, 200, 'crop']]],
            'gallery' => ['quality' => 90],
        ]]]);

        $profiles = $merged['uploads']['profiles'];
        $this->assertSame(['quality' => 70, 'renditions' => ['thumb' => [200, 200, 'crop']]], $profiles['product']);
        $this->assertSame(['quality' => 90], $profiles['gallery']);
        $this->assertSame(self::PACKAGE['uploads']['profiles']['default'], $profiles['default']);
    }

    public function test_the_apps_own_permissions_and_empty_sections(): void
    {
        $mine = ['reports' => ['name' => 'Reports', 'actions' => ['view']]];
        $this->assertSame($mine, PublishedConfig::merge(self::PACKAGE, ['permissions' => $mine])['permissions']);

        // An empty array where the package has a section adds nothing and removes nothing.
        $this->assertSame(self::PACKAGE['auth'], PublishedConfig::merge(self::PACKAGE, ['auth' => []])['auth']);
    }

    public function test_what_an_older_file_is_missing_is_named_once_per_section(): void
    {
        $missing = PublishedConfig::missing(self::PACKAGE, [
            'prefix' => 'api',
            'middleware' => ['api'],
            'permissions' => [],
            'auth' => ['enabled' => true],
            'rich_text' => ['tags' => ['p'], 'attributes' => []],
            'uploads' => ['disk' => 'public', 'profiles' => ['default' => []]],
        ]);

        $this->assertSame(['auth.session' => '0.12.0', 'auth.social' => '0.10.0'], $missing);
        $this->assertSame('auth.session (new in 0.12.0) and auth.social (new in 0.10.0)', PublishedConfig::describe($missing));
        $this->assertSame('trash (new in 0.6.0)', PublishedConfig::describe(['trash' => '0.6.0']));
        $this->assertSame('a, b and c', PublishedConfig::describe(['a' => null, 'b' => null, 'c' => null]));
        $this->assertSame([], PublishedConfig::missing(self::PACKAGE, self::PACKAGE));
    }
}
