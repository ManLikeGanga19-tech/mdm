<?php

namespace App\Mdm\Notifications;

use Filament\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "Forgot password?" email. Replaces Filament's (bound in AppServiceProvider) so the wording matches the other
 * account emails; Filament still makes the link. Filament already skips accounts that can't sign in.
 */
class ForgotPassword extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset your Noahport MDM password')
            ->greeting("Hello {$notifiable->name},")
            ->line('Someone asked to reset the password of your Noahport MDM account.')
            ->action('Choose a new password', $this->url)
            ->line('The link works once, for '.AccountEmail::linkLifetime().". If you didn't ask, ignore this email: your password stays the same.")
            ->salutation('Noahport MDM');
    }
}
