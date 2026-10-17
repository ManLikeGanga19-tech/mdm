<?php

namespace App\Filament\Auth;

use App\Mdm\Notifications\AccountEmail;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Notifications\Notification;

/**
 * "Forgot password?" on the sign-in page. Until the site can send mail (Zoho SMTP, MAIL_MAILER other than log), a
 * reset email would go nowhere, so the page says to ask a super admin, who makes a set-password link on Accounts.
 * With mail, Filament sends the reset email in our wording (App\Mdm\Notifications\ForgotPassword).
 */
class RequestPasswordReset extends BaseRequestPasswordReset
{
    public function request(): void
    {
        if (AccountEmail::canSend()) {
            parent::request();

            return;
        }
        Notification::make()
            ->title('Ask a super admin for a set-password link')
            ->body('Email is not set up on this site yet. A super admin can make you a link on the Accounts page.')
            ->warning()
            ->persistent()
            ->send();
    }
}
