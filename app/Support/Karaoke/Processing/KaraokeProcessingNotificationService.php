<?php

namespace App\Support\Karaoke\Processing;

use App\Enums\KaraokeProcessingNotificationEvent;
use App\Models\KaraokeProject;
use App\Models\KaroksProcessingNotificationDelivery;
use App\Models\User;
use App\Notifications\KaraokeProcessingNotification;
use App\Support\KaraokeProcessingStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KaraokeProcessingNotificationService
{
    public function __construct(
        private readonly KaraokeProcessingDriverResolver $driverResolver,
    ) {}

    public function idempotencyKey(KaraokeProject $project, string $runId, KaraokeProcessingNotificationEvent $event): string
    {
        return sprintf(
            'karaoke:%s:run:%s:%s',
            $project->public_id,
            $runId,
            $event->value,
        );
    }

    public function notifyTerminal(
        KaraokeProject $project,
        string $runId,
        KaraokeProcessingNotificationEvent $event,
        ?string $errorCode = null,
    ): bool {
        $user = $project->user;

        if ($user === null) {
            return false;
        }

        $idempotencyKey = $this->idempotencyKey($project, $runId, $event);
        $payload = $this->buildPayload($project, $runId, $event, $errorCode);

        $inserted = DB::table('karoks_processing_notification_deliveries')->insertOrIgnore([
            'user_id' => $user->id,
            'karaoke_project_id' => $project->id,
            'idempotency_key' => $idempotencyKey,
            'event_type' => $event->value,
            'project_public_id' => $project->public_id,
            'processing_run_id' => $runId,
            'created_at' => now(),
        ]);

        if ($inserted === 0) {
            return false;
        }

        $user->notify(new KaraokeProcessingNotification($payload));

        return true;
    }

    public function cleanupForProject(KaraokeProject $project): void
    {
        KaroksProcessingNotificationDelivery::query()
            ->where('karaoke_project_id', $project->id)
            ->delete();

        $project->user?->notifications()
            ->where('type', KaraokeProcessingNotification::class)
            ->where('data->project_public_id', $project->public_id)
            ->delete();
    }

    public function cleanupForUser(int $userId): void
    {
        KaroksProcessingNotificationDelivery::query()
            ->where('user_id', $userId)
            ->delete();

        DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $userId)
            ->where('type', KaraokeProcessingNotification::class)
            ->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(
        KaraokeProject $project,
        string $runId,
        KaraokeProcessingNotificationEvent $event,
        ?string $errorCode = null,
    ): array {
        $safeErrorCode = $this->normalizeErrorCode($errorCode ?? $event->safeErrorCode());
        $capturedDriver = (string) ($project->processing_driver ?? 'mock');
        $simulated = $capturedDriver === 'mock';
        $title = $this->displayTitle($project);

        return [
            'icon' => '/storage/demo/default.png',
            'body' => $this->bodyForEvent($event, $title, $simulated),
            'link' => $this->linkForEvent($project, $event),
            'event_type' => $event->value,
            'project_public_id' => $project->public_id,
            'project_title' => $title,
            'status' => $this->statusLabel($event),
            'error_code' => $safeErrorCode,
            'processing_driver' => $capturedDriver,
            'simulated_processing' => $simulated,
            'notified_at' => now()->toIso8601String(),
            'user' => [
                'name' => $project->user?->name ?? 'User',
            ],
        ];
    }

    private function displayTitle(KaraokeProject $project): string
    {
        $title = trim((string) ($project->title ?? ''));

        if ($title !== '') {
            return Str::limit($title, 120, '…');
        }

        $filename = trim((string) ($project->original_filename ?? ''));

        if ($filename !== '') {
            return Str::limit(basename(str_replace('\\', '/', $filename)), 120, '…');
        }

        return 'Karaoke project';
    }

    private function bodyForEvent(KaraokeProcessingNotificationEvent $event, string $title, bool $simulated): string
    {
        return match ($event) {
            KaraokeProcessingNotificationEvent::Completed => $simulated
                ? sprintf('"%s" finished processing (simulated).', $title)
                : sprintf('"%s" finished processing with external providers.', $title),
            KaraokeProcessingNotificationEvent::Failed => sprintf('Processing failed for "%s". You can retry from the project page.', $title),
            KaraokeProcessingNotificationEvent::Cancelled => sprintf('Processing was cancelled for "%s".', $title),
            KaraokeProcessingNotificationEvent::Stalled => sprintf('Processing stalled for "%s". You can retry from the project page.', $title),
        };
    }

    private function linkForEvent(KaraokeProject $project, KaraokeProcessingNotificationEvent $event): string
    {
        if ($event === KaraokeProcessingNotificationEvent::Completed && $project->isReadyForPlayback()) {
            return route('karaoke.projects.player', $project);
        }

        return route('karaoke.projects.show', $project);
    }

    private function statusLabel(KaraokeProcessingNotificationEvent $event): string
    {
        return match ($event) {
            KaraokeProcessingNotificationEvent::Completed => 'completed',
            KaraokeProcessingNotificationEvent::Cancelled => 'cancelled',
            KaraokeProcessingNotificationEvent::Failed => 'failed',
            KaraokeProcessingNotificationEvent::Stalled => 'failed',
        };
    }

    private function normalizeErrorCode(?string $errorCode): ?string
    {
        if ($errorCode === null) {
            return null;
        }

        $normalized = strtolower(trim($errorCode));

        if ($normalized === '' || ! in_array($normalized, KaraokeProcessingStateService::SAFE_ERROR_CODES, true)) {
            return 'processing_failed';
        }

        return $normalized;
    }
}
