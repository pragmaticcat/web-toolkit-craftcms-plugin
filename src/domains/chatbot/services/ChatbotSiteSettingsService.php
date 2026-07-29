<?php

namespace pragmatic\webtoolkit\domains\chatbot\services;

use Craft;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use pragmatic\webtoolkit\domains\chatbot\models\SiteChatbotSettingsModel;
use yii\db\Query;

class ChatbotSiteSettingsService
{
    private const TABLE = '{{%pragmatic_toolkit_chatbot_site_settings}}';

    public function getSiteSettings(int $siteId): SiteChatbotSettingsModel
    {
        $this->ensureTable();
        $defaults = $this->buildDefaultsForSite($siteId);

        $row = (new Query())
            ->from(self::TABLE)
            ->where(['siteId' => $siteId])
            ->one();

        if (!$row) {
            $this->saveSiteSettings($siteId, $defaults->toArray());
            return $defaults;
        }

        $model = new SiteChatbotSettingsModel();
        $model->assistantName = trim((string)($row['assistantName'] ?? $defaults->assistantName));
        $model->welcomeMessage = trim((string)($row['welcomeMessage'] ?? $defaults->welcomeMessage));
        $model->placeholderText = trim((string)($row['placeholderText'] ?? $defaults->placeholderText));
        $model->popupTitle = trim((string)($row['popupTitle'] ?? $defaults->popupTitle));
        $model->launcherLabel = trim((string)($row['launcherLabel'] ?? $defaults->launcherLabel));
        $model->themePrimaryColor = trim((string)($row['themePrimaryColor'] ?? $defaults->themePrimaryColor));
        $model->themeBackgroundColor = trim((string)($row['themeBackgroundColor'] ?? $defaults->themeBackgroundColor));
        $model->themeTextColor = trim((string)($row['themeTextColor'] ?? $defaults->themeTextColor));
        $model->panelPosition = trim((string)($row['panelPosition'] ?? $defaults->panelPosition));
        $model->displayMode = trim((string)($row['displayMode'] ?? $defaults->displayMode));
        $model->borderRadius = (int)($row['borderRadius'] ?? $defaults->borderRadius);
        $model->showLauncher = (bool)($row['showLauncher'] ?? $defaults->showLauncher);
        $model->autoOpen = (bool)($row['autoOpen'] ?? $defaults->autoOpen);
        $model->allowedSections = $this->decodeJsonArray($row['allowedSections'] ?? null);
        $model->excludedSections = $this->decodeJsonArray($row['excludedSections'] ?? null);
        $model->emptyStatePrompts = $this->decodeJsonArray($row['emptyStatePrompts'] ?? null) ?: $defaults->emptyStatePrompts;
        $model->fallbackContactUrl = trim((string)($row['fallbackContactUrl'] ?? $defaults->fallbackContactUrl));
        $model->disclaimerText = trim((string)($row['disclaimerText'] ?? $defaults->disclaimerText));

        return $model;
    }

    public function saveSiteSettings(int $siteId, array $input): bool
    {
        $this->ensureTable();
        $current = $this->getSiteSettingsDefaults($siteId);
        $model = new SiteChatbotSettingsModel();

        $model->assistantName = trim((string)($input['assistantName'] ?? $current->assistantName));
        $model->welcomeMessage = trim((string)($input['welcomeMessage'] ?? $current->welcomeMessage));
        $model->placeholderText = trim((string)($input['placeholderText'] ?? $current->placeholderText));
        $model->popupTitle = trim((string)($input['popupTitle'] ?? $current->popupTitle));
        $model->launcherLabel = trim((string)($input['launcherLabel'] ?? $current->launcherLabel));
        $model->themePrimaryColor = trim((string)($input['themePrimaryColor'] ?? $current->themePrimaryColor));
        $model->themeBackgroundColor = trim((string)($input['themeBackgroundColor'] ?? $current->themeBackgroundColor));
        $model->themeTextColor = trim((string)($input['themeTextColor'] ?? $current->themeTextColor));
        $model->panelPosition = trim((string)($input['panelPosition'] ?? $current->panelPosition));
        $model->displayMode = trim((string)($input['displayMode'] ?? $current->displayMode));
        $model->borderRadius = (int)($input['borderRadius'] ?? $current->borderRadius);
        $model->showLauncher = (bool)($input['showLauncher'] ?? $current->showLauncher);
        $model->autoOpen = (bool)($input['autoOpen'] ?? $current->autoOpen);
        $model->allowedSections = $this->normalizeStringArray($input['allowedSections'] ?? $current->allowedSections);
        $model->excludedSections = $this->normalizeStringArray($input['excludedSections'] ?? $current->excludedSections);
        $model->emptyStatePrompts = $this->normalizeStringArray($input['emptyStatePrompts'] ?? $current->emptyStatePrompts);
        $model->fallbackContactUrl = trim((string)($input['fallbackContactUrl'] ?? $current->fallbackContactUrl));
        $model->disclaimerText = trim((string)($input['disclaimerText'] ?? $current->disclaimerText));

        if (!$model->validate()) {
            return false;
        }

        $now = Db::prepareDateForDb(new \DateTime());
        $data = [
            'siteId' => $siteId,
            'assistantName' => $model->assistantName,
            'welcomeMessage' => $model->welcomeMessage,
            'placeholderText' => $model->placeholderText,
            'popupTitle' => $model->popupTitle,
            'launcherLabel' => $model->launcherLabel,
            'themePrimaryColor' => $model->themePrimaryColor,
            'themeBackgroundColor' => $model->themeBackgroundColor,
            'themeTextColor' => $model->themeTextColor,
            'panelPosition' => $model->panelPosition,
            'displayMode' => $model->displayMode,
            'borderRadius' => $model->borderRadius,
            'showLauncher' => $model->showLauncher,
            'autoOpen' => $model->autoOpen,
            'allowedSections' => json_encode($model->allowedSections),
            'excludedSections' => json_encode($model->excludedSections),
            'emptyStatePrompts' => json_encode($model->emptyStatePrompts),
            'fallbackContactUrl' => $model->fallbackContactUrl,
            'disclaimerText' => $model->disclaimerText,
        ];

        Craft::$app->getDb()->createCommand()->upsert(self::TABLE, [
            ...$data,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ], [
            ...$data,
            'dateUpdated' => $now,
        ])->execute();

        return true;
    }

    private function getSiteSettingsDefaults(int $siteId): SiteChatbotSettingsModel
    {
        return $this->buildDefaultsForSite($siteId);
    }

    private function buildDefaultsForSite(int $siteId): SiteChatbotSettingsModel
    {
        $site = Craft::$app->getSites()->getSiteById($siteId);
        $language = $site?->language ?? Craft::$app->language;
        $lang = strtolower(str_replace('_', '-', $language));

        $model = new SiteChatbotSettingsModel();

        if (str_starts_with($lang, 'ca')) {
            $model->assistantName = 'Assistent web';
            $model->welcomeMessage = 'Hola! Et puc ajudar a trobar contingut del web i portar-te a la pàgina correcta.';
            $model->placeholderText = 'Pregunta sobre serveis, pàgines o on anar...';
            $model->popupTitle = 'Necessites ajuda?';
            $model->launcherLabel = 'Necessites ajuda?';
            $model->emptyStatePrompts = ['Quins serveis oferiu?', 'Porta\'m a la pàgina adequada', 'Per on hauria de començar?'];
            $model->disclaimerText = 'Les respostes es basen en el contingut del web.';
        } elseif (str_starts_with($lang, 'es')) {
            $model->assistantName = 'Asistente web';
            $model->welcomeMessage = 'Hola. Puedo ayudarte a encontrar contenido del sitio y llevarte a la página correcta.';
            $model->placeholderText = 'Pregunta por servicios, páginas o dónde ir...';
            $model->popupTitle = '¿Necesitas ayuda?';
            $model->launcherLabel = '¿Necesitas ayuda?';
            $model->emptyStatePrompts = ['¿Qué servicios ofrecéis?', 'Llévame a la página correcta', '¿Por dónde debería empezar?'];
            $model->disclaimerText = 'Las respuestas se basan en el contenido del sitio.';
        }

        return $model;
    }

    private function ensureTable(): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        $db = Craft::$app->getDb();
        if ($db->getTableSchema(self::TABLE, true)) {
            $done = true;
            return;
        }

        $db->createCommand()->createTable(self::TABLE, [
            'id' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_PK),
            'siteId' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_INTEGER)->notNull(),
            'assistantName' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_STRING)->notNull(),
            'welcomeMessage' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_TEXT),
            'placeholderText' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_STRING)->notNull(),
            'popupTitle' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_STRING)->notNull(),
            'launcherLabel' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_STRING)->notNull(),
            'themePrimaryColor' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_STRING, 32)->notNull()->defaultValue('#0f766e'),
            'themeBackgroundColor' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_STRING, 32)->notNull()->defaultValue('#ffffff'),
            'themeTextColor' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_STRING, 32)->notNull()->defaultValue('#0f172a'),
            'panelPosition' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_STRING, 32)->notNull()->defaultValue('right'),
            'displayMode' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_STRING, 32)->notNull()->defaultValue('both'),
            'borderRadius' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_INTEGER)->notNull()->defaultValue(18),
            'showLauncher' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_BOOLEAN)->notNull()->defaultValue(true),
            'autoOpen' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_BOOLEAN)->notNull()->defaultValue(false),
            'allowedSections' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_TEXT),
            'excludedSections' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_TEXT),
            'emptyStatePrompts' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_TEXT),
            'fallbackContactUrl' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_STRING),
            'disclaimerText' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_STRING),
            'dateCreated' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_DATETIME)->notNull(),
            'dateUpdated' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_DATETIME)->notNull(),
            'uid' => $db->getSchema()->createColumnSchemaBuilder(\yii\db\Schema::TYPE_STRING, 36),
        ])->execute();

        $db->createCommand()->createIndex('pwt_chatbot_site_unique', self::TABLE, ['siteId'], true)->execute();
        $done = true;
    }

    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $this->normalizeStringArray($value);
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $this->normalizeStringArray($decoded) : [];
    }

    private function normalizeStringArray(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\R+/', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        $items = array_map(static fn(mixed $item): string => trim((string)$item), $value);
        $items = array_values(array_filter($items, static fn(string $item): bool => $item !== ''));

        return array_values(array_unique($items));
    }
}
