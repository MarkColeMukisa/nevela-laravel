<?php

namespace Nevela\Laravel\Forms;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One email for the notices that were held back: "14 more enquiries since 14:05".
 *
 * Each is named by its subject and first line, with a link to where it is in the
 * dashboard. See PublicMail::toOwner().
 */
final class OwnerDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /** The most a digest lists by name. Beyond it the email says how many more there are. */
    public const LISTED = 50;

    /**
     * @param  list<array{subject: string, line: string|null, url: string|null}>  $held
     * @param  string  $since  The time the first of them arrived: "14:05"
     */
    public function __construct(public array $held, public string $since) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function subject(): string
    {
        $count = count($this->held);

        return $count === 1 ? "1 more enquiry since {$this->since}" : number_format($count)." more enquiries since {$this->since}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->subject())
            ->line('These arrived after the hour\'s notices had gone out. Every one of them is in the dashboard.');
        foreach (array_slice($this->held, 0, self::LISTED) as $row) {
            $text = '**'.self::plain($row['subject']).'**'.($row['line'] !== null && $row['line'] !== '' ? ': '.self::plain($row['line']) : '');
            $message->line($row['url'] !== null && $row['url'] !== '' ? "{$text} ([open]({$row['url']}))" : $text);
        }
        if (($more = count($this->held) - self::LISTED) > 0) {
            $message->line("And {$more} more.");
        }

        return $message;
    }

    /** What a visitor typed, as words: the email is written in Markdown, and theirs must not be read as any. */
    private static function plain(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_{}\[\]()#+\-.!|<>])/', '\\\\$1', $text);
    }
}
