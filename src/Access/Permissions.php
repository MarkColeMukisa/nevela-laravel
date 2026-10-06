<?php

namespace Nevela\Laravel\Access;

use Nevela\Laravel\Nevela;

/**
 * Every permission this app understands, and the rules for matching a grant against one.
 *
 * A permission is `feature.action`: "products.view", "users.edit". The feature is a
 * resource's table, or one of the built-in ones (users, roles), or one the app adds in
 * config/nevela.php. The action is one of create, view, edit, delete.
 *
 * A role holds grants, and a grant may be a pattern:
 *
 *   *                  everything
 *   products.*         every action on products
 *   *.view             viewing everything
 *   @resources.*       every action on every resource, including ones added later
 *   @resources.view    viewing every resource
 *
 * The model, the names and the matching rules are Grit's (internal/authz), so a role means
 * the same thing in both.
 */
final class Permissions
{
    public const ACTIONS = ['create', 'view', 'edit', 'delete'];

    /** @var list<array{key: string, name: string, groups: list<array{key: string, name: string, features: list<array{key: string, name: string, actions: list<string>}>}>}>|null */
    private static ?array $catalog = null;

    /**
     * The catalog, shaped for the roles screen: modules, their groups, their features.
     * Only the feature and the action appear in a permission; the rest is for reading.
     *
     * @return list<array{key: string, name: string, groups: list<array{key: string, name: string, features: list<array{key: string, name: string, actions: list<string>}>}>}>
     */
    public static function catalog(): array
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }

        $taken = ['users' => true, 'roles' => true];
        $modules = [[
            'key' => 'access',
            'name' => 'Access',
            'groups' => [[
                'key' => 'identity',
                'name' => 'Identity',
                'features' => [
                    ['key' => 'users', 'name' => 'Users', 'actions' => self::ACTIONS],
                    ['key' => 'roles', 'name' => 'Roles and permissions', 'actions' => self::ACTIONS],
                ],
            ]],
        ]];

        // One feature per resource, under the heading it has in the sidebar.
        $groups = [];
        foreach (Nevela::all() as $descriptor) {
            if (isset($taken[$descriptor->table])) {
                continue; // a resource on the users or roles table is governed by the built-in feature
            }
            $taken[$descriptor->table] = true;
            $heading = trim((string) ($descriptor->group ?? '')) ?: 'Resources';
            $groups[$heading] ??= ['key' => self::slug($heading), 'name' => $heading, 'features' => []];
            $groups[$heading]['features'][] = ['key' => $descriptor->table, 'name' => $descriptor->pluralLabel, 'actions' => self::ACTIONS];
        }
        if ($groups !== []) {
            $modules[] = ['key' => 'resources', 'name' => 'Resources', 'groups' => array_values($groups)];
        }

        // The app's own: config('nevela.permissions') = ['reports' => ['name' => 'Reports', 'actions' => ['view']]].
        $custom = [];
        foreach ((array) config('nevela.permissions', []) as $key => $feature) {
            $key = is_string($key) ? $key : (string) ($feature['key'] ?? '');
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $key) || isset($taken[$key])) {
                continue;
            }
            $taken[$key] = true;
            $actions = array_values(array_intersect(self::ACTIONS, (array) ($feature['actions'] ?? self::ACTIONS)));
            $custom[] = ['key' => $key, 'name' => (string) ($feature['name'] ?? ucfirst(str_replace('_', ' ', $key))), 'actions' => $actions ?: self::ACTIONS];
        }
        if ($custom !== []) {
            $modules[] = ['key' => 'app', 'name' => 'App', 'groups' => [['key' => 'app', 'name' => 'App', 'features' => $custom]]];
        }

        return self::$catalog = $modules;
    }

    /** Forget the catalog, after the descriptors or the config change. */
    public static function forget(): void
    {
        self::$catalog = null;
    }

    /**
     * Every permission in the catalog, sorted.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        $keys = [];
        foreach (self::catalog() as $module) {
            foreach ($module['groups'] as $group) {
                foreach ($group['features'] as $feature) {
                    foreach ($feature['actions'] as $action) {
                        $keys[] = "{$feature['key']}.{$action}";
                    }
                }
            }
        }
        sort($keys);

        return $keys;
    }

    /** Whether `ability` has the shape of a permission, so the gate answers for it. */
    public static function isPermission(string $ability): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]*\.(create|view|edit|delete)$/', $ability);
    }

    /**
     * Whether any of the grants allows `key`.
     *
     * @param  list<string>  $grants
     */
    public static function granted(array $grants, string $key): bool
    {
        foreach ($grants as $grant) {
            if (is_string($grant) && self::matches($grant, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether one grant covers one permission.
     *
     * A pattern is checked to its end: "products.*" covers "products.view", and "*.view"
     * covers "products.view" and nothing else of products. Stopping at the first "*"
     * would turn "*.view" into "everything".
     */
    public static function matches(string $pattern, string $key): bool
    {
        if ($pattern === '*' || $pattern === $key) {
            return true;
        }
        $p = explode('.', $pattern);
        $k = explode('.', $key);
        if (count($p) !== 2 || count($k) !== 2) {
            return false;
        }
        [$feature, $action] = $p;

        if (str_starts_with($feature, '@')) {
            if (! in_array($k[0], self::featuresOf(substr($feature, 1)), true)) {
                return false;
            }
        } elseif ($feature !== '*' && $feature !== $k[0]) {
            return false;
        }

        return $action === '*' || $action === $k[1];
    }

    /**
     * The permissions a set of grants comes to, patterns resolved against the catalog.
     * Clients are given this, so nothing outside this class has to match a pattern.
     *
     * @param  list<string>  $grants
     * @return list<string>
     */
    public static function expand(array $grants): array
    {
        return array_values(array_filter(self::keys(), fn (string $key) => self::granted($grants, $key)));
    }

    /**
     * Whether the grants come to everything there is.
     *
     * @param  list<string>  $grants
     */
    public static function hasAll(array $grants): bool
    {
        return in_array('*', $grants, true) || count(self::expand($grants)) === count(self::keys());
    }

    /**
     * The requested grants that `held` does not cover. A pattern is covered only when
     * everything it comes to is; one that comes to nothing is not.
     *
     * This is the ceiling: nobody hands out more than they hold.
     *
     * @param  list<string>  $held
     * @param  list<string>  $requested
     * @return list<string>
     */
    public static function beyond(array $held, array $requested): array
    {
        if (in_array('*', $held, true)) {
            return [];
        }
        $beyond = [];
        foreach ($requested as $grant) {
            if ($grant === '*') {
                $beyond[] = $grant;
            } elseif (str_contains($grant, '*') || str_starts_with($grant, '@')) {
                $keys = self::expand([$grant]);
                $covered = $keys !== [];
                foreach ($keys as $key) {
                    $covered = $covered && self::granted($held, $key);
                }
                if (! $covered) {
                    $beyond[] = $grant;
                }
            } elseif (! self::granted($held, $grant)) {
                $beyond[] = $grant;
            }
        }

        return $beyond;
    }

    /**
     * Whether a grant is one the catalog understands: "*", a permission in it, or a pattern
     * that comes to at least one. A module pattern is accepted for a module with nothing in
     * it yet, because its point is to cover what is added later.
     */
    public static function understood(string $grant): bool
    {
        if ($grant === '*') {
            return true;
        }
        if (! preg_match('/^(\*|@?[a-z][a-z0-9_]*)\.(\*|create|view|edit|delete)$/', $grant)) {
            return false;
        }
        if (str_starts_with($grant, '@')) {
            return in_array(substr(explode('.', $grant)[0], 1), ['access', 'resources', 'app'], true);
        }

        return self::expand([$grant]) !== [];
    }

    /** @return list<string> */
    private static function featuresOf(string $module): array
    {
        $features = [];
        foreach (self::catalog() as $entry) {
            if ($entry['key'] !== $module) {
                continue;
            }
            foreach ($entry['groups'] as $group) {
                foreach ($group['features'] as $feature) {
                    $features[] = $feature['key'];
                }
            }
        }

        return $features;
    }

    private static function slug(string $heading): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($heading)), '-') ?: 'resources';
    }
}
