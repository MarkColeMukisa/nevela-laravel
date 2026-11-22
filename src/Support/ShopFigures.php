<?php

namespace Nevela\Laravel\Support;

/**
 * Which of an order's fields the shop's figures are counted from, found in its descriptor.
 *
 * - The total: its money field, "total" or "amount" first. Without one there are no sales.
 * - The status: its enum called status, state or stage. Without one nothing is pending.
 * - Pending is the status an order starts with: its default, else "pending", else the first.
 * - Cancelled orders sold nothing: every status that starts with "cancel" (cancelled, canceled).
 */
final class ShopFigures
{
    private const STATUS = '/^(status|state|stage)$/i';

    private const TOTAL = '/^(total|amount|grandTotal)$/i';

    /** @return array{total: string|null, status: string|null, pending: string|null, cancelled: list<string>} */
    public static function fieldsOf(Descriptor $orders): array
    {
        $money = array_filter($orders->fields, fn (Field $f) => $f->kind === 'float' && $f->format === 'money');
        $total = array_key_first(array_filter($money, fn (Field $f, string $name) => preg_match(self::TOTAL, $name) === 1, ARRAY_FILTER_USE_BOTH)) ?? array_key_first($money);

        $status = array_key_first(array_filter($orders->fields, fn (Field $f, string $name) => $f->kind === 'enum' && preg_match(self::STATUS, $name) === 1, ARRAY_FILTER_USE_BOTH));
        $options = $status === null ? [] : $orders->fields[$status]->options;
        $default = $status === null ? null : $orders->fields[$status]->default;
        $pending = match (true) {
            $status === null => null,
            is_string($default) && in_array($default, $options, true) => $default,
            in_array('pending', $options, true) => 'pending',
            default => $options[0] ?? null,
        };
        $cancelled = array_values(array_filter($options, fn (string $o) => str_starts_with(strtolower($o), 'cancel')));

        return [
            'total' => $total === null ? null : (string) $total,
            'status' => $status === null ? null : (string) $status,
            'pending' => $pending,
            'cancelled' => $cancelled,
        ];
    }
}
