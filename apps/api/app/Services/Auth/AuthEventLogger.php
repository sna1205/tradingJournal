<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AuthEventLogger
{
    public function log(
        Request $request,
        string $action,
        string $outcome,
        ?User $user = null,
        ?string $email = null,
        ?string $reason = null
    ): void {
        $resolvedEmail = strtolower(trim((string) ($email ?? $user?->email ?? '')));
        $resolvedIp = trim((string) ($request->ip() ?? ''));

        try {
            DB::table('auth_events')->insert([
                'user_id' => $user?->id,
                'email' => $resolvedEmail !== '' ? $resolvedEmail : 'unknown',
                'ip' => $resolvedIp !== '' ? $resolvedIp : 'unknown',
                'user_agent' => $request->userAgent(),
                'action' => $action,
                'outcome' => $outcome,
                'reason' => $reason,
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
