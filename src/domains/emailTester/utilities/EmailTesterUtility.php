<?php

namespace pragmatic\webtoolkit\domains\emailTester\utilities;

use Craft;
use craft\base\Utility;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use yii\base\InvalidArgumentException;
use yii\web\ForbiddenHttpException;

class EmailTesterUtility extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('pragmatic-web-toolkit', 'Email tester');
    }

    public static function id(): string
    {
        return 'pragmatic-email-tester';
    }

    public static function contentHtml(): string
    {
        if (!Craft::$app->getUser()->checkPermission('pragmaticWebToolkit:useEmailTester')) {
            throw new ForbiddenHttpException('You are not allowed to use the email tester.');
        }

        try {
            $templates = PragmaticWebToolkit::$plugin->emailTester->getTemplates();
            $values = PragmaticWebToolkit::$plugin->emailTester->defaultFormValues();
        } catch (InvalidArgumentException $e) {
            Craft::$app->getSession()->setError($e->getMessage());
            $templates = [];
            $values = [
                'templateKey' => '',
                'siteId' => (int)Craft::$app->getSites()->getPrimarySite()->id,
                'userId' => null,
                'recipient' => '',
                'subject' => '',
                'additionalVariables' => '',
            ];
        }

        return Craft::$app->getView()->renderTemplate('pragmatic-web-toolkit/email-tester/_content', [
            'templates' => $templates,
            'templateSubjectsBySite' => $templates === [] ? [] : PragmaticWebToolkit::$plugin->emailTester->subjectsBySite(),
            'sites' => Craft::$app->getSites()->getAllSites(),
            'values' => $values,
            'selectedUser' => null,
            'previewHtml' => null,
        ]);
    }
}
