<?php

namespace Nevela\Laravel\Forms;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Nevela\Laravel\Auth\Web;
use Nevela\Laravel\Support\Descriptor;
use Throwable;

/**
 * What stands between a public form and whoever sends it: the descriptor's "protect".
 *
 *   "protect": { "turnstile": true, "honeypot": true, "minSeconds": 3, "email": "email" }
 *
 * The checks run in this order, cheapest first, before anything is validated or stored:
 *
 * 1. the honeypot: a field no person sees has a value;
 * 2. the time taken: the form came back sooner than a person could fill it in, or its
 *    token is missing, forged or too old;
 * 3. Turnstile: Cloudflare, asked by this server, doesn't vouch for the visitor;
 * 4. the address: its domain takes no mail, or is a throwaway one;
 * 5. the count for that address: one person, however many networks they send from.
 *
 * A resource with no "protect" passes straight through, as before there was any.
 */
final class FormGuard
{
    /** The form's own token, and Turnstile's, as a form sends them. Neither is a field of the resource. */
    public const TOKEN = '_form';

    public const TURNSTILE = '_turnstile';

    /** What a person is told when it can't be shown they are one. A person can get this, so it says what to do. */
    public const NOT_A_PERSON = "We couldn't confirm you're not a robot. Please try again.";

    /**
     * Names a bot fills in because they look like something a form wants. The first that
     * isn't a field of the form is used. None is something a browser keeps for a person
     * (a company, a second address line): filled in for them, it would turn them away.
     */
    private const BAIT = ['website', 'homepage', 'url', 'site', 'web'];

    /** Where a form is built, and never where it is live. */
    private const LOCAL = ['localhost', '127.0.0.1'];

    /** Asks whether a domain takes mail. Replaced in tests, which have no DNS to ask. @var (callable(string): bool)|null */
    public static $takesMail = null;

    /**
     * What a form needs before it is shown: the token to send back, the name of the field
     * to leave empty, and whether the robot check is on.
     *
     * @return array{token: string|null, honeypot: string|null, minSeconds: int, turnstile: bool, action: string|null}
     */
    public static function issue(Descriptor $resource): array
    {
        $protect = $resource->public?->protect ?? [];
        $timed = ($protect['honeypot'] ?? false) || ($protect['minSeconds'] ?? 0) > 0;

        return [
            'token' => $timed ? self::sign($resource->name, now()->getTimestamp()) : null,
            'honeypot' => ($protect['honeypot'] ?? false) ? self::honeypot($resource) : null,
            'minSeconds' => (int) ($protect['minSeconds'] ?? 0),
            // On only where the answer can be checked: see robot().
            'turnstile' => $robot = ($protect['turnstile'] ?? false) && self::robotCheck() === 'on',
            // What the widget is rendered with, and Cloudflare hands back: which form the check was for.
            'action' => $robot ? self::action($resource) : null,
        ];
    }

    /**
     * The name a form's robot check is made under. The widget is given it, Cloudflare
     * returns it with its answer, and the two must agree: a token solved for one form
     * can't be spent on another. (Cloudflare allows letters, digits, _ and -, up to 32.)
     */
    public static function action(Descriptor $resource): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9_-]/', '-', $resource->slug), 0, 32);
    }

    /**
     * The sites a robot check may have been solved on: TURNSTILE_HOSTNAMES, or failing that
     * the web app's own. A name stands for its subdomains too, as it does on the widget.
     *
     * A widget usually lists localhost beside the real site, so the form can be built. This
     * is what stops a token solved on someone's own machine being spent on the live form:
     * in production localhost is never accepted, whatever the setting says.
     *
     * @return list<string>
     */
    public static function hostnames(?Request $request = null): array
    {
        $set = config('nevela.public.turnstile.hostnames');
        $names = array_values(array_filter(array_map(
            fn ($name) => strtolower(trim((string) $name)),
            is_array($set) ? $set : explode(',', (string) $set),
        )));
        if ($names === []) {
            $names = [strtolower(Web::host($request))];
        }

        return app()->environment('production')
            ? array_values(array_diff($names, self::LOCAL))
            : array_values(array_unique([...$names, ...self::LOCAL]));
    }

    /**
     * Whether the robot check can be made here: "on"; "skipped", in development with no
     * secret key; or "refusing", in production with something it needs missing. A form that
     * asks for the check never goes without it quietly.
     */
    public static function robotCheck(): string
    {
        $production = app()->environment('production');
        if (self::secret() === '') {
            return $production ? 'refusing' : 'skipped';
        }

        return $production && self::hostnames() === [] ? 'refusing' : 'on';
    }

    /** The field a person never sees, for this form. */
    public static function honeypot(Descriptor $resource): string
    {
        foreach (self::BAIT as $name) {
            if (! isset($resource->fields[$name])) {
                return $name;
            }
        }

        return '_'.self::BAIT[0];
    }

    /**
     * The input without what the guard added to the form: what is left is the resource's own fields.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function strip(Descriptor $resource, array $input): array
    {
        if (! ($resource->public?->protected() ?? false)) {
            return $input;
        }
        unset($input[self::TOKEN], $input[self::TURNSTILE], $input['cf-turnstile-response'], $input[self::honeypot($resource)]);

        return $input;
    }

    /**
     * Whether to turn this one away, and why. Null lets it through to validation.
     *
     * @param  array<string, mixed>  $input
     */
    public static function check(Descriptor $resource, Request $request, array $input): ?Refusal
    {
        $public = $resource->public;
        if ($public === null || ! $public->protected()) {
            return null;
        }
        $protect = $public->protect;

        if ($protect['honeypot'] && self::filled($input[self::honeypot($resource)] ?? null)) {
            return new Refusal('honeypot');
        }

        if ($protect['honeypot'] || $protect['minSeconds'] > 0) {
            $issued = self::issuedAt($resource->name, $input[self::TOKEN] ?? null);
            if ($issued === null) {
                return new Refusal('forged');
            }
            $age = now()->getTimestamp() - $issued;
            if ($age < $protect['minSeconds']) {
                return new Refusal('too_fast');
            }
            // Left open for hours, by a person. The form asks for a new token after every
            // attempt, so trying again is all they need to do.
            if ($age > (int) config('nevela.public.form_lifetime', 7200)) {
                return new Refusal('expired', 422, self::NOT_A_PERSON);
            }
        }

        if ($protect['turnstile'] && ($refusal = self::robot($resource, $request, $input)) !== null) {
            return $refusal;
        }

        $field = $protect['email'];
        $address = $field !== null && is_string($input[$field] ?? null) ? strtolower(trim($input[$field])) : null;
        // An address that isn't one at all is validation's to say, in its own words.
        if ($address !== null && filter_var($address, FILTER_VALIDATE_EMAIL)) {
            $domain = substr($address, (int) strrpos($address, '@') + 1);
            if (Disposable::is($domain)) {
                return new Refusal('disposable', 422, 'Use an address you keep. This one is from a throwaway service.', $field);
            }
            if (! self::takesMail($address, $domain)) {
                return new Refusal('undeliverable', 422, "This address's domain doesn't receive email. Check the spelling.", $field);
            }

            // The route's throttle counts a visitor. This counts an address, wherever it is sent from.
            [$most, $minutes] = array_map('intval', explode(',', $public->createThrottle));
            $key = "nevela-form:{$resource->slug}:".sha1($address);
            if (RateLimiter::tooManyAttempts($key, $most)) {
                return new Refusal('throttled', 429, 'Too many requests. Try again shortly.');
            }
            RateLimiter::hit($key, $minutes * 60);
        }

        return null;
    }

    /**
     * Whether Cloudflare vouches for the visitor. It is asked from here, with the secret
     * only this server has: what the browser says about its own check is not evidence.
     *
     * With no secret set the check can't be made. In development it is skipped, so a form
     * can be built before the keys exist. In production the form is refused outright: a
     * form that asked for the check and quietly went without would be open, and nobody
     * would know.
     *
     * A yes from Cloudflare says a person solved a check for this widget. It is taken only
     * if it was solved on this site and for this form: see hostnames() and action().
     *
     * @param  array<string, mixed>  $input
     */
    private static function robot(Descriptor $resource, Request $request, array $input): ?Refusal
    {
        $secret = self::secret();
        $unconfigured = new Refusal('unconfigured', 503, "This form can't be sent at the moment. Please try again later.");
        $state = self::robotCheck();
        if ($state !== 'on') {
            return $state === 'refusing' ? $unconfigured : null;
        }
        $token = $input[self::TURNSTILE] ?? $input['cf-turnstile-response'] ?? null;
        $failed = new Refusal('robot', 422, self::NOT_A_PERSON);
        // (Cloudflare's tokens are at most 2048 characters. Anything longer is not one.)
        if (! is_string($token) || $token === '' || strlen($token) > 2048) {
            return $failed;
        }

        try {
            $response = Http::asForm()->timeout(8)->post((string) config('nevela.public.turnstile.verify_url', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'), [
                'secret' => $secret,
                'response' => $token,
                // The visitor's, where the web app passed it on: see Http\PublicThrottle.
                'remoteip' => $request->ip(),
            ]);
        } catch (Throwable) {
            $response = null;
        }
        // Cloudflare not answering is not a yes. The person is asked to try again; nothing is let through.
        if ($response === null || $response->serverError()) {
            return new Refusal('unreachable', 503, "We couldn't check the form just now. Please try again in a moment.");
        }

        // A token is good once: a second use is answered with "timeout-or-duplicate", which is a no.
        if ($response->json('success') !== true) {
            return $failed;
        }
        // The keys Cloudflare publishes for testing say yes to everything, name no form, and
        // call every site example.com. Good for building a form. On a live one they are no check at all.
        if ($response->json('metadata.result_with_testing_key') === true) {
            return app()->environment('production') ? $unconfigured : null;
        }

        $solvedOn = strtolower((string) $response->json('hostname'));
        $expected = self::hostnames($request);
        $here = false;
        foreach ($expected as $name) {
            $here = $here || $solvedOn === $name || str_ends_with($solvedOn, '.'.$name);
        }
        if (! $here) {
            // Most often a setting, not an attack: the site is served from a name this app wasn't told of.
            logger()->warning("Nevela refused a robot check solved on \"{$solvedOn}\". This app accepts: ".implode(', ', $expected).'. If that is your site, add it to TURNSTILE_HOSTNAMES or correct NEVELA_WEB_URL.');

            return new Refusal('hostname', 422, self::NOT_A_PERSON);
        }

        return (string) $response->json('action') === self::action($resource) ? null : $failed;
    }

    public static function secret(): string
    {
        return (string) config('nevela.public.turnstile.secret', '');
    }

    private static function takesMail(string $address, string $domain): bool
    {
        if (self::$takesMail !== null) {
            return (bool) (self::$takesMail)($domain);
        }
        // Laravel's own check, which needs PHP's intl extension to read a domain in another script.
        if (function_exists('idn_to_ascii')) {
            return Validator::make(['address' => $address], ['address' => 'email:rfc,dns'])->passes();
        }

        // A domain with no MX record still takes mail at its A record, so either will do.
        return checkdnsrr($domain.'.', 'MX') || checkdnsrr($domain.'.', 'A') || checkdnsrr($domain.'.', 'AAAA');
    }

    private static function filled(mixed $value): bool
    {
        return is_scalar($value) ? trim((string) $value) !== '' : $value !== null && $value !== [];
    }

    /** A token that says when this form was handed out, in a way only this app could have written. */
    private static function sign(string $resource, int $time): string
    {
        $payload = rtrim(strtr(base64_encode($resource.'|'.$time), '+/', '-_'), '=');

        return $payload.'.'.hash_hmac('sha256', $payload, self::key());
    }

    /** When a token was issued, or null when it isn't one of this app's for this form. */
    private static function issuedAt(string $resource, mixed $token): ?int
    {
        if (! is_string($token) || substr_count($token, '.') !== 1) {
            return null;
        }
        [$payload, $signature] = explode('.', $token);
        if (! hash_equals(hash_hmac('sha256', $payload, self::key()), $signature)) {
            return null;
        }
        [$for, $time] = array_pad(explode('|', (string) base64_decode(strtr($payload, '-_', '+/'), true), 2), 2, '');

        return $for === $resource && ctype_digit($time) ? (int) $time : null;
    }

    private static function key(): string
    {
        return 'nevela-form|'.config('app.key');
    }
}
