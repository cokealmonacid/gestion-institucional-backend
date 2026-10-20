<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;

class HttpFoundationHeaderParsingTest extends TestCase
{
    public function test_whitespace_separators_still_split_values_after_the_security_fix(): void
    {
        // CVE-2026-108082's initial fixes regressed splitting on whitespace.
        $this->assertSame(['foo', 'bar'], HeaderUtils::split('foo bar', ' '));
        $this->assertSame(['foo', 'bar'], HeaderUtils::split("foo\tbar", "\t"));
        $this->assertSame(['foo bar', 'baz'], HeaderUtils::split('"foo bar" baz', ' '));
    }

    public function test_whitespace_before_a_separator_preserves_directives_with_bounded_backtracking(): void
    {
        // Fixed input/iteration sizes and a low PCRE budget bound this local probe.
        // Assert parser errors as well as values; never accept silently lost directives.
        $previousLimit = ini_set('pcre.backtrack_limit', '10000');
        $this->assertNotFalse($previousLimit);
        try {
            foreach ([16, 32, 64] as $spaces) {
                $parts = HeaderUtils::split('private'.str_repeat(' ', $spaces).', max-age=60', ',=');
                $this->assertSame(PREG_NO_ERROR, preg_last_error());
                $this->assertSame([['private'], ['max-age', '60']], $parts);
            }
        } finally {
            ini_set('pcre.backtrack_limit', $previousLimit);
        }
    }

    public function test_request_cache_control_retains_directives_with_whitespace_before_a_comma(): void
    {
        $previousLimit = ini_set('pcre.backtrack_limit', '10000');
        $this->assertNotFalse($previousLimit);
        try {
            // Construct a request in memory; no HTTP client or deployed endpoint.
            $request = Request::create('/synthetic-header-regression', 'GET', [], [], [], [
                'HTTP_CACHE_CONTROL' => 'private'.str_repeat(' ', 64).', max-age=60',
            ]);
            $this->assertTrue($request->headers->hasCacheControlDirective('private'));
            $this->assertSame('60', $request->headers->getCacheControlDirective('max-age'));
        } finally {
            ini_set('pcre.backtrack_limit', $previousLimit);
        }
    }
}
