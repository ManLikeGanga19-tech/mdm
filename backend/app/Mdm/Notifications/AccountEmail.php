<?php

namespace App\Mdm\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails about a person's own admin-site account (invite, new set-password link). Queued (Horizon), so a slow mail
 * server never holds up the admin page. Disabled accounts get nothing.
 */
abstract class AccountEmail extends Notification implements ShouldQueue
{
    use Queueable;

    /** False while the site has no mail server (MAIL_MAILER=log): links are then only shown to the admin to copy. */
    public static function canSend(): bool
    {
        return config('mail.default') !== 'log';
    }

    /** "48 hours", from the password broker's expiry (config/auth.php). */
    public static function linkLifetime(): string
    {
        $hours = intdiv((int) config('auth.passwords.users.expire'), 60);

        return $hours === 1 ? '1 hour' : "{$hours} hours";
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return ! ($notifiable instanceof User && $notifiable->isDisabled());
    }

    protected function message(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->greeting("Hello {$notifiable->name},")
            ->salutation('Noahport MDM');
    }

    abstract public function toMail(object $notifiable): MailMessage;
}
