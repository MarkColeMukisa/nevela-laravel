<?php

namespace Nevela\Laravel\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;

/**
 * A job that does nothing but say it ran, for `nevela deploy:check` to see whether a queue
 * worker is taking jobs. One left waiting because there is no worker does no harm when a
 * worker does come: it only notes that it ran.
 */
final class QueueProbe implements ShouldQueue
{
    public function __construct(public string $token) {}

    public static function key(string $token): string
    {
        return "nevela:queue-probe:{$token}";
    }

    public function handle(): void
    {
        Cache::put(self::key($this->token), true, 600);
    }
}
