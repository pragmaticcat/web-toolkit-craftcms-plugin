<?php

namespace pragmatic\webtoolkit\domains\redirects\services;

/**
 * Normalizes request paths and evaluates redirect rule match types.
 */
class RedirectMatcher
{
    /**
     * Normalize a URL path or URI for matching.
     *
     * @param string $path Path, optionally including query string or host.
     * @return string Normalized path without outer slashes or query string.
     */
    public function normalize(string $path): string
    {
        $path = parse_url($path, PHP_URL_PATH) ?: '';
        $path = rawurldecode($path);
        return mb_strtolower(trim($path, '/'));
    }

    /**
     * Compile a Craft-style token pattern to an anchored regular expression.
     *
     * @param string $pattern Source pattern.
     * @return string Compiled expression.
     */
    public function compilePattern(string $pattern): string
    {
        $parts = preg_split('/(<[a-zA-Z][a-zA-Z0-9_]*>)/', $this->normalize($pattern), -1, PREG_SPLIT_DELIM_CAPTURE);
        $regex = '';
        foreach ($parts ?: [] as $part) {
            if (preg_match('/^<([a-zA-Z][a-zA-Z0-9_]*)>$/', $part, $match)) {
                $regex .= '(?P<' . $match[1] . '>[^/]+)';
            } else {
                $regex .= preg_quote($part, '#');
            }
        }
        return '#^' . $regex . '$#iu';
    }

    /**
     * Test a source against a rule and return regex captures when present.
     *
     * @param string $path Request path.
     * @param string $source Rule source.
     * @param string $matchType Rule match type.
     * @return array<int|string, string>|null Captures or null when unmatched.
     */
    public function match(string $path, string $source, string $matchType): ?array
    {
        if ($matchType === 'exact') {
            return hash_equals($this->normalize($source), $this->normalize($path)) ? [] : null;
        }
        return $this->matchCompiled($path, $matchType, $matchType === 'pattern' ? $this->compilePattern($source) : $this->compileRegex($source));
    }

    /**
     * Compile a regular-expression source for repeated matching.
     *
     * @param string $source Regular expression source.
     * @return string Delimited expression.
     */
    public function compileRegex(string $source): string
    {
        return '#' . str_replace('#', '\\#', $this->normalizeRegex($source)) . '#iu';
    }

    /**
     * Match a path against a precompiled pattern or regex.
     *
     * @param string $path Request path.
     * @param string $matchType Match type.
     * @param string $compiled Compiled expression.
     * @return array<int|string, string>|null Captures or null when unmatched.
     */
    public function matchCompiled(string $path, string $matchType, string $compiled): ?array
    {
        if (!in_array($matchType, ['pattern', 'regex'], true)) {
            return null;
        }
        $matched = @preg_match($compiled, $this->normalize($path), $captures);
        return $matched === 1 ? $captures : null;
    }

    /**
     * Check whether a regex source compiles.
     *
     * @param string $source Regex source.
     * @return bool Whether the expression is valid.
     */
    public function isValidRegex(string $source): bool
    {
        return @preg_match($this->compileRegex($source), '') !== false;
    }

    /**
     * Expand captures in a destination.
     *
     * @param string $destination Destination template.
     * @param array<int|string, string> $captures Match captures.
     * @return string Expanded destination.
     */
    public function expandDestination(string $destination, array $captures): string
    {
        foreach ($captures as $key => $value) {
            if (is_int($key)) {
                $destination = str_replace('$' . $key, $value, $destination);
            }
            if (is_string($key)) {
                $destination = str_replace('<' . $key . '>', $value, $destination);
            }
        }
        return $destination;
    }

    /**
     * Determine whether a proposed redirect is a direct self redirect.
     *
     * @param string $source Source path.
     * @param string $destination Destination path or URL.
     * @return bool Whether both paths normalize identically.
     */
    public function isSelfRedirect(string $source, string $destination): bool
    {
        return $this->normalize($source) !== '' && $this->normalize($source) === $this->normalize($destination);
    }

    /**
     * Detect a direct or two-rule loop using a provided exact-rule map.
     *
     * @param string $source Proposed source.
     * @param string $destination Proposed destination.
     * @param array<string, string> $exactRules Normalized source to destination map.
     * @return bool Whether destination leads directly back to source.
     */
    public function createsSimpleLoop(string $source, string $destination, array $exactRules): bool
    {
        $source = $this->normalize($source);
        $destination = $this->normalize($destination);
        return $this->isSelfRedirect($source, $destination)
            || (isset($exactRules[$destination]) && $this->normalize($exactRules[$destination]) === $source);
    }

    /**
     * Normalize regex source without treating regex question marks as query delimiters.
     *
     * @param string $source Regular expression.
     * @return string Normalized expression.
     */
    private function normalizeRegex(string $source): string
    {
        return rawurldecode(trim($source, '/'));
    }
}
