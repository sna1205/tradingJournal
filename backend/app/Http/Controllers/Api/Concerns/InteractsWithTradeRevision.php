<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Trade;
use App\Support\ApiErrorResponder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait InteractsWithTradeRevision
{
    protected function buildTradeEtag(Trade $trade): string
    {
        $updatedAt = $trade->updated_at?->toISOString() ?? '';

        return sprintf('"%d:%s"', (int) $trade->revision, $updatedAt);
    }

    protected function assertTradeWritePrecondition(Request $request, Trade $trade): void
    {
        $ifMatch = trim((string) $request->header('If-Match', ''));
        if ($ifMatch === '') {
            throw new HttpResponseException(
                $this->tradePreconditionResponse(
                    request: $request,
                    trade: $trade,
                    status: 428,
                    code: 'trade_if_match_required',
                    message: 'If-Match header with current trade revision is required.'
                )
            );
        }

        $expectedRevision = $this->extractExpectedRevision($ifMatch);
        $currentRevision = (int) $trade->revision;

        if ($expectedRevision === null || $expectedRevision !== $currentRevision) {
            throw new HttpResponseException(
                $this->tradePreconditionResponse(
                    request: $request,
                    trade: $trade,
                    status: 412,
                    code: 'trade_precondition_failed',
                    message: 'Trade revision no longer matches latest server state.'
                )
            );
        }
    }

    protected function bumpTradeRevision(Trade $trade): Trade
    {
        $trade->revision = (int) $trade->revision + 1;
        $trade->save();

        return $trade->fresh();
    }

    private function tradePreconditionResponse(
        Request $request,
        Trade $trade,
        int $status,
        string $code,
        string $message
    ): JsonResponse {
        $current = [
            'revision' => (int) $trade->revision,
            'updated_at' => $trade->updated_at?->toISOString() ?? '',
            'etag' => $this->buildTradeEtag($trade),
        ];

        $response = ApiErrorResponder::errorV2(
            request: $request,
            status: $status,
            code: $code,
            message: $message,
            details: [[
                'field' => 'If-Match',
                'message' => $message,
            ]],
            meta: [
                'current' => $current,
            ]
        );

        $payload = $response->getData(true);
        $payload['current'] = $current;

        return $response
            ->setData($payload)
            ->header('ETag', $current['etag']);
    }

    private function extractExpectedRevision(?string $ifMatch): ?int
    {
        if (! is_string($ifMatch) || trim($ifMatch) === '') {
            return null;
        }

        $candidate = trim($ifMatch);
        if (str_starts_with($candidate, 'W/')) {
            $candidate = substr($candidate, 2);
        }

        $candidate = trim($candidate, "\" ");
        if (str_contains($candidate, ':')) {
            $candidate = (string) strtok($candidate, ':');
        }

        if (! is_numeric($candidate)) {
            return null;
        }

        $revision = (int) $candidate;

        return $revision > 0 ? $revision : null;
    }
}

