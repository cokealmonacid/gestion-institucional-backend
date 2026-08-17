<?php

namespace Modules\Nodes\Support;

final class NodeName
{
    /** @return array{display: string, normalized: string, fingerprint: string} */
    public static function normalize(mixed $value): array
    {
        if (! is_string($value)) {
            throw new \InvalidArgumentException('The node name must be a string.');
        }

        $display = trim($value);
        $display = \Normalizer::normalize($display, \Normalizer::FORM_C);

        if ($display === false) {
            throw new \InvalidArgumentException('The node name must be valid Unicode.');
        }

        if (mb_strlen($display, 'UTF-8') < 1 || mb_strlen($display, 'UTF-8') > 255) {
            throw new \InvalidArgumentException('The node name must contain between 1 and 255 characters.');
        }

        if (preg_match('/[\/\\\\\p{Cc}]/u', $display) === 1) {
            throw new \InvalidArgumentException('The node name contains prohibited characters.');
        }

        $normalized = mb_convert_case($display, MB_CASE_FOLD, 'UTF-8');

        return [
            'display' => $display,
            'normalized' => $normalized,
            'fingerprint' => hash('sha256', $normalized),
        ];
    }
}
