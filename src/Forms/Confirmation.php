<?php

namespace Nevela\Laravel\Forms;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Nevela\Laravel\Auth\Web;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Trash\Trash;

/**
 * Proving an address belongs to whoever typed it: the descriptor's "confirm".
 *
 *     "create": { "fields": ["email"], "confirm": "email" }
 *
 * The record is saved unconfirmed and one link is emailed to the address. Following it
 * confirms the record. This is for a list people join, where the other checks aren't
 * enough: an address can be real, take mail and belong to someone who never asked.
 *
 * The link is sent through PublicMail::toVisitor(), so its limits apply: signing a
 * stranger up a hundred times sends them one email.
 */
final class Confirmation
{
    /** How long an address has to follow its link before the record is removed, in hours. */
    public static function hours(): int
    {
        return max(1, (int) config('nevela.public.confirm.hours', 48));
    }

    /** What the link carries: only this app could have written it, for this record and this address. */
    public static function hash(Descriptor $resource, mixed $id, string $address): string
    {
        return hash_hmac('sha256', $resource->slug.'|'.$id.'|'.strtolower(trim($address)), 'nevela-confirm|'.config('app.key'));
    }

    /** The page of the web app that confirms a record when it is opened. */
    public static function link(Descriptor $resource, mixed $id, string $address, ?Request $request = null): string
    {
        return Web::url((string) config('nevela.public.confirm.path', '/confirm'), [
            'list' => $resource->slug,
            'id' => (string) $id,
            'key' => self::hash($resource, $id, $address),
        ], $request);
    }

    /** Email the link. False when the address has been written to too often lately, and nothing went. */
    public static function send(Descriptor $resource, mixed $id, string $address, ?Request $request = null): bool
    {
        return PublicMail::toVisitor($address, new ConfirmAddress(
            (string) config('app.name', 'Nevela'),
            self::link($resource, $id, $address, $request),
            self::hours(),
        ));
    }

    /**
     * Remove the records whose link was never followed in time. Run each night with the trash.
     *
     * @return array<string, int> How many were removed, by resource name
     */
    public static function purge(): array
    {
        $removed = [];
        $cutoff = now()->subHours(self::hours());
        foreach (Nevela::all() as $resource) {
            $model = "App\\Models\\{$resource->name}";
            if ($resource->public?->confirm === null || ! class_exists($model) || ! method_exists($model, 'confirms') || ! $model::confirms()) {
                continue;
            }
            $count = 0;
            // Whether or not someone put it in the trash meanwhile: it was never anyone's record.
            $model::query()->withoutGlobalScopes()->whereNull('confirmed_at')->where('created_at', '<', $cutoff)->orderBy((new $model)->getKeyName())
                ->chunkById(200, function ($records) use (&$count) {
                    foreach ($records as $record) {
                        /** @var Model $record */
                        $count += Trash::purge($record) ? 1 : 0;
                    }
                });
            if ($count > 0) {
                $removed[$resource->name] = $count;
            }
        }

        return $removed;
    }
}
