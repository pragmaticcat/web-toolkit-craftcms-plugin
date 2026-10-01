<?php

namespace pragmatic\webtoolkit\domains\emailTester\services;

use Craft;
use craft\base\Component;
use craft\elements\User;
use craft\web\View;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use yii\base\InvalidArgumentException;
use yii\validators\EmailValidator;

class EmailTesterService extends Component
{
    /** @return array<string,array{label:string,template:string,subject:string,requiredVariables:string[]}> */
    public function getTemplates(): array
    {
        $settings = PragmaticWebToolkit::$plugin->getSettings();
        return $this->validateTemplates((array)($settings->emailTester['templates'] ?? []));
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
        $context['html'] = $this->renderForSite($context['definition']['template'], $context['variables'], $context['siteId']);
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
            'subject' => $key !== '' ? $templates[$key]['subject'] : '',
            'additionalVariables' => '',
        ];
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
        $subject = trim((string)($input['subject'] ?? ''));
        if ($subject === '') {
            throw new InvalidArgumentException('Enter an email subject.');
        }
        $variables = $this->decodeAdditionalVariables((string)($input['additionalVariables'] ?? ''));
        $variables['user'] = $user;
        $this->validateRequiredVariables($templates[$templateKey]['requiredVariables'], $variables);

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
                'subject' => $subject,
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
}
