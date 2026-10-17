<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Sends one email right away (not queued), so a wrong Zoho host, port or app password shows here as an error. */
class SendTestMail extends Command
{
    protected $signature = 'mdm:mail-test {email}';

    protected $description = 'Send a test email to check the mail server settings';

    public function handle(): int
    {
        $mailer = config('mail.default');
        if ($mailer === 'log') {
            $this->warn('MAIL_MAILER is "log": the email only goes to the log file. Set the Zoho SMTP details in .env first.');
        }

        try {
            Mail::raw(
                'This is a test email from the Noahport MDM admin site ('.config('app.url').'). If you can read it, '
                .'invites, password emails and alerts can be sent.',
                fn ($message) => $message->to($this->argument('email'))->subject('Noahport MDM test email'),
            );
        } catch (Throwable $e) {
            $this->error('Sending failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Sent to {$this->argument('email')} via {$mailer} (from ".config('mail.from.address').').');

        return self::SUCCESS;
    }
}
