<?php

namespace Tests\Unit;

use App\Services\Export\CsvSanitizer;
use PHPUnit\Framework\TestCase;

class CsvSanitizerTest extends TestCase
{
    public function test_it_prefixes_formula_like_cells(): void
    {
        $sanitizer = new CsvSanitizer();

        $this->assertSame("'=SUM(A1:A2)", $sanitizer->sanitizeCell('=SUM(A1:A2)'));
        $this->assertSame("'+1+2", $sanitizer->sanitizeCell('+1+2'));
        $this->assertSame("'-42", $sanitizer->sanitizeCell('-42'));
        $this->assertSame("'@HYPERLINK(\"https://example.com\")", $sanitizer->sanitizeCell('@HYPERLINK("https://example.com")'));
        $this->assertSame("'  =1+1", $sanitizer->sanitizeCell('  =1+1'));
    }

    public function test_it_keeps_safe_values_unchanged(): void
    {
        $sanitizer = new CsvSanitizer();

        $this->assertSame('EURUSD', $sanitizer->sanitizeCell('EURUSD'));
        $this->assertSame(' already plain', $sanitizer->sanitizeCell(' already plain'));
        $this->assertSame('', $sanitizer->sanitizeCell(''));
        $this->assertSame(123, $sanitizer->sanitizeCell(123));
        $this->assertSame(true, $sanitizer->sanitizeCell(true));
    }
}
