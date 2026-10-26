<?php

namespace Nevela\Laravel\Notices;

use Closure;

/**
 * One thing to tell people in the dashboard's bell. See Notices::send().
 *
 *     new Notice('New enquiry from Ada', url: '/dashboard/enquiries/42',
 *         group: 'enquiries', many: fn (int $count) => "{$count} new enquiries", manyUrl: '/dashboard/enquiries')
 */
final class Notice
{
    /**
     * @param  string  $title  What happened, in a few words.
     * @param  string|null  $body  A line more, when the title isn't enough.
     * @param  string|null  $url  Where it is in the dashboard: "/dashboard/products/42".
     * @param  string  $tone  "info", or "warning" for something that needs doing.
     * @param  string  $kind  What it is about, for code that reads them: "form.received".
     * @param  string|null  $group  Notices with the same group are one while unread, with a count.
     * @param  (Closure(int): string)|null  $many  The title once there are several of the group.
     * @param  string|null  $manyUrl  Where several lead: the list rather than the latest record.
     */
    public function __construct(
        public readonly string $title,
        public readonly ?string $body = null,
        public readonly ?string $url = null,
        public readonly string $tone = 'info',
        public readonly string $kind = 'app',
        public readonly ?string $group = null,
        public readonly ?Closure $many = null,
        public readonly ?string $manyUrl = null,
    ) {}
}
