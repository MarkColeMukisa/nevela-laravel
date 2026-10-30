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

    /** Currencies with no smaller unit (ISO 4217's minor unit 0), whose amounts are whole. */
    private const WHOLE_CURRENCIES = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    private const LAST = ['Okafor', 'Nakamura', 'Silva', 'Mukasa', 'Haddad', 'Novak', 'Mensah', 'Patel', 'Garcia', 'Kim', 'Otieno', 'Rossi', 'Dubois', 'Khan', 'Andersson', 'Nkosi', 'Tanaka', 'Moreno', 'Ivanov', 'Abebe'];

    private const ADJECTIVES = ['Compact', 'Classic', 'Urban', 'Bright', 'Quiet', 'Rapid', 'Solid', 'Smart', 'Fresh', 'Prime', 'Royal', 'Swift', 'Amber', 'Coastal', 'Nordic', 'Golden'];

    private const NOUNS = ['Lamp', 'Chair', 'Kettle', 'Backpack', 'Notebook', 'Speaker', 'Bottle', 'Desk', 'Jacket', 'Camera', 'Grinder', 'Planter', 'Monitor', 'Keyboard', 'Blanket', 'Mug'];

    /** What a shop files things under: names for a Category, Collection, Department or Tag. */
    private const GROUPS = ['Kitchen', 'Outdoor', 'Office', 'Lighting', 'Audio', 'Travel', 'Home', 'Garden', 'Fitness', 'Accessories', 'Stationery', 'Bedroom', 'Toys', 'Tools', 'Beauty', 'Pets'];

    private const COMPANIES = ['Acme', 'Globex', 'Initech', 'Northwind', 'Brightline', 'Vandelay', 'Umbrella', 'Hooli', 'Stark', 'Wayne', 'Bluewave', 'Kestrel', 'Lakeside', 'Meridian', 'Oakridge', 'Pinecrest', 'Redstone', 'Silverline', 'Summit', 'Tidewater'];

    /** What a school teaches: titles for a Course, Class, Module or Lesson. */
    private const COURSES = ['Biology 101', 'Algebra I', 'World History', 'Introduction to Programming', 'Chemistry 101', 'English Literature', 'Physics 101', 'Human Geography', 'Principles of Economics', 'Statistics', 'Art and Design', 'French for Beginners', 'Data Structures', 'Creative Writing', 'Music Theory', 'Environmental Science'];

    /** What a project done for a client is called. */
    private const PROJECTS = ['Stock system', 'School website', 'Payroll system', 'Online shop', 'Point of sale', 'Booking app', 'Clinic records', 'Delivery tracker', 'Customer portal', 'Mobile money checkout', 'Inventory dashboard', 'Fees collection', 'Staff intranet', 'Sales reports', 'Loyalty app', 'Fleet tracking', 'Hotel bookings', 'Library system', 'Field data app', 'Company website'];

    /** What a teacher teaches. */
    private const SUBJECTS = ['Mathematics', 'Biology', 'Chemistry', 'Physics', 'English', 'History', 'Geography', 'Computer Science', 'Economics', 'Art', 'Music', 'French'];

    /** How a company's name ends: Acme Ltd, Acme Group. */
    private const COMPANY_ENDINGS = ['Ltd', 'Inc', 'Group', 'Labs', '& Co', 'Partners'];

    private const STREETS = ['Station Road', 'Market Street', 'Lake View Road', 'Hill Lane', 'Garden Avenue', 'Church Road', 'Park Street', 'Industrial Road', 'Mill Street', 'Harbour Road'];

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
     * @param  string|null  $currency  The app's (nevela.currency), for a money field that names none
     * @return array<string, string|int|float|bool|null>
     */
    public static function row(Descriptor $resource, int $number, array $pools = [], ?string $currency = null): array
    {
        $first = self::pick(self::FIRST);
        $last = self::pick(self::LAST);
        $isPerson = isset($resource->fields['email']) || isset($resource->fields['firstName']);
        $isGroup = (bool) preg_match('/(Category|Collection|Department|Tag|Genre|Brand)$/', $resource->name);
        // A client with a company of its own is a person at that company, not the company.
        $isCompany = (bool) preg_match('/(Company|Organi[sz]ation|Business|Supplier|Vendor|Partner|Agency|Firm|Client|Employer)$/', $resource->name) && ! isset($resource->fields['company']);

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
                $row[$field->column()] = match (true) {
                    $pool === [] => null,
                    // A gallery of one to four different ones, written as its JSON column holds it.
                    $field->multiple => json_encode(array_slice(self::shuffled($pool), 0, min(count($pool), mt_rand(1, 4)))),
                    default => self::pick($pool),
                };

                continue;
            }
            $row[$field->column()] = self::value($field, $number, $first, $last, $isPerson, $field->currency ?? $currency);
        }

        // A category is called Kitchen and a company Acme Ltd, not Compact Lamp. Each is walked
        // in order, so a unique name needs its number only once the list has run out.
        $name = $resource->fields['name'] ?? null;
        if ($isCompany && $name !== null && $name->kind === 'string' && $name->format === null) {
            $index = $number - 1;
            $company = self::COMPANIES[$index % count(self::COMPANIES)].' '.self::COMPANY_ENDINGS[intdiv($index, count(self::COMPANIES)) % count(self::COMPANY_ENDINGS)];
            $row['name'] = $name->unique && $number > count(self::COMPANIES) * count(self::COMPANY_ENDINGS) ? "{$company} {$number}" : $company;
        }
        if ($isGroup && $name !== null && $name->kind === 'string' && $name->format === null) {
            $group = self::GROUPS[($number - 1) % count(self::GROUPS)];
            $row['name'] = $name->unique && $number > count(self::GROUPS) ? "{$group} {$number}" : $group;
            $slug = $resource->fields['slug'] ?? null;
            if ($slug !== null && $slug->format === 'slug' && isset($row['slug'])) {
                $row['slug'] = strtolower($group).($slug->unique && $number > count(self::GROUPS) ? "-{$number}" : '');
            }
        }

        // A project is a piece of work: a stock system, a school website.
        if (preg_match('/(Project|Job|Engagement)$/', $resource->name) && $name !== null && $name->kind === 'string' && $name->format === null) {
            $project = self::PROJECTS[($number - 1) % count(self::PROJECTS)];
            $row['name'] = $name->unique && $number > count(self::PROJECTS) ? "{$project} {$number}" : $project;
        }

        // A course is called Biology 101, with a code to match, BIO-104; a teacher teaches a subject.
        if (preg_match('/(Course|Class|Module|Lesson)$/', $resource->name)) {
            $title = $resource->fields['title'] ?? $resource->fields['name'] ?? null;
            if ($title !== null && $title->kind === 'string' && $title->format === null) {
                $course = self::COURSES[($number - 1) % count(self::COURSES)];
                $row[$title->column()] = $title->unique && $number > count(self::COURSES) ? "{$course} {$number}" : $course;
                $code = $resource->fields['code'] ?? null;
                if ($code !== null && $code->kind === 'string' && $code->format === null && $code->maxLength === null) {
                    $row[$code->column()] = strtoupper(substr((string) preg_replace('/[^A-Za-z]/', '', $course), 0, 3)).'-'.(100 + $number);
                }
            }
        }
        $subject = $resource->fields['subject'] ?? null;
        if (preg_match('/(Teacher|Tutor|Lecturer|Instructor)$/', $resource->name) && $subject !== null && $subject->kind === 'string' && $subject->format === null && isset($row[$subject->column()])) {
            $row[$subject->column()] = self::SUBJECTS[($number - 1) % count(self::SUBJECTS)].($subject->unique ? " {$number}" : '');
        }

        // Any other slug made from a field reads as that field does: Quiet Backpack is
        // quiet-backpack. Numbered when it has to be unique and what it is made from doesn't make
        // it so, and cut to fit its column, number and all.
        foreach ($resource->fields as $field) {
            $source = $field->from === null ? null : ($resource->fields[$field->from] ?? null);
            if ($field->format !== 'slug' || $source === null || ($isGroup && $field->name === 'slug' && $field->from === 'name') || ! isset($row[$field->column()]) || ! is_string($row[$source->column()] ?? null)) {
                continue;
            }
            $words = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($row[$source->column()])), '-');
            $suffix = $field->unique && ! $source->unique ? "-{$number}" : '';
            $room = max(1, ($field->maxLength ?? 255) - strlen($suffix));
            $row[$field->column()] = rtrim(substr($words === '' ? 'item' : $words, 0, $room), '-').$suffix;
        }

        // A one-off project has no billing cycle: a field is empty where it doesn't apply.
        // Last, so nothing above (a course's code, a company's name) fills one in again.
        foreach ($resource->fields as $field) {
            foreach ($field->when as $on => $values) {
                if (! Field::holds($row[$resource->fields[$on]->column()] ?? null, $values)) {
                    $row[$field->column()] = null;
                }
            }
        }

        return $row;
    }

    private static function value(Field $field, int $number, string $first, string $last, bool $isPerson, ?string $currency = null): string|int|float|bool
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
                // Shillings come in thousands, not cents: UGX 1,250,000, not UGX 349. A unique amount is its number, which every column of decimal(12, 2) holds.
                $field->format === 'money' && in_array($currency, self::WHOLE_CURRENCIES, true) => $field->unique ? (float) $number : (float) (mt_rand(10, 2000) * 5000),
                $field->format === 'percent' => round(($field->unique ? $number % 1001 : mt_rand(0, 1000)) / 10, 1),
                $field->format === 'rating' => round(($field->unique ? 10 + $number % 41 : mt_rand(10, 50)) / 10, 1),
                $field->unique => round($number + mt_rand(0, 99) / 100, 2),
                default => round(mt_rand(199, 49999) / 100, 2),
            },
            'date' => match (true) {
                $field->unique => gmdate('Y-m-d', self::EPOCH + ($number % self::UNIQUE_DAYS) * 86400),
                // Someone born last month is no student: between 16 and 60 years ago.
                str_contains(strtolower($field->name), 'birth') || strtolower($field->name) === 'dob' => date('Y-m-d', time() - mt_rand(16 * 365, 60 * 365) * 86400),
                default => date('Y-m-d', time() - mt_rand(-30, 365) * 86400),
            },
            'datetime' => $field->unique
                ? gmdate('Y-m-d H:i:s', self::EPOCH + $number)
                : date('Y-m-d H:i:s', time() - mt_rand(0, 365 * 86400)),
            'text' => match (true) {
                $field->isRich() => self::article($field->unique ? " {$number}" : ''),
                // An address is a plot on a street in a city, not a sentence.
                str_contains(strtolower($field->name), 'address') => sprintf('Plot %d, %s, %s', $field->unique ? $number : mt_rand(1, 240), self::pick(self::STREETS), self::pick(self::CITIES)),
                default => self::sentence(mt_rand(8, 24)).($field->unique ? " {$number}" : ''),
            },
            default => self::string($field, $number, $first, $last, $isPerson),
        };
    }

    /** A few paragraphs with a heading and a list, so a seeded record reads like one somebody wrote. */
    private static function article(string $suffix): string
    {
        $items = implode('', array_map(fn () => '<li>'.ucfirst(self::sentence(mt_rand(3, 6))).'</li>', range(1, 3)));

        return '<p>'.self::sentence(mt_rand(14, 24)).'</p>'
            .'<h2>'.rtrim(self::sentence(mt_rand(3, 5)), '.').$suffix.'</h2>'
            .'<p>'.self::sentence(mt_rand(12, 20)).' <strong>'.rtrim(self::sentence(mt_rand(2, 4)), '.').'</strong>, '.lcfirst(self::sentence(mt_rand(6, 10))).'</p>'
            ."<ul>{$items}</ul>"
            .'<p>'.self::sentence(mt_rand(10, 18)).'</p>';
    }

    private static function string(Field $field, int $number, string $first, string $last, bool $isPerson): string
    {
        $name = strtolower($field->name);

        if ($field->auto !== null) {
            return Numbering::format($field->auto, $number);
        }
        $value = match (true) {
            // Always numbered: two people called Ada Okafor must not share an address.
            $field->format === 'email' || str_contains($name, 'email') => strtolower("{$first}.{$last}{$number}@example.com"),
            $field->format === 'url' || str_contains($name, 'website') || str_ends_with($name, 'url') => 'https://example.com/'.strtolower(self::pick(self::NOUNS)).($field->unique ? "-{$number}" : ''),
            // Seeded straight into the table, so already in the one form a country's number is kept in.
            $field->country !== null => Phone::fake($field->country, $number, $field->unique),
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

    /**
     * @param  list<mixed>  $from
     * @return list<mixed>
     */
    private static function shuffled(array $from): array
    {
        shuffle($from);

        return $from;
    }
}
