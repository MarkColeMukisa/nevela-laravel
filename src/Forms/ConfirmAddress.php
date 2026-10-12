<?php

namespace Nevela\Laravel\Forms;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

/**
 * The email with the link that proves an address: see Confirmation.
 *
 * Written like the emails signing in sends, so the two look alike. To say it in your own
 * words, send your own Mailable with Confirmation::link() in it, through
 * PublicMail::toVisitor().
 */
final class ConfirmAddress extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $app, public string $url, public int $hours) {}

    public function build(): self
    {
        $message = (new MailMessage)
            ->subject("Confirm your email address for {$this->app}")
            ->line("Someone, we hope you, gave this address to {$this->app}. Confirm it and you're on the list.")
            ->action('Confirm my address', $this->url)
            ->line("The link works for {$this->hours} hours. If this wasn't you, do nothing: the address is removed and you won't hear from us again.");

        return $this->subject("Confirm your email address for {$this->app}")->html((string) $message->render());
    }
}
