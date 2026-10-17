<?php

namespace App\Mdm\Notifications;

use App\Mdm\Models\Alert;
use App\Mdm\Models\AlertRule;
use App\Models\User;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * New alerts of one rule from one check: one email (when the site has mail) and one bell notification on the admin
 * site per recipient account; extra addresses get the email only. Queued (Horizon).
 */
class AlertsTriggered extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var list<string> */
    public array $messages;

    public string $subject;

    public string $url;

    /** @param  Collection<int, Alert>  $alerts */
    public function __construct(AlertRule $rule, Collection $alerts)
    {
        $tags = $alerts->map(fn (Alert $a) => $a->device?->asset_tag)->filter()->values();
        $this->subject = $rule->name.($tags->isEmpty() ? '' : ': '.$tags->take(3)->implode(', ').($tags->count() > 3 ? ' and '.($tags->count() - 3).' more' : ''));
        $this->messages = $alerts->pluck('message')->all();
        $this->url = route('filament.admin.resources.alerts.index');
    }

    public function via(object $notifiable): array
    {
        $mail = AccountEmail::canSend() ? ['mail'] : [];

        return $notifiable instanceof User ? ['database', ...$mail] : $mail;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject("Alert: {$this->subject}")->greeting($this->subject);
        foreach ($this->messages as $line) {
            $message->line($line);
        }

        return $message->action('Open alerts', $this->url)
            ->line('You get this email because an alert rule on the Noahport MDM admin site lists you as a recipient.')
            ->salutation('Noahport MDM');
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->subject)
            ->body(implode("\n", array_slice($this->messages, 0, 5)).(count($this->messages) > 5 ? "\n…" : ''))
            ->icon('heroicon-o-bell-alert')
            ->danger()
            ->getDatabaseMessage();
    }
}
