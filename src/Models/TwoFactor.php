<?php

namespace Nevela\Laravel\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One person's second step for signing in.
 *
 * The authenticator app's secret and the backup codes are encrypted with the app key:
 * a copy of the database alone is not enough to produce a code. Backup codes are also
 * only kept as hashes.
 *
 * @property string $user_id
 * @property string|null $secret
 * @property \Illuminate\Support\Carbon|null $totp_confirmed_at
 * @property list<string>|null $backup_codes
 * @property \Illuminate\Support\Carbon|null $enabled_at
 */
class TwoFactor extends Model
{
    use HasUuids;

    protected $table = 'nevela_two_factor';

    protected $guarded = [];

    protected $hidden = ['secret', 'backup_codes'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'backup_codes' => 'encrypted:array',
            'totp_confirmed_at' => 'datetime',
            'enabled_at' => 'datetime',
        ];
    }

    /** Whether codes from an authenticator app are set up and confirmed. */
    public function usesAuthenticator(): bool
    {
        return $this->secret !== null && $this->totp_confirmed_at !== null;
    }
}
