<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmployeeDefaultPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $defaultPassword) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your HRIS Account Credentials')
            ->greeting('Hello '.($notifiable->name ?? 'Employee').',')
            ->line('Your HRIS account has been created.')
            ->line('Default password: '.$this->defaultPassword)
            ->line('For security, you must change this password immediately after your first login.')
            ->action('Login to HRIS', route('login'))
            ->line('If you did not expect this account, please contact HR immediately.');
    }
}
