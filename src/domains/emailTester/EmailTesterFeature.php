<?php

namespace pragmatic\webtoolkit\domains\emailTester;

use pragmatic\webtoolkit\interfaces\FeatureProviderInterface;

class EmailTesterFeature implements FeatureProviderInterface
{
    public static function domainKey(): string { return 'emailTester'; }
    public static function navLabel(): string { return 'Email tester'; }
    public static function cpSubpath(): string { return 'email-tester'; }

    public function cpRoutes(): array
    {
        return [
            'pragmatic-toolkit/email-tester' => 'pragmatic-web-toolkit/email-tester/index',
            'pragmatic-toolkit/email-tester/templates' => 'pragmatic-web-toolkit/email-tester/templates',
            'pragmatic-toolkit/email-tester/save-templates' => 'pragmatic-web-toolkit/email-tester/save-templates',
        ];
    }

    public function siteRoutes(): array { return []; }

    public function permissions(): array
    {
        return ['pragmaticWebToolkit:useEmailTester' => ['label' => 'Use the email tester']];
    }

    public function injectFrontendHtml(string $html): string { return $html; }
}
