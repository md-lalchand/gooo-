<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\InAppNotification;
use App\Models\User;
use Illuminate\Http\Request;

class AuditRecorder
{
    public static function log(?Request $request, string $action, ?string $targetType = null, ?string $targetId = null, array $details = [], ?User $actorOverride = null): void
    {
        try {
            $actor = $actorOverride ?? $request?->user();
            AuditLog::create([
                'actor_id' => $actor?->id,
                'actor_name' => $actor?->name ?? 'System',
                'actor_role' => $actor?->role ?? 'system',
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'details_json' => !empty($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
                'ip_address' => $request?->ip(),
            ]);
        } catch (\Throwable) {
            // Non-blocking audit recorder
        }
    }

    public static function notify(int $userId, string $title, string $body, ?string $linkUrl = null, string $category = 'system'): void
    {
        try {
            InAppNotification::create([
                'user_id' => $userId,
                'title' => $title,
                'body' => $body,
                'link_url' => $linkUrl,
                'category' => $category,
                'is_read' => false,
            ]);
        } catch (\Throwable) {
            // Non-blocking notification helper
        }
    }
}
