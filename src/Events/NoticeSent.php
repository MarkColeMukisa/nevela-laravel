<?php

namespace Nevela\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Nevela\Laravel\Notices\Notice;

/**
 * Something went into people's bells (Notices::send). For whatever should follow it out of
 * the dashboard: a push to their phones, a message elsewhere.
 *
 *     Event::listen(NoticeSent::class, fn (NoticeSent $sent) => Push::send($sent->userIds, $sent->title, $sent->body, $sent->url));
 *
 * Plain values only, so a listener can be queued (ShouldQueue). Sent once the work that
 * caused it is saved: a notice from a transaction that is rolled back is never told of.
 * A listener that fails is reported, and never fails that work.
 */
final class NoticeSent
{
    use Dispatchable;

    /**
     * @param  list<string>  $userIds  Everyone it was written for
     */
    public function __construct(
        public readonly array $userIds,
        public readonly string $title,
        public readonly ?string $body,
        public readonly ?string $url,
        public readonly string $tone,
        public readonly string $kind,
        public readonly ?string $group,
    ) {}

    /** @param list<string> $userIds */
    public static function of(array $userIds, Notice $notice): self
    {
        return new self($userIds, $notice->title, $notice->body, $notice->url, $notice->tone, $notice->kind, $notice->group);
    }
}
