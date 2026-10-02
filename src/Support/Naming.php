<?php

namespace Nevela\Laravel\Support;

/**
 * Name conversions, kept free of Laravel so the generator can be tested on its own.
 * Pluralisation mirrors Flare's core (naming.ts) for the common cases; descriptors store
 * `table` and `slug` explicitly, so the two sides never have to agree on edge cases.
 */
final class Naming
{
    private const IRREGULAR = [
        'person' => 'people', 'child' => 'children', 'man' => 'men', 'woman' => 'women',
        'mouse' => 'mice', 'goose' => 'geese', 'tooth' => 'teeth', 'foot' => 'feet',
    ];

    private const UNCOUNTABLE = ['equipment', 'information', 'money', 'news', 'series', 'species', 'data', 'feedback'];

    /** @return list<string> */
    public static function words(string $value): array
    {
        $value = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $value);
        $value = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $value);
        $parts = preg_split('/[^A-Za-z0-9]+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY);

        return array_values($parts);
    }

    public static function snake(string $value): string
    {
        return implode('_', self::words($value));
    }

    public static function kebab(string $value): string
    {
        return implode('-', self::words($value));
    }

    public static function pascal(string $value): string
    {
        return implode('', array_map('ucfirst', self::words($value)));
    }

    public static function camel(string $value): string
    {
        return lcfirst(self::pascal($value));
    }

    public static function humanize(string $value): string
    {
        return ucfirst(implode(' ', self::words($value)));
    }

    public static function plural(string $value): string
    {
        if (! preg_match('/([A-Za-z]+)$/', $value, $m)) {
            return $value;
        }
        $word = $m[1];
        $lower = strtolower($word);
        $head = substr($value, 0, strlen($value) - strlen($word));
        $keepCase = fn (string $p) => ctype_upper($word[0]) ? ucfirst($p) : $p;

        if (in_array($lower, self::UNCOUNTABLE, true)) {
            return $value;
        }
        if (isset(self::IRREGULAR[$lower])) {
            return $head.$keepCase(self::IRREGULAR[$lower]);
        }
        if (preg_match('/[^aeiou]y$/i', $word)) {
            return $head.substr($word, 0, -1).'ies';
        }
        if (preg_match('/(s|x|z|ch|sh)$/i', $word)) {
            return $head.$word.'es';
        }

        return $head.$word.'s';
    }
}
