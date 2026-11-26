<?php

namespace Nevela\Laravel\Activity;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nevela\Laravel\Support\SchemaFacts;

/**
 * The activity log's hash chain (step 11, part 5), after Grit's audit chain.
 *
 * Each entry is written once and never changed. Its hash is an HMAC, under a key the database
 * doesn't hold, of the entry before's hash and the entry itself:
 *
 *     hash = HMAC-SHA256(key, prev_hash . "\n" . canonical(entry))
 *
 * so changing or deleting an entry breaks every hash from there on, and nobody can recompute
 * them without the key. A sealed head row keeps the newest entry, so deleting the newest shows
 * too; sealed anchors say where the chain began and where it starts again after a prune.
 *
 * The key is NEVELA_AUDIT_KEY, or one derived from APP_KEY. Each entry records which key
 * sealed it, so rotating APP_KEY (with APP_PREVIOUS_KEYS) or setting a key of your own later
 * leaves older entries verifiable.
 *
 * What it catches: anyone who can write to the database but doesn't hold the key (SQL
 * injection, someone with database access, a tampered copy put back). What it can't: someone
 * who holds the key, who can write the whole chain again; a database put back to an earlier
 * copy, which is a valid chain of its time; and entries written before the chain began.
 */
final class Chain
{
    public const HEAD = 'nevela_activity_head';

    public const ANCHORS = 'nevela_activity_anchors';

    /** How many entries verify() reads at a time. */
    private const CHUNK = 500;

    public static function ready(): bool
    {
        return SchemaFacts::hasTable(self::HEAD) && SchemaFacts::hasColumn(Activity::TABLE, 'seq');
    }

    /**
     * The keys this app holds, by id; the first is the one that seals.
     *
     * @return array<string, string>
     */
    public static function keys(): array
    {
        $keys = [];
        $own = (string) config('nevela.audit.key', '');
        if ($own !== '') {
            $keys[] = str_starts_with($own, 'base64:') ? (string) base64_decode(substr($own, 7), true) : $own;
        }
        foreach ([config('app.key'), ...(array) config('app.previous_keys', [])] as $appKey) {
            if (! is_string($appKey) || $appKey === '') {
                continue;
            }
            $raw = str_starts_with($appKey, 'base64:') ? (string) base64_decode(substr($appKey, 7), true) : $appKey;
            $keys[] = hash_hmac('sha256', 'nevela-activity-chain-v1', $raw, true);
        }
        $byId = [];
        foreach ($keys as $key) {
            if ($key !== '') {
                $byId[self::idOf($key)] ??= $key;
            }
        }

        return $byId;
    }

    /**
     * Append an entry: under the head's lock, at the next place, chained to the newest. The row
     * is everything but the chain's own columns; created_at is set here, as stored.
     *
     * @param  array<string, mixed>  $row
     */
    public static function append(array $row): void
    {
        $keys = self::keys();
        if ($keys === []) {
            throw new \RuntimeException('The activity log is sealed with APP_KEY or NEVELA_AUDIT_KEY, and neither is set.');
        }
        $keyId = (string) array_key_first($keys);
        $key = $keys[$keyId];

        DB::transaction(function () use ($row, $keyId, $key) {
            $head = DB::table(self::HEAD)->where('id', 1)->lockForUpdate()->first();
            $seq = (int) $head->seq + 1;
            $at = now()->format('Y-m-d H:i:s');
            if ((int) $head->seq === 0 && ! DB::table(self::ANCHORS)->where('kind', 'genesis')->exists()) {
                self::anchor('genesis', 1, '', 0, $at);
            }
            $entry = [...$row, 'seq' => $seq, 'created_at' => $at, 'updated_at' => $at, 'key_id' => $keyId, 'prev_hash' => (string) $head->hash];
            $entry['hash'] = hash_hmac('sha256', $entry['prev_hash']."\n".self::canonical((object) $entry), $key);
            DB::table(Activity::TABLE)->insert($entry);
            DB::table(self::HEAD)->where('id', 1)->update([
                'seq' => $seq,
                'hash' => $entry['hash'],
                'key_id' => $keyId,
                'seal' => hash_hmac('sha256', "head\n{$seq}\n{$entry['hash']}", $key),
            ]);
        }, 3);
    }

    /**
     * Remove the oldest entries, those before `$cutoff`, as a run from the start of the chain,
     * and record where it now starts. Never rewrites an entry that stays.
     */
    public static function prune(string $cutoff): int
    {
        if (! self::ready()) {
            return 0;
        }

        return DB::transaction(function () use ($cutoff) {
            $head = DB::table(self::HEAD)->where('id', 1)->lockForUpdate()->first();
            $firstKept = DB::table(Activity::TABLE)->whereNotNull('seq')->where('created_at', '>=', $cutoff)->min('seq');
            $last = $firstKept === null ? (int) $head->seq : (int) $firstKept - 1;
            $from = (int) DB::table(Activity::TABLE)->whereNotNull('seq')->min('seq');
            if ($last < 1 || $from === 0 || $last < $from) {
                return 0;
            }
            $hash = (string) DB::table(Activity::TABLE)->where('seq', $last)->value('hash');
            $removed = DB::table(Activity::TABLE)->whereNotNull('seq')->where('seq', '<=', $last)->delete();
            self::anchor('prune', $last + 1, $hash, $removed, now()->format('Y-m-d H:i:s'));

            return $removed;
        });
    }

    /**
     * Walk the chain from where it starts to the head.
     *
     * @return array{started: bool, ok: bool, checked: int, unverifiable: int, brokenAt: int|null, message: string}
     */
    public static function verify(): array
    {
        $result = fn (bool $ok, string $message, int $checked = 0, ?int $at = null, int $unknown = 0, bool $started = true) => [
            'started' => $started, 'ok' => $ok, 'checked' => $checked, 'unverifiable' => $unknown, 'brokenAt' => $at, 'message' => $message,
        ];
        if (! self::ready()) {
            return $result(true, 'The chain needs a migration: php nevela migrate', started: false);
        }
        $keys = self::keys();
        $anchors = DB::table(self::ANCHORS)->orderBy('seq')->get();
        foreach ($anchors as $anchor) {
            $key = $keys[$anchor->key_id] ?? null;
            if ($key !== null && ! hash_equals(self::anchorSeal($key, $anchor->kind, (int) $anchor->seq, $anchor->prev_hash, (int) $anchor->removed, $anchor->at), (string) $anchor->seal)) {
                return $result(false, "A {$anchor->kind} record of the chain was changed or forged.");
            }
        }
        $genesis = $anchors->firstWhere('kind', 'genesis');
        $head = DB::table(self::HEAD)->where('id', 1)->first();
        if ($genesis === null) {
            return DB::table(Activity::TABLE)->whereNotNull('hash')->exists() || (int) ($head->seq ?? 0) > 0
                ? $result(false, 'Sealed entries are there, but the record of where the chain began is gone.')
                : $result(true, 'Nothing sealed yet: the chain begins with the next change.', started: false);
        }
        // Entries outside the chain are from before it began; one from after it was put there by
        // hand. When it began: the genesis record, if this app can check its seal, else the oldest
        // entry in the chain (a genesis sealed with a key since dropped could have been moved).
        $began = isset($keys[$genesis->key_id])
            ? (string) $genesis->at
            : (string) (DB::table(Activity::TABLE)->whereNotNull('seq')->min('created_at') ?? $genesis->at);
        $forged = DB::table(Activity::TABLE)->whereNull('seq')->where('created_at', '>', $began)->orderBy('created_at')->first();
        if ($forged !== null) {
            return $result(false, "An entry from {$forged->created_at} has no seal: it was written into the database by hand.");
        }

        // Where the chain starts: the latest prune, or the beginning.
        $start = $anchors->where('kind', 'prune')->sortByDesc('seq')->first() ?? $genesis;
        $expected = (int) $start->seq;
        $prev = (string) $start->prev_hash;
        $checked = $unknown = 0;
        $cursor = $expected - 1;
        // A key this app doesn't hold is allowed only for the oldest entries: those sealed before
        // APP_KEY was rotated without keeping the old one. After an entry this app can check, one
        // it can't is an entry changed and given another key to hide it.
        $sawKnown = false;
        $startKnown = isset($keys[$start->key_id]);
        while (true) {
            $rows = DB::table(Activity::TABLE)->whereNotNull('seq')->where('seq', '>', $cursor)->orderBy('seq')->limit(self::CHUNK)->get();
            if ($rows->isEmpty()) {
                break;
            }
            foreach ($rows as $row) {
                $seq = (int) $row->seq;
                if ($seq !== $expected) {
                    return $result(false, $seq > $expected ? "Entries {$expected} to ".($seq - 1).' were deleted.' : "Entry {$seq} is out of place.", $checked, $seq, $unknown);
                }
                if ((string) $row->prev_hash !== $prev) {
                    return $result(false, "Entry {$seq} doesn't follow the one before it: an entry before it was changed or deleted.", $checked, $seq, $unknown);
                }
                $key = $keys[$row->key_id] ?? null;
                if ($key === null) {
                    if ($sawKnown) {
                        return $result(false, "Entry {$seq} was changed after it was written: it claims a key this app doesn't hold, after entries sealed with one it does.", $checked, $seq, $unknown);
                    }
                    $unknown++;
                } elseif (! hash_equals(hash_hmac('sha256', $prev."\n".self::canonical($row), $key), (string) $row->hash)) {
                    return $result(false, "Entry {$seq} was changed after it was written.", $checked, $seq, $unknown);
                } else {
                    // The record of where the chain starts can't claim a key it doesn't hold
                    // when the entry it starts at is under one this app does.
                    if (! $sawKnown && $checked === 0 && ! $startKnown) {
                        return $result(false, "The {$start->kind} record of the chain was changed or forged.", $checked, $seq, $unknown);
                    }
                    $sawKnown = true;
                }
                $prev = (string) $row->hash;
                $expected++;
                $checked++;
            }
            $cursor = $expected - 1;
        }

        // The newest: what the head says it is, under its seal.
        $headKey = $keys[$head->key_id ?? ''] ?? null;
        if ((int) $head->seq !== $expected - 1 || (string) $head->hash !== $prev) {
            return $result(false, 'The newest entries were deleted: the log ends at '.($expected - 1).' and should end at '.(int) $head->seq.'.', $checked, null, $unknown);
        }
        // Every write seals the head with the key that sealed its entry, and a prune records its
        // own start with the current key: a start no key here can check, with nothing after it
        // and a head this app sealed, was put there by hand.
        if ($checked === 0 && ! $startKnown && $headKey !== null && (int) $head->seq > 0) {
            return $result(false, "The {$start->kind} record of the chain was changed or forged.");
        }
        if ((int) $head->seq > 0 && ($headKey === null ? $sawKnown : ! hash_equals(hash_hmac('sha256', "head\n{$head->seq}\n{$head->hash}", $headKey), (string) $head->seal))) {
            return $result(false, 'The record of the newest entry was changed.', $checked, null, $unknown);
        }
        if ($checked > 0 && ! $sawKnown) {
            return $result(true, "{$checked} entries in order, but none can be checked here: they were sealed with a key this app doesn't hold (a copy of another app's database?).", $checked, null, $unknown);
        }

        return $result(true, $unknown > 0
            ? "{$checked} entries in order; the oldest {$unknown} were sealed with a key this app no longer holds, and can't be checked. Keep the old APP_KEY in APP_PREVIOUS_KEYS to check them."
            : "{$checked} entries, none changed or removed.", $checked, null, $unknown);
    }

    /** The entry, as hashed: its stored values, in a fixed order. */
    public static function canonical(object $row): string
    {
        $text = fn ($value) => $value === null ? null : (string) $value;

        return (string) json_encode([
            (int) $row->seq,
            $text($row->group_id ?? null),
            $text($row->user_id ?? null),
            (string) $row->action,
            (string) $row->resource,
            (int) $row->count,
            $text($row->record_ids ?? null),
            $text($row->label ?? null),
            (string) $row->created_at,
            (string) $row->key_id,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function anchor(string $kind, int $seq, string $prevHash, int $removed, string $at): void
    {
        $keys = self::keys();
        $keyId = (string) array_key_first($keys);
        DB::table(self::ANCHORS)->insert([
            'id' => (string) Str::uuid(),
            'kind' => $kind,
            'seq' => $seq,
            'prev_hash' => $prevHash,
            'removed' => $removed,
            'at' => $at,
            'key_id' => $keyId,
            'seal' => self::anchorSeal($keys[$keyId], $kind, $seq, $prevHash, $removed, $at),
        ]);
    }

    private static function anchorSeal(string $key, string $kind, int $seq, string $prevHash, int $removed, string $at): string
    {
        return hash_hmac('sha256', "anchor\n{$kind}\n{$seq}\n{$prevHash}\n{$removed}\n{$at}", $key);
    }

    private static function idOf(string $key): string
    {
        return substr(hash('sha256', 'nevela-key-id|'.$key), 0, 12);
    }
}
