<?php

namespace Nevela\Laravel\Support;

/**
 * Plausible rows for a descriptor, for `php artisan nevela:seed`.
 *
 * Pure PHP with no Faker: Laravel apps only install Faker for development, and a seed
 * that fills a staging database shouldn't depend on it. Values follow the same rules the
 * generated form request enforces (lengths, enum options, 0–100 percents, Y-m-d dates),
 * and a field's name is used as a hint, so `email`, `city` or `sku` look like what they are.
 */
final class Fake
{
    private const FIRST = ['Ada', 'Grace', 'Alan', 'Linus', 'Amara', 'Kofi', 'Mei', 'Ravi', 'Sofia', 'Omar', 'Nia', 'Lucas', 'Aisha', 'Mateo', 'Yuki', 'Zainab', 'Noah', 'Priya', 'Elena', 'Kwame'];

    private const LAST = ['Okafor', 'Nakamura', 'Silva', 'Mukasa', 'Haddad', 'Novak', 'Mensah', 'Patel', 'Garcia', 'Kim', 'Otieno', 'Rossi', 'Dubois', 'Khan', 'Andersson', 'Nkosi', 'Tanaka', 'Moreno', 'Ivanov', 'Abebe'];

    private const ADJECTIVES = ['Compact', 'Classic', 'Urban', 'Bright', 'Quiet', 'Rapid', 'Solid', 'Smart', 'Fresh', 'Prime', 'Royal', 'Swift', 'Amber', 'Coastal', 'Nordic', 'Golden'];

    private const NOUNS = ['Lamp', 'Chair', 'Kettle', 'Backpack', 'Notebook', 'Speaker', 'Bottle', 'Desk', 'Jacket', 'Camera', 'Grinder', 'Planter', 'Monitor', 'Keyboard', 'Blanket', 'Mug'];

    /** What a shop files things under: names for a Category, Collection, Department or Tag. */
    private const GROUPS = ['Kitchen', 'Outdoor', 'Office', 'Lighting', 'Audio', 'Travel', 'Home', 'Garden', 'Fitness', 'Accessories', 'Stationery', 'Bedroom', 'Toys', 'Tools', 'Beauty', 'Pets'];

    private const COMPANIES = ['Acme', 'Globex', 'Initech', 'Northwind', 'Brightline', 'Vandelay', 'Umbrella', 'Hooli', 'Stark', 'Wayne'];

    private const CITIES = ['Kampala', 'Nairobi', 'Lagos', 'Accra', 'London', 'Berlin', 'Toronto', 'Tokyo', 'Lisbon', 'Austin', 'Mumbai', 'Sydney'];

    private const COUNTRIES = ['Uganda', 'Kenya', 'Nigeria', 'Ghana', 'United Kingdom', 'Germany', 'Canada', 'Japan', 'Portugal', 'United States', 'India', 'Australia'];

    private const WORDS = ['reliable', 'handmade', 'everyday', 'lightweight', 'durable', 'refined', 'practical', 'seasonal', 'limited', 'popular', 'tested', 'trusted', 'simple', 'modern', 'original'];

    /** 2000-01-01 UTC, where unique dates start counting from. */
    private const EPOCH = 946684800;

    /** A century of distinct days. */
    private const UNIQUE_DAYS = 36500;

    private const COLORS = 0x1000000;

    /**
     * How many distinct values a unique field can hold, or null when it's as good as unlimited.
     * A unique enum of three options can only ever fill three rows; the seed command checks
     * this before it starts rather than failing on the fourth insert.
     */
    public static function capacity(Field $field): ?int
    {
        if (! $field->unique) {
            return null;
        }

        return match (true) {
            $field->kind === 'enum' => count($field->options),
            $field->kind === 'boolean' => 2,
            $field->kind === 'int' && $field->format === 'percent' => 101,
            $field->kind === 'int' && $field->format === 'rating' => 5,
            $field->kind === 'float' && $field->format === 'percent' => 1001,
            $field->kind === 'float' && $field->format === 'rating' => 41,
            $field->kind === 'date' => self::UNIQUE_DAYS,
            $field->format === 'color' => self::COLORS,
            default => null,
        };
    }

    /**
     * One row, keyed by column name (without id or timestamps).
     *
     * @param  int  $number  A number no other seeded row of this table shares: what keeps unique fields unique.
     * @param  array<string, list<string>>  $pools  Values to draw from, by field name: the ids a belongsTo may point at, the keys of files that exist.
     * @return array<string, string|int|float|bool|null>
     */
    public static function row(Descriptor $resource, int $number, array $pools = []): array
    {
        $first = self::pick(self::FIRST);
        $last = self::pick(self::LAST);
        $isPerson = isset($resource->fields['email']) || isset($resource->fields['firstName']);
        $isGroup = (bool) preg_match('/(Category|Collection|Department|Tag|Genre|Brand)$/', $resource->name);

        $row = [];
        foreach ($resource->fields as $field) {
            // Optional fields are left empty now and then, as real data is.
            if (! $field->required && ! $field->unique && mt_rand(1, 5) === 1) {
                $row[$field->column()] = null;

                continue;
            }
            // A relation or a file can't be invented: it has to be one that exists.
            if (in_array($field->kind, ['belongsTo', 'file'], true)) {
                $pool = $pools[$field->name] ?? [];
                $row[$field->column()] = $pool === [] ? null : self::pick($pool);

                continue;
            }
            $row[$field->column()] = self::value($field, $number, $first, $last, $isPerson);
        }

        // A category is called Kitchen, not Compact Lamp, and its slug follows its name.
        $name = $resource->fields['name'] ?? null;
        if ($isGroup && $name !== null && $name->kind === 'string' && $name->format === null) {
            $group = self::GROUPS[($number - 1) % count(self::GROUPS)];
            $row['name'] = $name->unique ? "{$group} {$number}" : $group;
            $slug = $resource->fields['slug'] ?? null;
            if ($slug !== null && $slug->format === 'slug' && isset($row['slug'])) {
                $row['slug'] = strtolower($group).($slug->unique ? "-{$number}" : '');
            }
        }

        return $row;
    }

    private static function value(Field $field, int $number, string $first, string $last, bool $isPerson): string|int|float|bool
    {
        return match ($field->kind) {
            // A unique field walks its range by number instead of drawing at random; capacity() says how far that goes.
            'enum' => $field->unique ? $field->options[$number % count($field->options)] : self::pick($field->options),
            'boolean' => $field->unique ? $number % 2 === 0 : mt_rand(1, 10) > 3,
            'int' => match (true) {
                $field->format === 'percent' => $field->unique ? $number % 101 : mt_rand(0, 100),
                $field->format === 'rating' => $field->unique ? 1 + $number % 5 : mt_rand(1, 5),
                $field->unique => $number,
                default => mt_rand(0, 500),
            },
            'float' => match (true) {
                $field->format === 'percent' => round(($field->unique ? $number % 1001 : mt_rand(0, 1000)) / 10, 1),
                $field->format === 'rating' => round(($field->unique ? 10 + $number % 41 : mt_rand(10, 50)) / 10, 1),
                $field->unique => round($number + mt_rand(0, 99) / 100, 2),
                default => round(mt_rand(199, 49999) / 100, 2),
            },
            'date' => $field->unique
                ? gmdate('Y-m-d', self::EPOCH + ($number % self::UNIQUE_DAYS) * 86400)
                : date('Y-m-d', time() - mt_rand(-30, 365) * 86400),
            'datetime' => $field->unique
                ? gmdate('Y-m-d H:i:s', self::EPOCH + $number)
                : date('Y-m-d H:i:s', time() - mt_rand(0, 365 * 86400)),
            'text' => self::sentence(mt_rand(8, 24)).($field->unique ? " {$number}" : ''),
            default => self::string($field, $number, $first, $last, $isPerson),
        };
    }

    private static function string(Field $field, int $number, string $first, string $last, bool $isPerson): string
    {
        $name = strtolower($field->name);

        $value = match (true) {
            // Always numbered: two people called Ada Okafor must not share an address.
            $field->format === 'email' || str_contains($name, 'email') => strtolower("{$first}.{$last}{$number}@example.com"),
            $field->format === 'url' || str_contains($name, 'website') || str_ends_with($name, 'url') => 'https://example.com/'.strtolower(self::pick(self::NOUNS)).($field->unique ? "-{$number}" : ''),
            $field->format === 'tel' || str_contains($name, 'phone') => $field->unique
                ? '+256 7'.str_pad((string) $number, 10, '0', STR_PAD_LEFT)
                : sprintf('+256 7%02d %03d %03d', mt_rand(0, 99), mt_rand(0, 999), mt_rand(0, 999)),
            $field->format === 'slug' => strtolower(self::pick(self::ADJECTIVES).'-'.self::pick(self::NOUNS)).($field->unique ? "-{$number}" : ''),
            $field->format === 'color' => sprintf('#%06x', $field->unique ? $number % self::COLORS : mt_rand(0, 0xFFFFFF)),
            in_array($name, ['sku', 'code', 'reference', 'ref', 'number'], true) => strtoupper(substr(self::pick(self::NOUNS), 0, 3)).'-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT),
            $name === 'firstname' => $first,
            $name === 'lastname' => $last,
            in_array($name, ['name', 'fullname'], true) && $isPerson => "{$first} {$last}",
            str_contains($name, 'company') || str_contains($name, 'organisation') || str_contains($name, 'organization') => self::pick(self::COMPANIES).' '.self::pick(['Ltd', 'Inc', 'Group', 'Labs']),
            str_contains($name, 'city') => self::pick(self::CITIES),
            str_contains($name, 'country') => self::pick(self::COUNTRIES),
            in_array($name, ['name', 'title', 'label'], true) => self::pick(self::ADJECTIVES).' '.self::pick(self::NOUNS),
            default => ucfirst(self::sentence(mt_rand(2, 4), false)),
        };

        // Emails, URLs, slugs, codes and phone numbers end in the number already, and a colour
        // is derived from it; everything else gets it appended.
        if ($field->unique && $field->format !== 'color' && ! preg_match('/'.$number.'(@|$)/', $value)) {
            $value .= " {$number}";
        }

        return $value;
    }

    private static function sentence(int $words, bool $stop = true): string
    {
        $picked = [];
        for ($i = 0; $i < $words; $i++) {
            $picked[] = self::pick(self::WORDS);
        }

        return ucfirst(implode(' ', $picked)).($stop ? '.' : '');
    }

    /**
     * @template T
     *
     * @param  list<T>  $from
     * @return T
     */
    private static function pick(array $from): mixed
    {
        return $from[mt_rand(0, count($from) - 1)];
    }
}
