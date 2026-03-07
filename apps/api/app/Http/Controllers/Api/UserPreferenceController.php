<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserPreference;
use App\Services\UserPreferenceAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserPreferenceController extends Controller
{
    public function __construct(
        private readonly UserPreferenceAuditLogger $auditLogger
    ) {
    }

    public function show(Request $request)
    {
        $user = $request->user();
        $preference = $this->resolvePreference((int) $user->id);

        return response()->json($preference);
    }

    /**
     * @throws ValidationException
     */
    public function update(Request $request)
    {
        $user = $request->user();
        $payload = $this->validatePayload($request);
        $preference = $this->resolvePreference((int) $user->id);
        $keys = array_keys($payload);
        $before = [];
        foreach ($keys as $key) {
            $before[$key] = $preference->{$key};
        }

        $preference->fill($payload);
        $dirty = array_keys($preference->getDirty());
        if ($dirty === []) {
            return response()->json($preference);
        }

        $preference->save();
        $after = [];
        foreach ($dirty as $key) {
            $after[$key] = $preference->{$key};
        }

        $this->auditLogger->log(
            request: $request,
            userId: (int) $user->id,
            changedByUserId: (int) $user->id,
            changedKeys: $dirty,
            beforeValues: array_intersect_key($before, array_flip($dirty)),
            afterValues: $after
        );

        return response()->json($preference);
    }

    /**
     * @throws ValidationException
     * @return array<string,mixed>
     */
    private function validatePayload(Request $request): array
    {
        return Validator::make($request->all(), [
            'theme_mode' => ['sometimes', Rule::in(['light', 'dark', 'forest', 'dawn'])],
            'profile_timezone' => ['sometimes', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_\/+\-]*$/'],
            'profile_locale' => ['sometimes', 'string', 'max:16', 'regex:/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/'],
        ])->validate();
    }

    private function resolvePreference(int $userId): UserPreference
    {
        return UserPreference::query()->firstOrCreate(
            ['user_id' => $userId],
            [
                'theme_mode' => 'light',
                'profile_timezone' => 'UTC',
                'profile_locale' => 'en-US',
            ]
        );
    }
}
