<?php

namespace Nevela\Laravel\Http;

use Closure;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Nevela\Laravel\Auth\Account;

/**
 * The throttle on a resource's public routes: Laravel's own, counting the right thing.
 *
 * A public page or form is usually reached through the web app, whose server makes the
 * call. Counted as they arrive, every visitor would be that one server: ten enquiries a
 * minute for the whole world, and a busy page would lock itself out. So, for a request
 * that proves it comes from the web app (the secret the two share, as for the list of
 * signed-in devices):
 *
 * - one made for a visitor is counted against that visitor's address, which the web app
 *   passes on as X-Nevela-Client. It is the address the proxy in front of the web app saw,
 *   not the one the request claimed for itself: a visitor who could choose it could have
 *   a fresh allowance with every request. Where the web app can't tell (it says
 *   "unknown"), visitors are counted together, from the web app's own address;
 * - one made for nobody in particular, such as a page being built to be cached and
 *   shown to many, is the site reading its own data and is not counted. Such a request
 *   carries the secret and names no visitor at all.
 *
 * Anything else is counted against the address it came from, as ever.
 */
final class PublicThrottle extends ThrottleRequests
{
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        if (Account::fromDashboard($request)) {
            $visitor = $request->header('X-Nevela-Client');
            if ($visitor === null) {
                // Only on the strength of the secret: "from this machine, in development" is
                // enough to believe an address, and not enough to stop counting.
                if ((string) config('nevela.auth.proxy_secret', '') !== '') {
                    return $next($request);
                }
            } elseif (filter_var($visitor, FILTER_VALIDATE_IP)) {
                $request->server->set('REMOTE_ADDR', $visitor);
            }
        }

        return parent::handle($request, $next, $maxAttempts, $decayMinutes, $prefix);
    }
}
