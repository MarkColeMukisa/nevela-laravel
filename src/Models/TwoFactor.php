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

    /**
     * Accept a code from the authenticator app, once. False when the code is wrong, or
     * when it is one that was already used (or older than one that was).
     */
    public function spendCode(string $code): bool
    {
        $step = $this->secret === null ? null : \Nevela\Laravel\Auth\Totp::step((string) $this->secret, $code);
        if ($step === null) {
            return false;
        }
        // One statement decides it, so two requests with the same code can't both win.
        $taken = static::query()->whereKey($this->getKey())
            ->where(fn ($query) => $query->whereNull('totp_last_step')->orWhere('totp_last_step', '<', $step))
            ->update(['totp_last_step' => $step]);

        return $taken === 1;
    }

    /** Whether codes from an authenticator app are set up and confirmed. */
    public function usesAuthenticator(): bool
    {
        return $this->secret !== null && $this->totp_confirmed_at !== null;
    }
}
