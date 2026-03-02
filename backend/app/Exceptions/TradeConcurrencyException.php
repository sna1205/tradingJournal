<?php

namespace App\Exceptions;

use RuntimeException;

class TradeConcurrencyException extends RuntimeException
{
    public function __construct(
        private readonly int $currentRevision,
        private readonly string $currentUpdatedAt,
        private readonly string $currentEtag,
        string $message = 'Trade update conflict. The trade has been modified by another request.'
    ) {
        parent::__construct($message);
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
