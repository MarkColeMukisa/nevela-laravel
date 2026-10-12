<?php

namespace Nevela\Laravel\Forms;

use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * The mail a public form causes, with the limits that make it safe to send.
 *
 *     PublicMail::toVisitor($message->email, new MessageReceived($message));
 *     PublicMail::toOwner(new NewMessage($message), line: $message->subject, url: $link);
 *
 * A form that writes back to whatever address was typed into it will write to anyone:
 * type a stranger's address a thousand times and the site has sent them a thousand
 * emails. So an address is written to at most once an hour and three times a day,
 * counted across every form, and past that the email is dropped. The record is saved
 * either way.
 *
 * The owner is the other inbox. A burst of submissions would bury the real ones, so only
 * so many notices go out in an hour. The rest are held, and one email at the top of the
 * hour names them. Nothing is lost: every record is in the dashboard.
 *
 * Both are queued, so a slow mail server doesn't keep a visitor waiting.
 */
final class PublicMail
{
    private const SENT = 'nevela_public_mail';

    private const HELD = 'nevela_owner_digest';

    /**
     * Write to an address a form was given. False when it has been written to too often
     * and this one was dropped.
     */
    public static function toVisitor(string $address, Mailable $mail): bool
    {
        $address = strtolower(trim($address));
        if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $who = self::fingerprint($address);

        return self::counted("visitor:{$who}", function () use ($address, $mail, $who) {
            $hour = self::sentSince('visitor', $who, now()->subHour());
            $day = self::sentSince('visitor', $who, now()->subDay());
            if ($hour >= (int) config('nevela.public.mail.visitor_per_hour', 1) || $day >= (int) config('nevela.public.mail.visitor_per_day', 3)) {
                return false;
            }
            self::record('visitor', $who);
            Mail::to($address)->queue($mail);

            return true;
        });
    }

    /**
     * Tell the owner a form was sent. False when the hour's notices have gone, and this
     * one waits for the digest.
     *
     * @param  string|null  $line  What the digest says of it: a subject, or its first line
     * @param  string|null  $url  Where in the dashboard it is
     */
    public static function toOwner(Mailable $mail, ?string $line = null, ?string $url = null): bool
    {
        $to = self::owner();
        if ($to === null) {
            return false;
        }

        return self::counted('owner', function () use ($mail, $line, $url, $to) {
            if (self::sentSince('owner', 'owner', now()->subHour()) >= (int) config('nevela.public.notify.max_per_hour', 10)) {
                DB::table(self::HELD)->insert([
                    'id' => (string) Str::uuid(),
                    'subject' => Str::limit(self::subjectOf($mail), 250, ''),
                    'line' => $line === null ? null : Str::limit(trim(preg_replace('/\s+/', ' ', $line) ?? ''), 490),
                    'url' => $url === null ? null : Str::limit($url, 2040, ''),
                    'held_at' => now(),
                ]);

                return false;
            }
            self::record('owner', 'owner');
            Mail::to($to)->queue($mail);

            return true;
        });
    }

    /**
     * Send what was held, as one email, and forget it. Run at the top of each hour by the
     * scheduler (`nevela:digest`). Returns how many notices it named.
     */
    public static function digest(): int
    {
        $to = self::owner();
        $held = DB::table(self::HELD)->orderBy('held_at')->get();
        if ($held->isEmpty()) {
            return 0;
        }
        if ($to !== null) {
            Notification::route('mail', $to)->notify(new OwnerDigest(
                $held->map(fn ($row) => ['subject' => (string) $row->subject, 'line' => $row->line, 'url' => $row->url])->all(),
                Carbon::parse($held->first()->held_at)->format('H:i'),
            ));
        }
        DB::table(self::HELD)->whereIn('id', $held->pluck('id')->all())->delete();

        return $held->count();
    }

    /** Forget who was written to more than a few days ago, and old counts of what was refused. */
    public static function prune(): void
    {
        try {
            DB::table(self::SENT)->where('sent_at', '<', now()->subDays(2))->delete();
            DB::table('nevela_form_blocks')->where('day', '<', now()->subDays(90)->toDateString())->delete();
        } catch (Throwable) {
            // Not migrated yet.
        }
    }

    /** Who a form's notices go to: nevela.public.notify.to, or the address the app sends from. */
    public static function owner(): ?string
    {
        $to = config('nevela.public.notify.to') ?: config('mail.from.address');

        return is_string($to) && filter_var($to, FILTER_VALIDATE_EMAIL) ? $to : null;
    }

    /** An address as it is counted by: enough to tell it from another, not to read it back. */
    public static function fingerprint(string $address): string
    {
        return hash_hmac('sha256', strtolower(trim($address)), 'nevela-mail|'.config('app.key'));
    }

    private static function sentSince(string $kind, string $recipient, Carbon $since): int
    {
        return DB::table(self::SENT)->where('kind', $kind)->where('recipient', $recipient)->where('sent_at', '>', $since)->count();
    }

    private static function record(string $kind, string $recipient): void
    {
        DB::table(self::SENT)->insert(['id' => (string) Str::uuid(), 'kind' => $kind, 'recipient' => $recipient, 'sent_at' => now()]);
    }

    /** What an email is headed, for the digest to name it by: its subject, or failing that what it is called. */
    private static function subjectOf(Mailable $mail): string
    {
        try {
            // Asked of a copy: a mailable that sets its subject in build() must not be built twice.
            $probe = clone $mail;
            if (method_exists($probe, 'envelope')) {
                $subject = $probe->envelope()->subject;
            } else {
                if (method_exists($probe, 'build')) {
                    app()->call([$probe, 'build']);
                }
                $subject = $probe->subject ?? null;
            }
        } catch (Throwable) {
            $subject = null;
        }

        return is_string($subject) && $subject !== '' ? $subject : Str::headline(class_basename($mail));
    }

    /**
     * Count and send as one step, so two requests at the same moment can't both be the
     * last one allowed. Mail is never the reason a form fails: a problem here is reported
     * and the answer is "not sent".
     *
     * @param  callable(): bool  $send
     */
    private static function counted(string $name, callable $send): bool
    {
        try {
            try {
                return (bool) Cache::lock('nevela-mail:'.$name, 10)->block(5, $send);
            } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
                return (bool) $send();
            }
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
