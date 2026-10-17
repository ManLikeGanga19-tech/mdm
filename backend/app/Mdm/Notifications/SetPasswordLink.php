<?php

namespace App\Mdm\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/** Sent when a super admin makes a new set-password link (AccountService::resetPassword); the old password is gone. */
class SetPasswordLink extends AccountEmail
{
    public function __construct(public string $link) {}

    public function toMail(object $notifiable): MailMessage
    {
        return $this->message($notifiable)
            ->subject('Choose a new Noahport MDM password')
            ->line('A super admin has reset your password on the Noahport MDM admin site. Your old password no longer works.')
            ->action('Choose a new password', $this->link)
            ->line('The link works once, for '.self::linkLifetime().'.')
            ->line("If you didn't expect this, tell a super admin.");
    }
}
