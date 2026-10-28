<?php

namespace Nevela\Laravel\Support;

use Illuminate\Support\Facades\DB;

/**
 * The next number of a numbered field: INV-0001, INV-0002, and on past INV-9999 to INV-10000.
 *
 * Worked out from the numbers already given, the trash's included, so a number is never
 * given twice. Two records made at the same instant can be given the same one; the
 * column is unique, so the second is refused rather than saved with it.
 */
final class Numbering
{
    /** How many digits a number has at least. */
    public const DIGITS = 4;

    /** @param array<string, true> $taken Numbers given to earlier rows of the same batch, not saved yet */
    public static function next(string $table, string $column, string $prefix, array $taken = []): string
    {
        $query = DB::table($table);
        $wrapped = $query->getGrammar()->wrap($column);
        // The longest first, then the highest of those: INV-10000 is after INV-9999.
        $given = $query->where($column, 'like', "{$prefix}-%")->orderByRaw("length({$wrapped}) desc")->orderBy($column, 'desc')->limit(50)->pluck($column)->all();
        $highest = 0;
        foreach ([...$given, ...array_keys($taken)] as $value) {
            if (preg_match('/^'.preg_quote($prefix, '/').'-(\d+)$/', (string) $value, $match)) {
                $highest = max($highest, (int) $match[1]);
            }
        }

        return self::format($prefix, $highest + 1);
    }

    public static function format(string $prefix, int $number): string
    {
        return $prefix.'-'.str_pad((string) $number, self::DIGITS, '0', STR_PAD_LEFT);
    }
}
