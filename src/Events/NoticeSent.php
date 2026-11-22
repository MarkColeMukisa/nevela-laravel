<?php

namespace Nevela\Laravel\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Nevela\Laravel\Notices\Notice;

/**
 * Something went into people's bells (Notices::send). For whatever should follow it out of
 * the dashboard: a push to their phones, a message elsewhere.
 *
 *     Event::listen(NoticeSent::class, fn (NoticeSent $sent) => Push::send($sent->userIds, $sent->notice->title, $sent->notice->body, $sent->notice->url));
 *
 * Only once the work that caused it is saved: a notice from a transaction that is rolled
 * back is never told of. A listener that fails is reported, and never fails that work.
 */
final class NoticeSent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<string>  $userIds  Everyone it was written for
     */
    public function __construct(
        public readonly array $userIds,
        public readonly Notice $notice,
    ) {}
}
