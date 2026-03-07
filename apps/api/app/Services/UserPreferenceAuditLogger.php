<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class UserPreferenceAuditLogger
{
    /**
     * @param array<int,string> $changedKeys
     * @param array<string,mixed> $beforeValues
     * @param array<string,mixed> $afterValues
     */
    public function log(
        Request $request,
        int $userId,
        int $changedByUserId,
        array $changedKeys,
        array $beforeValues,
        array $afterValues
    ): void {
        if ($changedKeys === []) {
            return;
        }

        try {
            DB::table('user_preference_audits')->insert([
                'user_id' => $userId,
                'changed_by_user_id' => $changedByUserId,
                'changed_keys' => json_encode(array_values($changedKeys), JSON_THROW_ON_ERROR),
                'before_values' => json_encode($beforeValues, JSON_THROW_ON_ERROR),
                'after_values' => json_encode($afterValues, JSON_THROW_ON_ERROR),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
