<?php

namespace Nevela\Laravel\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One uploaded file. A record's file field holds the key; this row holds everything
 * else about it.
 *
 * @property string $key
 * @property string $disk
 * @property string $resource
 * @property string $field
 * @property string $name
 * @property string $mime
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property array<string, array{key: string, width: int, height: int, size: int}>|null $renditions
 * @property string|null $profile
 * @property bool $optimised
 * @property string|null $original_key
 * @property int|null $original_size
 */
class Upload extends Model
{
    use HasUuids;

    protected $table = 'nevela_uploads';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'renditions' => 'array',
            'optimised' => 'boolean',
            'original_size' => 'integer',
        ];
    }
}
