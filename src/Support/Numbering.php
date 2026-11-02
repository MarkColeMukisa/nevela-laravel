<?php

namespace Nevela\Laravel\Support;

use Illuminate\Support\Facades\DB;

/**
 * The next number of a numbered field: INV-0001, INV-0002, and on past INV-9999 to INV-10000.
 *
 * Each is given once, whoever else is making a record at that moment: the row that holds
 * the last one given is locked while the next is worked out. The numbers in the table count
 * too, the trash's included, because numbers typed in don't pass through here.
 *
 * A record that fails after being given a number leaves a gap, unless it was in a
 * transaction that is rolled back, which gives the number back. Inside a transaction the
 * lock lasts until it ends, so a long import holds up others making the same kind of record.
 */
final class Numbering
{
    /** How many digits a number has at least. */
    public const DIGITS = 4;

    private const TABLE = 'nevela_numbers';

    /** How many of the longest numbers are looked at together, to find the highest. */
    private const LOOKED_AT = 50;

    public static function next(string $table, string $column, string $prefix): string
    {
        return self::format($prefix, self::reserve($table, $column, $prefix) + 1);
    }

    /**
     * Speak for the next numbers: the ones after what this returns are the caller's, and
     * are given to nobody else.
     *
     * @param  bool  $look  Whether to look through the table for a higher number than the one kept. Someone asking again for more, who
     *                      has just looked, can leave it out: on a table of a million it is the slowest part of a batch of a seed.
     */
    public static function reserve(string $table, string $column, string $prefix, int $count = 1, bool $look = true): int
    {
        // Upgraded and not yet migrated: as it was before there was a table for this.
        if (! SchemaFacts::hasTable(self::TABLE)) {
            return self::highest($table, $column, $prefix);
        }
        $name = "{$table}.{$column}.{$prefix}";

        return DB::transaction(function () use ($table, $column, $prefix, $name, $count, $look): int {
            // A write first, even one that changes nothing: SQLite has no row locks, and
            // this is what makes the others wait there.
            DB::table(self::TABLE)->insertOrIgnore(['name' => $name, 'last' => 0]);
            $last = (int) DB::table(self::TABLE)->where('name', $name)->lockForUpdate()->value('last');
            $before = $look ? max($last, self::highest($table, $column, $prefix)) : $last;
            DB::table(self::TABLE)->where('name', $name)->update(['last' => $before + $count]);

            return $before;
        }, 3);
    }

    /** Start a table's numbers again: it has been emptied, or is gone. */
    public static function forget(string $table): void
    {
        if (SchemaFacts::hasTable(self::TABLE)) {
            DB::table(self::TABLE)->where('name', 'like', "{$table}.%")->delete();
        }
    }

    public static function format(string $prefix, int $number): string
    {
        return $prefix.'-'.str_pad((string) $number, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The highest number with this prefix in the table, the trash's included. Looked for
     * longest first, as INV-10000 is after INV-9999, and on until there is one: something
     * typed in with the same start, such as INV-2026-0001, is longer and isn't a number.
     */
    private static function highest(string $table, string $column, string $prefix): int
    {
        $highest = 0;
        for ($offset = 0; ; $offset += self::LOOKED_AT) {
            $query = DB::table($table);
            $wrapped = $query->getGrammar()->wrap($column);
            $given = $query->where($column, 'like', "{$prefix}-%")->orderByRaw("length({$wrapped}) desc")->orderBy($column, 'desc')->offset($offset)->limit(self::LOOKED_AT)->pluck($column)->all();
            foreach ($given as $value) {
                if (preg_match('/^'.preg_quote($prefix, '/').'-(\d+)$/', (string) $value, $match)) {
                    $highest = max($highest, (int) $match[1]);
                }
            }
            if ($highest > 0 || count($given) < self::LOOKED_AT) {
                return $highest;
            }
        }
    }
}
