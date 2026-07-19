<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class HrisTransactionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $requestType,
        private readonly string $status,
        private readonly array $details,
        private readonly ?string $actor = null,
        private readonly ?string $notes = null,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['mail'];

        if (method_exists($notifiable, 'pushSubscriptions') && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("[HRIS] {$this->requestType} – {$this->status}")
            ->view('emails.hris_transaction', [
                'notifiable' => $notifiable,
                'requestType' => $this->requestType,
                'status' => $this->status,
                'details' => $this->details,
                'actor' => $this->actor,
                'notes' => $this->notes,
                'sentAt' => now()->format('l, F j, Y g:i A'),
            ]);
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $body = $this->notes ?: collect($this->details)
            ->map(fn ($value, $key) => "{$key}: {$value}")
            ->implode(', ');

        return (new WebPushMessage)
            ->title("[HRIS] {$this->requestType} – {$this->status}")
            ->body($body !== '' ? $body : 'Tap to view details in HRIS.')
            ->icon('/icons/icon-192.png')
            ->data(['url' => '/dashboard']);
    }
}
