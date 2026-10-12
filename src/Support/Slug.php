<?php

namespace Nevela\Laravel\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A slug nobody typed: made from another field, and made different from every one
 * already in the table.
 *
 * "Opening day" is `opening-day`; the next post of that name is `opening-day-2`. Records
 * in the trash are counted, because they still hold their value and can come back with it.
 */
final class Slug
{
    /** Room left at the end of the column's 255 for the "-12" that makes one different. */
    private const LONGEST = 240;

    /**
     * @param  array<string, true>  $taken  Values spoken for that the table doesn't hold yet: earlier rows of the same batch. The one returned is added.
     */
    public static function free(string $table, string $column, string $from, array &$taken = []): string
    {
        $base = Str::limit(Str::slug($from), self::LONGEST, '');
        // A title with nothing a slug can keep (all symbols, say) still needs an address.
        $base = trim($base, '-') !== '' ? trim($base, '-') : 'untitled';

        $held = $taken;
        // Straight from the table, past the model's scopes: a record in the trash holds its slug too.
        foreach (DB::table($table)->where($column, $base)->orWhere($column, 'like', $base.'-%')->pluck($column) as $existing) {
            $held[(string) $existing] = true;
        }

        $slug = $base;
        for ($n = 2; isset($held[$slug]); $n++) {
            $slug = "{$base}-{$n}";
        }
        $taken[$slug] = true;

        return $slug;
    }
}
