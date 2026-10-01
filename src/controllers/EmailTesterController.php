<?php

namespace pragmatic\webtoolkit\controllers;

use Craft;
use craft\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use yii\base\InvalidArgumentException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class EmailTesterController extends Controller
{
    public const PERMISSION = 'pragmaticWebToolkit:useEmailTester';

    public function beforeAction($action): bool
    {
        $this->requireCpRequest();
        $this->requirePermission(self::PERMISSION);
        if (!PragmaticWebToolkit::$plugin->domains->isEnabled('emailTester')) {
            throw new NotFoundHttpException('The email tester is disabled.');
        }
        if (in_array($action->id, ['preview', 'send', 'save-templates'], true)) {
            $this->requirePostRequest();
        }
        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        try {
            $values = PragmaticWebToolkit::$plugin->emailTester->defaultFormValues();
        } catch (InvalidArgumentException $e) {
            Craft::$app->getSession()->setError($e->getMessage());
            $values = $this->emptyValues();
        }
        return $this->renderPage($values);
    }

    public function actionPreview(): Response
    {
        $values = $this->postedValues();
        try {
            $result = PragmaticWebToolkit::$plugin->emailTester->preview($values);
            Craft::$app->getSession()->setNotice('Email preview rendered. No email was sent.');
            return $this->renderPage($result['values'], $result['user'], $result['html']);
        } catch (InvalidArgumentException $e) {
            Craft::$app->getSession()->setError($e->getMessage());
        } catch (\Throwable $e) {
            Craft::error('Email preview failed (' . $e::class . ').', __METHOD__);
            Craft::$app->getSession()->setError('The email could not be rendered. Check the template and the application log.');
        }
        return $this->renderPage($values, $this->selectedUser($values));
    }

    public function actionTemplates(): Response
    {
        try {
            $rows = PragmaticWebToolkit::$plugin->emailTester->getTemplateRows();
        } catch (InvalidArgumentException $e) {
            Craft::$app->getSession()->setError($e->getMessage());
            $rows = [];
        }

        return $this->renderTemplate('pragmatic-web-toolkit/email-tester/templates', [
            'templateRows' => $rows,
        ]);
    }

    public function actionSaveTemplates(): Response
    {
        $rows = (array)Craft::$app->getRequest()->getBodyParam('templates', []);
        try {
            if (!PragmaticWebToolkit::$plugin->emailTester->saveTemplateRows($rows)) {
                throw new \RuntimeException('Settings store returned false.');
            }
            Craft::$app->getSession()->setNotice('Email templates saved.');
        } catch (InvalidArgumentException $e) {
            Craft::$app->getSession()->setError($e->getMessage());
            return $this->renderTemplate('pragmatic-web-toolkit/email-tester/templates', [
                'templateRows' => $rows,
            ]);
        } catch (\Throwable $e) {
            Craft::error('Email template settings could not be saved (' . $e::class . ').', __METHOD__);
            Craft::$app->getSession()->setError('The email templates could not be saved. Check the application log.');
        }

        return $this->redirectToPostedUrl();
    }

    public function actionSend(): Response
    {
        $values = $this->postedValues();
        try {
            if (!PragmaticWebToolkit::$plugin->emailTester->send($values)) {
                throw new \RuntimeException('Mailer returned false.');
            }
            Craft::$app->getSession()->setNotice('Test email sent successfully.');
        } catch (InvalidArgumentException $e) {
            Craft::$app->getSession()->setError($e->getMessage());
        } catch (\Throwable $e) {
            Craft::error('Test email delivery failed (' . $e::class . ').', __METHOD__);
            Craft::$app->getSession()->setError('The test email could not be sent. Check the mailer and the application log.');
        }
        return $this->renderPage($values, $this->selectedUser($values));
    }

    private function renderPage(array $values, mixed $selectedUser = null, ?string $previewHtml = null): Response
    {
        try {
            $templates = PragmaticWebToolkit::$plugin->emailTester->getTemplates();
        } catch (InvalidArgumentException $e) {
            Craft::$app->getSession()->setError($e->getMessage());
            $templates = [];
        }
        return $this->renderTemplate('pragmatic-web-toolkit/email-tester/index', [
            'templates' => $templates,
            'sites' => Craft::$app->getSites()->getAllSites(),
            'values' => $values,
            'selectedUser' => $selectedUser,
            'previewHtml' => $previewHtml,
        ]);
    }

    private function postedValues(): array
    {
        return (array)Craft::$app->getRequest()->getBodyParam('emailTester', []);
    }

    private function selectedUser(array $values): mixed
    {
        $id = (int)($values['userId'] ?? 0);
        return $id > 0 ? Craft::$app->getUsers()->getUserById($id) : null;
    }

    private function emptyValues(): array
    {
        return [
            'templateKey' => '',
            'siteId' => (int)Craft::$app->getSites()->getPrimarySite()->id,
            'userId' => null,
            'recipient' => '',
            'subject' => '',
            'additionalVariables' => '',
        ];
    }
}
