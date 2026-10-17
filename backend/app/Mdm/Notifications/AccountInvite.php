<?php

namespace App\Mdm\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/** Sent when a super admin invites someone (AccountService::invite): a link to choose their password. */
class AccountInvite extends AccountEmail
{
    public function __construct(public string $link, public ?string $invitedBy = null) {}

    public function toMail(object $notifiable): MailMessage
    {
        $by = $this->invitedBy ?? 'A super admin';

        return $this->message($notifiable)
            ->subject('Your Noahport MDM account')
            ->line("{$by} has made you a {$notifiable->role?->name} account on the Noahport MDM admin site, where company phones are managed.")
            ->action('Choose your password', $this->link)
            ->line('The link works once, for '.self::linkLifetime().'. After that, ask a super admin for a new one.')
            ->line("You sign in with this email address ({$notifiable->email}).");
    }
}
