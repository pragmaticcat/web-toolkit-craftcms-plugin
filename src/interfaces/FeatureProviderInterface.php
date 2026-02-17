<?php

namespace pragmatic\webtoolkit\interfaces;

interface FeatureProviderInterface
{
    public static function domainKey(): string;

    public static function navLabel(): string;

    public static function cpSubpath(): string;

    /**
     * @return array<string, string>
     */
    public function cpRoutes(): array;

    /**
     * @return array<string, string>
     */
    public function siteRoutes(): array;

    /**
     * @return array<string, array{label:string}>
     */
    public function permissions(): array;

    public function injectFrontendHtml(string $html): string;
}
