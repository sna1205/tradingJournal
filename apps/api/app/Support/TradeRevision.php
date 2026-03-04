<?php

namespace App\Support;

use App\Models\Trade;

class TradeRevision
{
    public static function buildEtag(Trade $trade): string
    {
        $updatedAt = $trade->updated_at?->toISOString() ?? '';

        return sprintf('"%d:%s"', (int) $trade->revision, $updatedAt);
    }

    public static function extractExpectedRevision(?string $ifMatch): ?int
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

