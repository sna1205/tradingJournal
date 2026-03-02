<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiErrorResponder
{
    public const CONTRACT_VERSION = '2026-03-02';

    /**
     * @param array<int,array{field:string,message:string}> $details
     * @param array<string,array<int,string>> $legacyErrors
     */
    public static function respond(
        Request $request,
        int $status,
        string $code,
        string $message,
        array $details = [],
        array $legacyErrors = [],
        array $meta = []
    ): JsonResponse {
        return self::errorV2(
            request: $request,
            status: $status,
            code: $code,
            message: $message,
            details: $details,
            legacyErrors: $legacyErrors,
            meta: $meta
        );
    }

    /**
     * @param array<int,array{field:string,message:string}> $details
     * @param array<string,array<int,string>> $legacyErrors
     */
    public static function errorV2(
        Request $request,
        int $status,
        string $code,
        string $message,
        array $details = [],
        array $legacyErrors = [],
        array $meta = []
    ): JsonResponse {
        $requestId = self::resolveRequestId($request);

        $payload = [
            'error' => [
                'version' => self::CONTRACT_VERSION,
                'status' => $status,
                'code' => $code,
                'message' => $message,
                'details' => array_values($details),
                'requestId' => $requestId,
                'meta' => (object) $meta,
            ],

            // Legacy fields kept for backward compatibility while clients migrate.
            'message' => $message,
        ];

        if ($legacyErrors !== []) {
            $payload['errors'] = $legacyErrors;
        }

        return response()
            ->json($payload, $status)
            ->header('X-Error-Contract', 'v2');
    }

    /**
     * @param array<string,array<int,string>> $errors
     * @return array<int,array{field:string,message:string}>
     */
    public static function flattenValidationErrors(array $errors): array
    {
        $details = [];

        foreach ($errors as $field => $messages) {
            foreach ($messages as $message) {
                $details[] = [
                    'field' => (string) $field,
                    'message' => (string) $message,
                ];
            }
        }

        return $details;
    }

    private static function resolveRequestId(Request $request): ?string
    {
        $headerValue = trim((string) $request->headers->get('X-Request-Id', ''));
        if ($headerValue !== '') {
            return $headerValue;
        }

        $attributeValue = trim((string) $request->attributes->get('request_id', ''));
        return $attributeValue !== '' ? $attributeValue : null;
    }
}
