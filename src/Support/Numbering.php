<?php

namespace Nevela\Laravel\Support;

use Illuminate\Support\Facades\DB;

/**
 * The next number of a numbered field: INV-0001, INV-0002, and on past INV-9999 to INV-10000.
 *
 * Each is given once, whoever else is making a record at that moment: the row that holds
 * the last one given is locked while the next is worked out. The numbers in the table count
 * too, the trash's included, because seeding and numbers typed in don't pass through here.
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

    public static function next(string $table, string $column, string $prefix): string
    {
        // Upgraded and not yet migrated: as it was before there was a table for this.
        if (! SchemaFacts::hasTable(self::TABLE)) {
            return self::format($prefix, self::highest($table, $column, $prefix) + 1);
        }
        $name = "{$table}.{$column}.{$prefix}";

        return DB::transaction(function () use ($table, $column, $prefix, $name): string {
            // A write first, even one that changes nothing: SQLite has no row locks, and
            // this is what makes the others wait there.
            DB::table(self::TABLE)->insertOrIgnore(['name' => $name, 'last' => 0]);
            $last = (int) DB::table(self::TABLE)->where('name', $name)->lockForUpdate()->value('last');
            $number = max($last, self::highest($table, $column, $prefix)) + 1;
            DB::table(self::TABLE)->where('name', $name)->update(['last' => $number]);

            return self::format($prefix, $number);
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

    /** The highest number with this prefix in the table, the trash's included. */
    private static function highest(string $table, string $column, string $prefix): int
    {
        $query = DB::table($table);
        $wrapped = $query->getGrammar()->wrap($column);
        // The longest first, then the highest of those: INV-10000 is after INV-9999.
        $given = $query->where($column, 'like', "{$prefix}-%")->orderByRaw("length({$wrapped}) desc")->orderBy($column, 'desc')->limit(50)->pluck($column)->all();
        $highest = 0;
        foreach ($given as $value) {
            if (preg_match('/^'.preg_quote($prefix, '/').'-(\d+)$/', (string) $value, $match)) {
                $highest = max($highest, (int) $match[1]);
            }
        }

        return $highest;
    }
}
