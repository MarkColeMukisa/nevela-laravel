<?php

namespace Nevela\Laravel\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A passkey: the public half of a key pair whose private half never leaves the person's
 * device. Nothing here is secret.
 *
 * @property string $user_id
 * @property string|null $name
 * @property string $credential_id  base64url
 * @property string $public_key     PEM
 * @property int $counter
 * @property list<string>|null $transports
 * @property \Illuminate\Support\Carbon|null $last_used_at
 */
class Passkey extends Model
{
    use HasUuids;

    protected $table = 'nevela_passkeys';

    protected $guarded = [];

    protected $hidden = ['public_key'];

    protected function casts(): array
    {
        return [
            'counter' => 'integer',
            'transports' => 'array',
            'last_used_at' => 'datetime',
        ];
    }
}
