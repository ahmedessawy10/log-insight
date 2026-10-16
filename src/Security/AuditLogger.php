<?php

namespace LogInsight\Security;

use LogInsight\Models\AuditLog;

class AuditLogger
{
    /**
     * Record who did what. Never throws: auditing must not break the request, and detail
     * payloads are size-capped so a huge filter can't bloat the table.
     */
    public static function record(string $action, ?string $target = null, array $details = []): void
    {
        try {
            $request = request();
            $user = $request?->user();

            AuditLog::create([
                'user_id' => $user?->getAuthIdentifier(),
                'user_label' => $user?->email ?? $user?->name ?? EnvAuth::username(),
                'action' => $action,
                'target' => $target !== null ? mb_substr($target, 0, 255) : null,
                'details' => $details ? mb_substr(json_encode($details, JSON_UNESCAPED_UNICODE), 0, 4000) : null,
                'ip' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
