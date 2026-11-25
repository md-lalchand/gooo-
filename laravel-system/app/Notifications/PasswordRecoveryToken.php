<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordRecoveryToken extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token)
    {
    }

    public function tokenHash(): string
    {
        return hash('sha256', $this->token);
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your account recovery token')
            ->greeting('Hello ' . ($notifiable->name ?? 'there') . ',')
            ->line('Use the recovery token below to set a new password.')
            ->line($this->token)
            ->line('This token expires in 60 minutes and can only be used once.')
            ->line('If you did not request this change, you can ignore this email.');
    }
}
