<?php

namespace pragmatic\webtoolkit\domains\emailTester\services;

use Craft;
use craft\base\Component;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\helpers\App;
use craft\helpers\Markdown;
use craft\helpers\Template;
use craft\web\View;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use yii\base\InvalidArgumentException;
use yii\validators\EmailValidator;

class EmailTesterService extends Component
{
    /** @return array<string,array{label:string,template:string,subject:string,requiredVariables:string[]}> */
    public function getTemplates(): array
    {
        return $this->getCraftSystemTemplates() + $this->getManagedTemplates();
    }

    /** @return array<string,array{label:string,template:string,subject:string,requiredVariables:string[]}> */
    public function getManagedTemplates(): array
    {
        $settings = PragmaticWebToolkit::$plugin->getSettings();
        $stored = PragmaticWebToolkit::$plugin->domainSettingsStore->get(
            'emailTester',
            (array)($settings->emailTester ?? [])
        );
        return $this->validateTemplates((array)($stored['templates'] ?? []));
    }

    public function saveTemplateRows(array $rows): bool
    {
        $templates = $this->normalizeTemplateRows($rows);
        $this->validateTemplates($templates);

        return PragmaticWebToolkit::$plugin->domainSettingsStore->save('emailTester', [
            'templates' => $templates,
        ]);
    }

    /** @return array<string,array{label:string,template:string,subject:string,requiredVariables:string[]}> */
    public function normalizeTemplateRows(array $rows): array
    {
        $templates = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Each template row must be valid.');
            }
            $key = trim((string)($row['key'] ?? ''));
            if ($key === '' || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $key)) {
                throw new InvalidArgumentException('Each template needs a unique key starting with a letter and containing only letters, numbers, hyphens or underscores.');
            }
            if (isset($templates[$key])) {
                throw new InvalidArgumentException("The template key '{$key}' is duplicated.");
            }
            $required = $row['requiredVariables'] ?? [];
            if (is_string($required)) {
                $required = preg_split('/\s*,\s*/', trim($required), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }
            $templates[$key] = [
                'label' => trim((string)($row['label'] ?? '')),
                'template' => trim((string)($row['template'] ?? '')),
                'subject' => trim((string)($row['subject'] ?? '')),
                'requiredVariables' => array_values(array_unique((array)$required)),
            ];
        }
        return $templates;
    }

    public function getTemplateRows(): array
    {
        $rows = [];
        foreach ($this->getManagedTemplates() as $key => $definition) {
            $rows[] = [
                'key' => $key,
                'label' => $definition['label'],
                'template' => $definition['template'],
                'subject' => $definition['subject'],
                'requiredVariables' => implode(', ', $definition['requiredVariables']),
            ];
        }
        return $rows;
    }

    /** @return array<string,array{label:string,template:string,subject:string,requiredVariables:string[]}> */
    public function validateTemplates(array $templates): array
    {
        $validated = [];
        foreach ($templates as $key => $definition) {
            if (!is_string($key) || $key === '' || !is_array($definition)) {
                throw new InvalidArgumentException('Each email template must have a non-empty key and a definition.');
            }
            foreach (['label', 'template', 'subject'] as $field) {
                if (!isset($definition[$field]) || !is_string($definition[$field]) || trim($definition[$field]) === '') {
                    throw new InvalidArgumentException("Email template '{$key}' requires a non-empty {$field}.");
                }
            }
            $required = $definition['requiredVariables'] ?? [];
            if (!is_array($required) || array_filter($required, static fn($value): bool => !is_string($value) || trim($value) === '') !== []) {
                throw new InvalidArgumentException("Email template '{$key}' has invalid requiredVariables.");
            }
            $validated[$key] = [
                'label' => trim($definition['label']),
                'template' => trim($definition['template']),
                'subject' => trim($definition['subject']),
                'requiredVariables' => array_values(array_unique($required)),
            ];
        }
        return $validated;
    }

    public function decodeAdditionalVariables(?string $json): array
    {
        if (trim((string)$json) === '') {
            return [];
        }
        try {
            $variables = json_decode((string)$json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('The additional variables JSON is invalid: ' . $e->getMessage());
        }
        if (!is_array($variables) || array_is_list($variables)) {
            throw new InvalidArgumentException('Additional variables must be a JSON object.');
        }
        return $variables;
    }

    public function validateRequiredVariables(array $required, array $variables): void
    {
        $missing = array_values(array_filter(
            $required,
            static fn(string $name): bool => !array_key_exists($name, $variables) || $variables[$name] === null
        ));
        if ($missing !== []) {
            throw new InvalidArgumentException('Missing required variables: ' . implode(', ', $missing) . '.');
        }
    }

    /** @return array{html:string,subject:string,recipient:string,user:User,values:array} */
    public function preview(array $input): array
    {
        $context = $this->buildContext($input);
        $systemMessageKey = $context['definition']['systemMessageKey'] ?? null;
        $context['html'] = is_string($systemMessageKey)
            ? $this->renderSystemMessageForSite($systemMessageKey, $context['variables'], $context['siteId'])
            : $this->renderForSite($context['definition']['template'], $context['variables'], $context['siteId']);
        return $context;
    }

    public function send(array $input): bool
    {
        $context = $this->preview($input);
        return $this->deliver($context['recipient'], $context['subject'], $context['html']);
    }

    protected function deliver(string $recipient, string $subject, string $html): bool
    {
        return Craft::$app->getMailer()->compose()
            ->setTo($recipient)
            ->setSubject($subject)
            ->setHtmlBody($html)
            ->send();
    }

    public function defaultFormValues(): array
    {
        $templates = $this->getTemplates();
        $key = (string)(array_key_first($templates) ?? '');
        return [
            'templateKey' => $key,
            'siteId' => (int)Craft::$app->getSites()->getPrimarySite()->id,
            'userId' => null,
            'recipient' => '',
            'subject' => $key !== '' ? $this->subjectForSite($key, (int)Craft::$app->getSites()->getPrimarySite()->id) : '',
            'additionalVariables' => '',
        ];
    }

    public function subjectsBySite(): array
    {
        $subjects = [];
        $templates = $this->getTemplates();
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            foreach ($templates as $key => $definition) {
                $subjects[(string)$site->id][$key] = $this->subjectForDefinition($definition, (int)$site->id);
            }
        }
        return $subjects;
    }

    private function subjectForSite(string $templateKey, int $siteId): string
    {
        $templates = $this->getTemplates();
        $definition = $templates[$templateKey] ?? null;
        if (!is_array($definition)) {
            return '';
        }
        return $this->subjectForDefinition($definition, $siteId);
    }

    private function subjectForDefinition(array $definition, int $siteId): string
    {
        $systemKey = $definition['systemMessageKey'] ?? null;
        if (!is_string($systemKey)) {
            return (string)$definition['subject'];
        }
        $site = Craft::$app->getSites()->getSiteById($siteId);
        if ($site === null) {
            return (string)$definition['subject'];
        }
        $message = Craft::$app->getSystemMessages()->getMessage($systemKey, $site->language);
        $default = Craft::$app->getSystemMessages()->getDefaultMessage($systemKey);
        if ($message !== null && $default !== null && $message->subject !== $default->subject) {
            return (string)$message->subject;
        }
        return Craft::t('app', $systemKey . '_subject', [], $site->language);
    }

    private function buildContext(array $input): array
    {
        $templates = $this->getTemplates();
        $templateKey = trim((string)($input['templateKey'] ?? ''));
        if (!isset($templates[$templateKey])) {
            throw new InvalidArgumentException('Select a configured email template.');
        }
        $siteId = (int)($input['siteId'] ?? 0);
        if (Craft::$app->getSites()->getSiteById($siteId) === null) {
            throw new InvalidArgumentException('Select a valid site.');
        }
        $user = User::find()->id((int)($input['userId'] ?? 0))->status(null)->one();
        if (!$user instanceof User) {
            throw new InvalidArgumentException('Select a valid Craft user.');
        }
        $recipient = trim((string)($input['recipient'] ?? ''));
        if (!(new EmailValidator())->validate($recipient)) {
            throw new InvalidArgumentException('Enter a valid test recipient address.');
        }
        $subjectTemplate = trim((string)($input['subject'] ?? ''));
        if ($subjectTemplate === '') {
            throw new InvalidArgumentException('Enter an email subject.');
        }
        $variables = $this->decodeAdditionalVariables((string)($input['additionalVariables'] ?? ''));
        $variables['user'] = $user;
        if (isset($templates[$templateKey]['systemMessageKey'])) {
            $variables += [
                'link' => Template::raw('https://example.test/craft-email-test'),
                'settings' => 'Email tester preview',
            ];
        }
        $this->validateRequiredVariables($templates[$templateKey]['requiredVariables'], $variables);
        $subject = $this->renderStringForSite($subjectTemplate, $variables, $siteId);

        return [
            'definition' => $templates[$templateKey],
            'variables' => $variables,
            'siteId' => $siteId,
            'html' => '',
            'subject' => $subject,
            'recipient' => $recipient,
            'user' => $user,
            'values' => [
                'templateKey' => $templateKey,
                'siteId' => $siteId,
                'userId' => (int)$user->id,
                'recipient' => $recipient,
                'subject' => $subjectTemplate,
                'additionalVariables' => (string)($input['additionalVariables'] ?? ''),
            ],
        ];
    }

    private function renderForSite(string $template, array $variables, int $siteId): string
    {
        $view = Craft::$app->getView();
        $sites = Craft::$app->getSites();
        $site = $sites->getSiteById($siteId);
        $previousSite = $sites->getCurrentSite();
        $previousLanguage = Craft::$app->language;
        $previousMode = $view->getTemplateMode();

        try {
            $sites->setCurrentSite($site);
            Craft::$app->language = $site->language;
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);
            if (!$view->doesTemplateExist($template)) {
                throw new InvalidArgumentException("The configured email template '{$template}' does not exist.");
            }
            return $view->renderTemplate($template, $variables, View::TEMPLATE_MODE_SITE);
        } finally {
            $view->setTemplateMode($previousMode);
            Craft::$app->language = $previousLanguage;
            $sites->setCurrentSite($previousSite);
        }
    }

    private function renderStringForSite(string $value, array $variables, int $siteId): string
    {
        $sites = Craft::$app->getSites();
        $site = $sites->getSiteById($siteId);
        $previousSite = $sites->getCurrentSite();
        $previousLanguage = Craft::$app->language;
        try {
            $sites->setCurrentSite($site);
            Craft::$app->language = $site->language;
            return Craft::$app->getView()->renderSandboxedString($value, $variables);
        } finally {
            Craft::$app->language = $previousLanguage;
            $sites->setCurrentSite($previousSite);
        }
    }

    /** @return array<string,array{label:string,template:string,subject:string,requiredVariables:string[],systemMessageKey:string}> */
    private function getCraftSystemTemplates(): array
    {
        $templates = [];
        foreach (['account_activation', 'verify_new_email', 'forgot_password', 'test_email'] as $key) {
            $templates['craft:' . $key] = [
                'label' => 'Craft · ' . Craft::t('app', $key . '_heading'),
                'template' => '@craft/system-message/' . $key,
                'subject' => Craft::t('app', $key . '_subject'),
                'requiredVariables' => ['user'],
                'systemMessageKey' => $key,
            ];
        }
        return $templates;
    }

    private function renderSystemMessageForSite(string $key, array $variables, int $siteId): string
    {
        $view = Craft::$app->getView();
        $sites = Craft::$app->getSites();
        $site = $sites->getSiteById($siteId);
        $previousSite = $sites->getCurrentSite();
        $previousLanguage = Craft::$app->language;
        $previousMode = $view->getTemplateMode();

        try {
            $sites->setCurrentSite($site);
            Craft::$app->language = $site->language;
            $message = Craft::$app->getSystemMessages()->getMessage($key, $site->language);
            if ($message === null) {
                throw new InvalidArgumentException("Craft system message '{$key}' does not exist.");
            }
            $defaultMessage = Craft::$app->getSystemMessages()->getDefaultMessage($key);
            $messageBody = $defaultMessage !== null && $message->body === $defaultMessage->body
                ? Craft::t('app', $key . '_body', [], $site->language)
                : (string)$message->body;

            $mailSettings = App::mailSettings();
            $variables += [
                'emailKey' => $key,
                'fromEmail' => App::parseEnv($mailSettings->fromEmail),
                'replyToEmail' => App::parseEnv($mailSettings->replyToEmail),
                'fromName' => App::parseEnv($mailSettings->fromName),
                'language' => $site->language,
            ];
            $body = $view->renderSandboxedString($messageBody, $variables, escapeHtml: true);
            $mailerTemplate = Craft::$app->edition->value >= CmsEdition::Pro->value
                ? trim((string)Craft::$app->getMailer()->template)
                : '';
            $template = $mailerTemplate !== '' ? $mailerTemplate : '_special/email.twig';
            $mode = $mailerTemplate !== '' ? View::TEMPLATE_MODE_SITE : View::TEMPLATE_MODE_CP;

            return $view->renderTemplate($template, $variables + [
                'body' => Template::raw(Markdown::process($body, 'gfm-comment')),
            ], $mode);
        } finally {
            $view->setTemplateMode($previousMode);
            Craft::$app->language = $previousLanguage;
            $sites->setCurrentSite($previousSite);
        }
    }
}
