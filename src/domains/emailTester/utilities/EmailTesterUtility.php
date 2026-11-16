<?php

namespace pragmatic\webtoolkit\domains\emailTester\utilities;

use CraftCms\Cms\Utility\Utility;
use Illuminate\Support\Facades\Gate;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Flash;

use function CraftCms\Cms\t;
use function CraftCms\Cms\template;

class EmailTesterUtility extends Utility
{
    public static function displayName(): string
    {
        return t('Email tester', [], 'pragmatic-web-toolkit');
    }

    public static function id(): string
    {
        return 'pragmatic-email-tester';
    }

    public static function contentHtml(): string
    {
        if (!Gate::check('pragmaticWebToolkit:useEmailTester')) {
            throw new AccessDeniedHttpException('You are not allowed to use the email tester.');
        }

        try {
            $templates = PragmaticWebToolkit::$plugin->emailTester->getTemplates();
            $values = PragmaticWebToolkit::$plugin->emailTester->defaultFormValues();
        } catch (\InvalidArgumentException $e) {
            Flash::error($e->getMessage());
            $templates = [];
            $values = [
                'templateKey' => '',
                'siteId' => (int)Sites::getPrimarySite()->id,
                'userId' => null,
                'recipient' => '',
                'subject' => '',
                'additionalVariables' => '',
            ];
        }

        return template('pragmatic-web-toolkit/email-tester/_content', [
            'templates' => $templates,
            'templateSubjectsBySite' => $templates === [] ? [] : PragmaticWebToolkit::$plugin->emailTester->subjectsBySite(),
            'sites' => Sites::getAllSites()->all(),
            'values' => $values,
            'selectedUser' => null,
            'previewHtml' => null,
        ]);
    }
}
