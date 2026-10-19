<?php

namespace Nevela\Laravel\Forms;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nevela\Laravel\Support\SchemaFacts;
use Throwable;

/**
 * What a public form turned away, counted by resource, reason and day.
 *
 * The dashboard shows it on the resource's page ("Blocked this week: 132"). Without it
 * a form that is well protected and a form nobody uses look the same from the inside.
 */
final class FormBlocks
{
    private const TABLE = 'nevela_form_blocks';

    /** What each reason is called where a person reads it. */
    public const LABELS = [
        'honeypot' => 'filled the hidden field',
        'forged' => 'no form token',
        'too_fast' => 'sent too fast',
        'expired' => 'form left open too long',
        'robot' => 'robot check',
        'hostname' => 'robot check solved on another site',
        'disposable' => 'throwaway address',
        'undeliverable' => 'address that takes no mail',
        'throttled' => 'too many from one address',
        'unconfigured' => 'robot check not set up',
        'unreachable' => 'robot check unreachable',
    ];

    /** One more of this reason, today. Never the reason a request fails: counting is not what the form is for. */
    public static function record(string $resource, string $reason): void
    {
        try {
            $day = now()->toDateString();
            $where = ['resource' => $resource, 'reason' => $reason, 'day' => $day];
            if (DB::table(self::TABLE)->where($where)->increment('count') === 0) {
                DB::table(self::TABLE)->insert($where + ['id' => (string) Str::uuid(), 'count' => 1]);
            }
        } catch (Throwable) {
            // The table isn't there yet (not migrated), or two requests made today's row at once.
        }
    }

    /**
     * How many were turned away in the last `$days` days, in all and by reason, most first.
     *
     * @return array{total: int, days: int, reasons: list<array{reason: string, label: string, count: int}>}
     */
    public static function summary(string $resource, int $days = 7): array
    {
        $reasons = [];
        try {
            if (SchemaFacts::hasTable(self::TABLE)) {
                $rows = DB::table(self::TABLE)
                    ->where('resource', $resource)
                    ->where('day', '>', now()->subDays($days)->toDateString())
                    ->selectRaw('reason, sum(count) as total')
                    ->groupBy('reason')
                    ->get();
                foreach ($rows as $row) {
                    $reasons[] = ['reason' => (string) $row->reason, 'label' => self::LABELS[$row->reason] ?? (string) $row->reason, 'count' => (int) $row->total];
                }
            }
        } catch (Throwable) {
            $reasons = [];
        }
        usort($reasons, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return ['total' => array_sum(array_column($reasons, 'count')), 'days' => $days, 'reasons' => $reasons];
    }
}
