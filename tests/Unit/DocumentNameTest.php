<?php

namespace Tests\Unit;

use Modules\Documents\Support\DocumentName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DocumentNameTest extends TestCase
{
    public function test_it_trims_and_normalizes_unicode_without_folding_case(): void
    {
        $this->assertSame('Área', DocumentName::canonicalize("  A\u{0301}rea  "));
        $this->assertSame('AREA', DocumentName::canonicalize('AREA'));
        $this->assertNotSame(DocumentName::canonicalize('AREA'), DocumentName::canonicalize('area'));
    }

    #[DataProvider('invalidNames')]
    public function test_it_rejects_invalid_names(mixed $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DocumentName::canonicalize($name);
    }

    public static function invalidNames(): array
    {
        return [[null], ['   '], ['a/b'], ['a\\b'], ["a\nb"], [str_repeat('x', 256)]];
    }
}
