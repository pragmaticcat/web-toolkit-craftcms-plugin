<?php

namespace pragmatic\webtoolkit\migrations;

use craft\db\Migration;
use craft\elements\Entry;
use yii\db\Query;

class m261001_000012_remove_section_seo_toggle extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%pragmatic_toolkit_seo_blocks}}';
        if ($this->db->tableExists($table) && $this->db->columnExists($table, 'useSectionSeo')) {
            $enabledRows = (new Query())
                ->select(['canonicalId', 'fieldId'])
                ->from($table)
                ->where(['siteId' => 0, 'useSectionSeo' => true])
                ->all($this->db);

            $sectionTable = '{{%pragmatic_toolkit_seo_meta_section_settings}}';
            foreach ($this->db->tableExists($sectionTable) ? $enabledRows : [] as $enabledRow) {
                $entries = Entry::find()
                    ->id((int)$enabledRow['canonicalId'])
                    ->site('*')
                    ->status(null)
                    ->all();

                foreach ($entries as $entry) {
                    $sectionSettings = (new Query())
                        ->from($sectionTable)
                        ->where([
                            'siteId' => (int)$entry->siteId,
                            'sectionId' => (int)$entry->sectionId,
                        ])
                        ->one($this->db);
                    if (!$sectionSettings) {
                        continue;
                    }

                    $this->update($table, [
                        'title' => trim((string)($sectionSettings['titleSiteName'] ?? '')),
                        'description' => trim((string)($sectionSettings['defaultSiteDescription'] ?? '')),
                        'imageId' => !empty($sectionSettings['defaultSiteImageId']) ? (int)$sectionSettings['defaultSiteImageId'] : null,
                    ], [
                        'canonicalId' => (int)$enabledRow['canonicalId'],
                        'siteId' => (int)$entry->siteId,
                        'fieldId' => (int)$enabledRow['fieldId'],
                    ]);
                }
            }

            $this->dropColumn($table, 'useSectionSeo');
        }

        return true;
    }

    public function safeDown(): bool
    {
        $table = '{{%pragmatic_toolkit_seo_blocks}}';
        if ($this->db->tableExists($table) && !$this->db->columnExists($table, 'useSectionSeo')) {
            $this->addColumn($table, 'useSectionSeo', $this->boolean()->notNull()->defaultValue(false));
        }

        return true;
    }
}
