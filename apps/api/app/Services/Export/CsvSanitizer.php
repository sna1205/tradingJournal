<?php

namespace App\Services\Export;

class CsvSanitizer
{
    /**
     * @param array<int, mixed> $row
     * @return array<int, mixed>
     */
    public function sanitizeRow(array $row): array
    {
        return array_map(
            fn (mixed $value): mixed => $this->sanitizeCell($value),
            $row
        );
    }

    public function sanitizeCell(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $trimmed = ltrim($value);
        if ($trimmed === '') {
            return $value;
        }

        $firstChar = $trimmed[0];
        if (in_array($firstChar, ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }
}
