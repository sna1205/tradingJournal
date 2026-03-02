<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\AuthEventLogger;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthEventLogger $authEventLogger
    ) {
    }

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

        if ($this->statefulApiEnabled()) {
            Auth::guard('web')->login($user);
            if ($request->hasSession()) {
                $request->session()->regenerate();
            }
        }

        $this->authEventLogger->log(
            request: $request,
            action: 'register',
            outcome: 'success',
            user: $user,
            email: $user->email
        );

        return response()->json(
            $this->authSuccessPayload($user),
            201
        );
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
            $this->authEventLogger->log(
                request: $request,
                action: 'login',
                outcome: 'fail',
                email: $email,
                reason: 'rate_limited'
            );

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

            $this->authEventLogger->log(
                request: $request,
                action: 'login',
                outcome: 'fail',
                user: $user,
                email: $email,
                reason: 'invalid_credentials'
            );

            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        RateLimiter::clear($failureKey);
        RateLimiter::clear($backoffKey);

        if ($this->statefulApiEnabled()) {
            Auth::guard('web')->login($user);
            if ($request->hasSession()) {
                $request->session()->regenerate();
            }
        }

        $this->authEventLogger->log(
            request: $request,
            action: 'login',
            outcome: 'success',
            user: $user,
            email: $user->email
        );

        return response()->json($this->authSuccessPayload($user));
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
        $user = $request->user();
        if ($this->statefulApiEnabled()) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        } else {
            $request->user()?->currentAccessToken()?->delete();
        }

        $this->authEventLogger->log(
            request: $request,
            action: 'logout',
            outcome: 'success',
            user: $user,
            email: $user?->email
        );

        $response = response()->json([
            'message' => 'Logged out.',
        ]);

        if ($this->statefulApiEnabled()) {
            return $response
                ->withoutCookie((string) config('session.cookie'))
                ->withoutCookie('XSRF-TOKEN');
        }

        return $response;
    }

    public function logoutAll(Request $request)
    {
        $user = $request->user();
        if ($user === null) {
            $this->authEventLogger->log(
                request: $request,
                action: 'logout_all',
                outcome: 'fail',
                reason: 'unauthenticated'
            );

            throw new HttpResponseException(response()->json([
                'message' => 'Unauthenticated.',
            ], 401));
        }

        $currentSessionId = $request->hasSession() ? (string) $request->session()->getId() : '';

        $revokedSessions = 0;
        if ((string) config('session.driver', 'file') === 'database') {
            $sessions = DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->getAuthIdentifier());

            if ($currentSessionId !== '') {
                $sessions->where('id', '!=', $currentSessionId);
            }

            $revokedSessions = (int) $sessions->delete();
        }

        $revokedTokens = (int) $user->tokens()->delete();

        $user->forceFill([
            'remember_token' => Str::random(60),
        ])->save();

        if ($request->hasSession()) {
            $request->session()->regenerate();
            $request->session()->regenerateToken();
        }

        $this->authEventLogger->log(
            request: $request,
            action: 'logout_all',
            outcome: 'success',
            user: $user,
            email: $user->email
        );

        return response()->json([
            'message' => 'Other sessions and tokens revoked.',
            'revoked_sessions' => $revokedSessions,
            'revoked_tokens' => $revokedTokens,
        ]);
    }

    private function statefulApiEnabled(): bool
    {
        return (bool) config('sanctum.stateful_api', true);
    }

    /**
     * @return array<string, mixed>
     */
    private function authSuccessPayload(User $user): array
    {
        if ($this->statefulApiEnabled()) {
            return [
                'user' => $user,
            ];
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ];
    }
}
