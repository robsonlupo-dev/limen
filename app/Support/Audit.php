<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

// ClientFingerprint: HMAC-SHA256 do IP com a APP_KEY (mesmo do aceite de
// documentos). O audit guarda o hash, nunca o octeto cru — ver a migration
// hash_ip_on_audit_logs e SECURITY_ISSUES.

class Audit
{
    public static function log(
        string $action,
        ?Model $subject = null,
        ?array $metadata = null,
        ?Request $request = null,
    ): AuditLog {
        $request ??= request();

        $userId = $request->user()?->id;
        if (! $userId && $subject instanceof User) {
            $userId = $subject->getKey();
        }

        return AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'subject_type' => $subject ? $subject->getMorphClass() : null,
            'subject_id' => $subject?->getKey(),
            'ip_hash' => ClientFingerprint::hash($request->ip()),
            'metadata' => $metadata,
        ]);
    }
}
