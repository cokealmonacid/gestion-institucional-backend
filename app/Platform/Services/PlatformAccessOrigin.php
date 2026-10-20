<?php

namespace App\Platform\Services;

class PlatformAccessOrigin
{
    public static function allowed(mixed $url): bool
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        if (! $parts || ! isset($parts['host'])
            || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) !== []
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return false;
        }

        return ($parts['scheme'] ?? null) === 'https'
            || (($parts['scheme'] ?? null) === 'http'
                && config('app.env') === 'local'
                && config('platform.allow_local_http') === true
                && in_array($parts['host'], ['localhost', '127.0.0.1'], true));
    }
}
