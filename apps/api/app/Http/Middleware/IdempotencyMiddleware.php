<?php

namespace App\Http\Middleware;

use App\Support\ApiErrorResponder;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class IdempotencyMiddleware
{
    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        $required = strtolower(trim($mode)) === 'required';
        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));

        if ($idempotencyKey === '') {
            if (! $required) {
                return $next($request);
            }

            return ApiErrorResponder::errorV2(
                request: $request,
                status: 422,
                code: 'idempotency_key_required',
                message: 'Idempotency-Key header is required for this endpoint.',
                details: [[
                    'field' => 'Idempotency-Key',
                    'message' => 'Idempotency-Key header is required for this endpoint.',
                ]],
                legacyErrors: [
                    'Idempotency-Key' => ['Idempotency-Key header is required for this endpoint.'],
                ]
            );
        }

        $userId = (int) ($request->user()?->id ?? 0);
        if ($userId <= 0) {
            return ApiErrorResponder::errorV2(
                request: $request,
                status: 401,
                code: 'idempotency_auth_required',
                message: 'Authenticated user is required for idempotent requests.'
            );
        }

        $routeSignature = strtoupper($request->method()) . ' ' . ltrim($request->path(), '/');
        $requestHash = $this->buildRequestHash($request);
        $expiresAt = now()->addMinutes($this->ttlMinutes());

        $this->deleteExpiredReservation($userId, $routeSignature, $idempotencyKey);

        $reserved = $this->reserveKey($userId, $routeSignature, $idempotencyKey, $requestHash, $expiresAt);
        if (! $reserved) {
            $existing = DB::table('idempotency_keys')
                ->where('user_id', $userId)
                ->where('route', $routeSignature)
                ->where('key', $idempotencyKey)
                ->first();

            if ($existing === null) {
                return ApiErrorResponder::errorV2(
                    request: $request,
                    status: 409,
                    code: 'idempotency_state_unavailable',
                    message: 'Unable to resolve idempotent request state.'
                );
            }

            if ((string) $existing->request_hash !== $requestHash) {
                return ApiErrorResponder::errorV2(
                    request: $request,
                    status: 409,
                    code: 'idempotency_payload_mismatch',
                    message: 'Idempotency-Key reuse with different request payload is not allowed.',
                    details: [[
                        'field' => 'Idempotency-Key',
                        'message' => 'Idempotency-Key cannot be reused with a different request payload.',
                    ]]
                );
            }

            if ($existing->response_code !== null) {
                return response((string) ($existing->response_body ?? ''), (int) $existing->response_code, [
                    'Content-Type' => 'application/json',
                    'X-Idempotent-Replay' => 'true',
                ]);
            }

            return ApiErrorResponder::errorV2(
                request: $request,
                status: 409,
                code: 'idempotency_in_progress',
                message: 'A request with this Idempotency-Key is currently in progress.'
            );
        }

        try {
            /** @var Response $response */
            $response = $next($request);

            if ($response->isSuccessful()) {
                DB::table('idempotency_keys')
                    ->where('user_id', $userId)
                    ->where('route', $routeSignature)
                    ->where('key', $idempotencyKey)
                    ->update([
                        'response_code' => (int) $response->getStatusCode(),
                        'response_body' => $this->responseBodyForStorage($response),
                    ]);

                return $response;
            }

            $this->deleteReservation($userId, $routeSignature, $idempotencyKey);

            return $response;
        } catch (\Throwable $throwable) {
            $this->deleteReservation($userId, $routeSignature, $idempotencyKey);
            throw $throwable;
        }
    }

    private function reserveKey(
        int $userId,
        string $routeSignature,
        string $idempotencyKey,
        string $requestHash,
        \Illuminate\Support\Carbon $expiresAt
    ): bool
    {
        try {
            DB::table('idempotency_keys')->insert([
                'user_id' => $userId,
                'route' => $routeSignature,
                'key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'response_code' => null,
                'response_body' => null,
                'created_at' => now(),
                'expires_at' => $expiresAt,
            ]);

            return true;
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return false;
            }

            throw $exception;
        }
    }

    private function deleteReservation(int $userId, string $routeSignature, string $idempotencyKey): void
    {
        DB::table('idempotency_keys')
            ->where('user_id', $userId)
            ->where('route', $routeSignature)
            ->where('key', $idempotencyKey)
            ->whereNull('response_code')
            ->delete();
    }

    private function deleteExpiredReservation(int $userId, string $routeSignature, string $idempotencyKey): void
    {
        DB::table('idempotency_keys')
            ->where('user_id', $userId)
            ->where('route', $routeSignature)
            ->where('key', $idempotencyKey)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) config('idempotency.ttl_minutes', 1440));
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');

        return in_array($sqlState, ['23000', '23505'], true);
    }

    private function responseBodyForStorage(Response $response): string
    {
        $content = $response->getContent();

        if ($content === false || $content === null) {
            return '';
        }

        return (string) $content;
    }

    private function buildRequestHash(Request $request): string
    {
        $payload = [
            'method' => strtoupper($request->method()),
            'path' => ltrim($request->path(), '/'),
            'query' => $this->normalizeValue($request->query()),
            'input' => $this->normalizeValue($request->except([])),
            'files' => $this->normalizeFiles($request->allFiles()),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            if ($this->isSequentialArray($value)) {
                return array_map(fn ($item): mixed => $this->normalizeValue($item), $value);
            }

            ksort($value);
            $normalized = [];
            foreach ($value as $key => $entry) {
                $normalized[(string) $key] = $this->normalizeValue($entry);
            }

            return $normalized;
        }

        if (is_object($value)) {
            if ($value instanceof UploadedFile) {
                return $this->normalizeUploadedFile($value);
            }

            return $this->normalizeValue((array) $value);
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $files
     * @return array<string,mixed>
     */
    private function normalizeFiles(array $files): array
    {
        $normalized = [];
        foreach ($files as $key => $value) {
            if ($value instanceof UploadedFile) {
                $normalized[(string) $key] = $this->normalizeUploadedFile($value);
                continue;
            }

            if (is_array($value)) {
                $normalized[(string) $key] = $this->normalizeFiles($value);
            }
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @return array{name:string,size:int,mime:string,sha1:string}
     */
    private function normalizeUploadedFile(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $sha1 = is_string($path) && $path !== '' && is_file($path)
            ? (string) (sha1_file($path) ?: '')
            : '';

        return [
            'name' => (string) $file->getClientOriginalName(),
            'size' => (int) ($file->getSize() ?? 0),
            'mime' => (string) ($file->getClientMimeType() ?? $file->getMimeType() ?? ''),
            'sha1' => $sha1,
        ];
    }

    /**
     * @param array<int|string,mixed> $value
     */
    private function isSequentialArray(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}
