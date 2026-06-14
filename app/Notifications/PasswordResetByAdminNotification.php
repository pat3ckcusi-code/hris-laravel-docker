<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetByAdminNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $temporaryPassword) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your HRIS Password Has Been Reset')
            ->greeting('Hello '.($notifiable->name ?? 'Employee').',')
            ->line('Your HRIS account password has been reset by an administrator.')
            ->line('Temporary password: '.$this->temporaryPassword)
            ->line('For security, you will be required to set a new password when you next log in.')
            ->action('Login to HRIS', route('login'))
            ->line('If you did not request this reset, please contact your Records Manager or HR immediately.');
    }
}
