<?php

namespace Nevela\Laravel\Auth;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifications;
use Throwable;

/**
 * The emails signing in needs: codes, links, and notices that something changed.
 *
 * Sent with whatever mailer the Laravel app is configured with. In development that is
 * usually the log, where nobody looks, so the code or link is also printed to the
 * server's output: with `nevela dev` it appears in the terminal as it is "sent".
 */
final class AuthMail
{
    /**
     * @param  list<string>  $lines  Paragraphs, in order
     * @param  array{0: string, 1: string}|null  $action  A button: [label, url]
     * @param  string|null  $secret  The code or link, for the development console
     */
    /**
     * What was sent while tests run, so a test can read the code it was "emailed".
     *
     * @var list<array{email: string, subject: string, lines: list<string>, action: array{0: string, 1: string}|null, secret: string|null}>
     */
    public static array $outbox = [];

    public static function send(string $email, string $subject, array $lines, ?array $action = null, ?string $secret = null): void
    {
        if (app()->runningUnitTests()) {
            self::$outbox[] = compact('email', 'subject', 'lines', 'action', 'secret');
        }
        if (app()->environment('local') && $secret !== null) {
            // The dev server shows its error output; nothing else of the request is printed there.
            @file_put_contents('php://stderr', "Nevela mail to {$email}: {$subject}\n    {$secret}\n");
        }

        try {
            Notifications::route('mail', $email)->notify(new class($subject, $lines, $action) extends Notification
            {
                /** @param list<string> $lines @param array{0: string, 1: string}|null $action */
                public function __construct(private string $subject, private array $lines, private ?array $action) {}

                /** @return list<string> */
                public function via(object $notifiable): array
                {
                    return ['mail'];
                }

                public function toMail(object $notifiable): MailMessage
                {
                    $message = (new MailMessage)->subject($this->subject);
                    foreach ($this->lines as $line) {
                        $message->line($line);
                    }
                    if ($this->action !== null) {
                        $message->action($this->action[0], $this->action[1]);
                    }

                    return $message->line("If you didn't ask for this, you can ignore this email.");
                }
            });
        } catch (Throwable $e) {
            // A mail server being down must not tell the visitor anything, or break sign-in
            // for an address that may not even exist. It is reported for the developer.
            report($e);
        }
    }

    public static function app(): string
    {
        return (string) (config('nevela.auth.issuer') ?: config('app.name', 'Nevela'));
    }
}
