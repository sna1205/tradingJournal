<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * @throws ValidationException
     */
    public function register(Request $request)
    {
        $payload = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ])->validate();

        $user = User::query()->create([
            'name' => (string) $payload['name'],
            'email' => strtolower((string) $payload['email']),
            'password' => (string) $payload['password'],
        ]);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ], 201);
    }

    /**
     * @throws ValidationException
     */
    public function login(Request $request)
    {
        $payload = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ])->validate();

        $email = strtolower((string) $payload['email']);
        $identityKey = $this->loginIdentityKey($request, $email);
        $failureKey = "auth:login:failures:{$identityKey}";
        $backoffKey = "auth:login:backoff:{$identityKey}";

        if (RateLimiter::tooManyAttempts($backoffKey, 1)) {
            $retryAfter = max(1, RateLimiter::availableIn($backoffKey));

            throw new HttpResponseException(
                response()->json([
                    'message' => 'Too many failed login attempts. Please retry later.',
                    'retry_after' => $retryAfter,
                ], 429)
            );
        }

        $user = User::query()->where('email', $email)->first();

        if (!$user || !Hash::check((string) $payload['password'], (string) $user->password)) {
            $failedAttempts = (int) RateLimiter::hit($failureKey, 15 * 60);
            $backoffSeconds = $this->loginBackoffSeconds($failedAttempts);
            if ($backoffSeconds > 0) {
                RateLimiter::clear($backoffKey);
                RateLimiter::hit($backoffKey, $backoffSeconds);
            }

            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        RateLimiter::clear($failureKey);
        RateLimiter::clear($backoffKey);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }

    private function loginIdentityKey(Request $request, string $email): string
    {
        $ip = (string) ($request->ip() ?? 'unknown');
        $normalizedEmail = strtolower(trim($email));

        return $normalizedEmail !== ''
            ? "{$ip}|{$normalizedEmail}"
            : "{$ip}|missing-email";
    }

    private function loginBackoffSeconds(int $failedAttempts): int
    {
        if ($failedAttempts < 6) {
            return 0;
        }

        return (int) min(300, 2 ** min(8, $failedAttempts - 5));
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    public function logout(Request $request)
    {
        $token = $request->user()?->currentAccessToken();
        if ($token !== null) {
            $token->delete();
        }

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }
}
