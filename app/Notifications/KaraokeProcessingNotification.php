<?php

namespace App\Notifications;

use App\Enums\KaraokeProcessingNotificationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class KaraokeProcessingNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly array $payload,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }

    public function eventType(): KaraokeProcessingNotificationEvent
    {
        return KaraokeProcessingNotificationEvent::from((string) ($this->payload['event_type'] ?? 'failed'));
    }
}
