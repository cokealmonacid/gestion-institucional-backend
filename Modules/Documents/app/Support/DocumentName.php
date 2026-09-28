<?php

namespace Modules\Documents\Support;

final class DocumentName
{
    public static function canonicalize(mixed $value): string
    {
        if (! is_string($value)) {
            throw new \InvalidArgumentException('The document name must be a string.');
        }

        $name = \Normalizer::normalize(trim($value), \Normalizer::FORM_C);

        if ($name === false) {
            throw new \InvalidArgumentException('The document name must be valid Unicode.');
        }

        if (mb_strlen($name, 'UTF-8') < 1 || mb_strlen($name, 'UTF-8') > 255) {
            throw new \InvalidArgumentException('The document name must contain between 1 and 255 characters.');
        }

        if (preg_match('/[\/\\\\\p{Cc}]/u', $name) === 1) {
            throw new \InvalidArgumentException('The document name contains prohibited characters.');
        }

        return $name;
    }
}
