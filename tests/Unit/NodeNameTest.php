<?php

namespace Tests\Unit;

use InvalidArgumentException;
use Modules\Nodes\Support\NodeName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NodeNameTest extends TestCase
{
    public function test_it_trims_normalizes_nfc_and_builds_a_case_insensitive_key(): void
    {
        $composed = NodeName::normalize('  Área_2026.test  ');
        $decomposed = NodeName::normalize("A\u{0301}REA_2026.TEST");

        $this->assertSame('Área_2026.test', $composed['display']);
        $this->assertSame($composed['normalized'], $decomposed['normalized']);
        $this->assertSame($composed['fingerprint'], $decomposed['fingerprint']);
    }

    #[DataProvider('invalidNames')]
    public function test_it_rejects_invalid_names(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        NodeName::normalize($name);
    }

    public static function invalidNames(): array
    {
        return [
            'empty after trim' => ['   '],
            'slash' => ['Area/Legal'],
            'backslash' => ['Area\\Legal'],
            'control' => ["Area\nLegal"],
            'too long' => [str_repeat('á', 256)],
        ];
    }

    public function test_it_accepts_the_boundaries(): void
    {
        $this->assertSame('x', NodeName::normalize('x')['display']);
        $this->assertSame(255, mb_strlen(NodeName::normalize(str_repeat('ñ', 255))['display']));
    }
}
