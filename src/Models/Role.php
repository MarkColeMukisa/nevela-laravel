<?php

namespace Nevela\Laravel\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A named set of grants. See Nevela\Laravel\Access\Permissions for what a grant is.
 *
 * Grants are kept as written, patterns included: a role holding "products.*" goes on
 * covering products when an action is added, which a list of the permissions it came to
 * on the day it was saved would not.
 *
 * @property string $id
 * @property string $name
 * @property string|null $description
 * @property list<string> $grants
 * @property bool $is_system
 */
class Role extends Model
{
    use HasUuids;

    /** The three every app starts with. They can be edited, and not renamed or deleted. */
    public const ADMIN = 'ADMIN';

    public const EDITOR = 'EDITOR';

    public const USER = 'USER';

    protected $table = 'nevela_roles';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    /**
     * The grants, as a list. A value that can't be read comes to no grants at all: this
     * decides who may do what, and the safe way to fail is to refuse.
     *
     * @return list<string>
     */
    public function getGrantsAttribute(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /** @param list<string>|null $value */
    public function setGrantsAttribute(mixed $value): void
    {
        $this->attributes['grants'] = json_encode(array_values(array_unique(array_filter((array) $value, 'is_string'))));
    }

    /**
     * The roles a new app is given.
     *
     * @return list<array{name: string, description: string, grants: list<string>}>
     */
    public static function defaults(): array
    {
        return [
            ['name' => self::ADMIN, 'description' => 'Full access to every feature and action.', 'grants' => ['*']],
            ['name' => self::EDITOR, 'description' => 'Works with the app\'s records. Sees who the users are, and manages neither users nor roles.', 'grants' => ['@resources.*', 'users.view']],
            ['name' => self::USER, 'description' => 'A standard account. Signs in and manages its own profile, and nothing else.', 'grants' => []],
        ];
    }
}
