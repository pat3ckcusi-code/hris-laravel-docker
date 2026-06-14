<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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
        return ['mail'];
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
}
