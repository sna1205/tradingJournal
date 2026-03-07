<?php

namespace App\Exceptions;

use RuntimeException;

class ChecklistConcurrencyException extends RuntimeException
{
    public function __construct(
        private readonly int $currentRevision,
        private readonly string $currentUpdatedAt,
        private readonly string $currentEtag
    ) {
        parent::__construct('Checklist update conflict. The checklist has been modified by another request.');
    }

    public function currentRevision(): int
    {
        return $this->currentRevision;
    }

    public function currentUpdatedAt(): string
    {
        return $this->currentUpdatedAt;
    }

    public function currentEtag(): string
    {
        return $this->currentEtag;
    }
}
