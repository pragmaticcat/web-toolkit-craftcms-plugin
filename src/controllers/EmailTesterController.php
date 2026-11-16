<?php

namespace pragmatic\webtoolkit\controllers;

use pragmatic\webtoolkit\web\Controller;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\Users;
use CraftCms\Cms\Support\Flash;

class EmailTesterController extends Controller
{
    public const PERMISSION = 'pragmaticWebToolkit:useEmailTester';

    public function beforeAction(string $action): bool
    {
        $this->requireCpRequest();
        $this->requirePermission(self::PERMISSION);
        if (!PragmaticWebToolkit::$plugin->domains->isEnabled('emailTester')) {
            throw new NotFoundHttpException('The email tester is disabled.');
        }
        if (in_array($action, ['preview', 'send', 'save-templates'], true)) {
            $this->requirePostRequest();
        }
        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        try {
            $values = PragmaticWebToolkit::$plugin->emailTester->defaultFormValues();
        } catch (\InvalidArgumentException $e) {
            Flash::error($e->getMessage());
            $values = $this->emptyValues();
        }
        return $this->renderPage($values);
    }

    public function actionPreview(): Response
    {
        $values = $this->postedValues();
        try {
            $result = PragmaticWebToolkit::$plugin->emailTester->preview($values);
            Flash::notice('Email preview rendered. No email was sent.');
            return $this->renderPage($result['values'], $result['user'], $result['html']);
        } catch (\InvalidArgumentException $e) {
            Flash::error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Email preview failed (' . $e::class . ').');
            Flash::error('The email could not be rendered. Check the template and the application log.');
        }
        return $this->renderPage($values, $this->selectedUser($values));
    }

    public function actionTemplates(): Response
    {
        try {
            $rows = PragmaticWebToolkit::$plugin->emailTester->getTemplateRows();
        } catch (\InvalidArgumentException $e) {
            Flash::error($e->getMessage());
            $rows = [];
        }

        return $this->renderTemplate('pragmatic-web-toolkit/email-tester/templates', [
            'templateRows' => $rows,
        ]);
    }

    public function actionSaveTemplates(): Response
    {
        $rows = (array)request()->input('templates', []);
        try {
            if (!PragmaticWebToolkit::$plugin->emailTester->saveTemplateRows($rows)) {
                throw new \RuntimeException('Settings store returned false.');
            }
            Flash::notice('Email templates saved.');
        } catch (\InvalidArgumentException $e) {
            Flash::error($e->getMessage());
            return $this->renderTemplate('pragmatic-web-toolkit/email-tester/templates', [
                'templateRows' => $rows,
            ]);
        } catch (\Throwable $e) {
            Log::error('Email template settings could not be saved (' . $e::class . ').');
            Flash::error('The email templates could not be saved. Check the application log.');
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
            Flash::notice('Test email sent successfully.');
        } catch (\InvalidArgumentException $e) {
            Flash::error($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Test email delivery failed (' . $e::class . ').');
            Flash::error('The test email could not be sent. Check the mailer and the application log.');
        }
        return $this->renderPage($values, $this->selectedUser($values));
    }

    private function renderPage(array $values, mixed $selectedUser = null, ?string $previewHtml = null): Response
    {
        try {
            $templates = PragmaticWebToolkit::$plugin->emailTester->getTemplates();
        } catch (\InvalidArgumentException $e) {
            Flash::error($e->getMessage());
            $templates = [];
        }
        $subjects = $templates === [] ? [] : PragmaticWebToolkit::$plugin->emailTester->subjectsBySite();
        return $this->renderTemplate('pragmatic-web-toolkit/email-tester/index', [
            'templates' => $templates,
            'templateSubjectsBySite' => $subjects,
            'sites' => Sites::getAllSites()->all(),
            'values' => $values,
            'selectedUser' => $selectedUser,
            'previewHtml' => $previewHtml,
        ]);
    }

    private function postedValues(): array
    {
        return (array)request()->input('emailTester', []);
    }

    private function selectedUser(array $values): mixed
    {
        $id = (int)($values['userId'] ?? 0);
        return $id > 0 ? Users::getUserById($id) : null;
    }

    private function emptyValues(): array
    {
        return [
            'templateKey' => '',
            'siteId' => (int)Sites::getPrimarySite()->id,
            'userId' => null,
            'recipient' => '',
            'subject' => '',
            'additionalVariables' => '',
        ];
    }
}
