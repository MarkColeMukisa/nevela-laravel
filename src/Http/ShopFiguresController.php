<?php

namespace Nevela\Laravel\Http;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Nevela\Laravel\Access\Visibility;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Support\ShopFigures;

/**
 * GET _nevela/shop-figures: the shop at a glance, for the top of the home screen.
 *
 *   { orders: { slug, label, pluralLabel }, today: { orders, sameTimeLastWeek },
 *     pending, pendingStatus, statusField, sales: { total, week, lastWeek }, currency }
 *
 * Only in an app with a shop (an order whose lines point at what is sold); 404 NO_SHOP
 * otherwise. Counted over the orders this person may see, as their list is. Today starts at
 * midnight in the app's timezone, and is compared with last week's same day up to the same
 * time, not with a whole day. A week is the last seven days, against the seven before.
 * Cancelled orders sold nothing. Without a money field there are no sales, and without a
 * status nothing is pending: both are then null.
 */
final class ShopFiguresController
{
    public function __invoke(Request $request): JsonResponse
    {
        $shop = Nevela::shop();
        $model = $shop === null ? null : "App\\Models\\{$shop['orders']->name}";
        if ($model === null || ! class_exists($model)) {
            return response()->json(['error' => 'This app has no shop.', 'code' => 'NO_SHOP'], 404);
        }
        Gate::authorize('viewAny', $model);

        $orders = $shop['orders'];
        $fields = ShopFigures::fieldsOf($orders);
        $base = fn (): Builder => Visibility::narrow($model::query(), $request->user());
        $now = now();
        $today = $now->copy()->startOfDay();
        $weekAgo = $now->copy()->subDays(7);

        $sales = null;
        if ($fields['total'] !== null) {
            $column = $orders->fields[$fields['total']]->column();
            $sold = function (?\DateTimeInterface $from = null, ?\DateTimeInterface $until = null) use ($base, $fields, $orders, $column): float {
                $query = $base();
                if ($fields['status'] !== null && $fields['cancelled'] !== []) {
                    $query->whereNotIn($orders->fields[$fields['status']]->column(), $fields['cancelled']);
                }
                if ($from !== null) {
                    $query->where('created_at', '>=', $from);
                }
                if ($until !== null) {
                    $query->where('created_at', '<', $until);
                }

                return round((float) $query->sum($column), 2);
            };
            $sales = ['total' => $sold(), 'week' => $sold($weekAgo), 'lastWeek' => $sold($now->copy()->subDays(14), $weekAgo)];
        }

        return response()->json([
            'orders' => ['slug' => $orders->slug, 'label' => $orders->label, 'pluralLabel' => $orders->pluralLabel],
            'today' => [
                'orders' => $base()->where('created_at', '>=', $today)->count(),
                'sameTimeLastWeek' => $base()->where('created_at', '>=', $today->copy()->subDays(7))->where('created_at', '<', $weekAgo)->count(),
            ],
            'pending' => $fields['status'] === null || $fields['pending'] === null ? null : $base()->where($orders->fields[$fields['status']]->column(), $fields['pending'])->count(),
            'pendingStatus' => $fields['pending'],
            // The field it is, for a link to the pending ones: filter[{statusField}]={pendingStatus}.
            'statusField' => $fields['status'],
            'sales' => $sales,
            'currency' => $fields['total'] === null ? null : ($orders->fields[$fields['total']]->currency ?? config('nevela.currency')),
        ]);
    }
}
