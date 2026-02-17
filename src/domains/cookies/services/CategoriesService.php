<?php

namespace pragmatic\webtoolkit\domains\cookies\services;

use Craft;
use craft\db\Query;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use pragmatic\webtoolkit\domains\cookies\models\CookieCategoryModel;
use pragmatic\webtoolkit\domains\cookies\records\CookieCategoryRecord;
use yii\db\Expression;

class CategoriesService
{
    private const SITE_VALUES_TABLE = '{{%pragmatic_toolkit_cookies_category_site_values}}';

    public function getAllCategories(?int $siteId = null): array
    {
        $siteId = $this->resolveSiteId($siteId);

        $rows = (new Query())
            ->from(['c' => CookieCategoryRecord::tableName()])
            ->leftJoin(
                ['sv' => self::SITE_VALUES_TABLE],
                '[[sv.categoryId]] = [[c.id]] AND [[sv.siteId]] = :siteId',
                [':siteId' => $siteId]
            )
            ->select([
                'id' => '[[c.id]]',
                'name' => new Expression('COALESCE([[sv.name]], [[c.name]])'),
                'handle' => '[[c.handle]]',
                'description' => new Expression('COALESCE([[sv.description]], [[c.description]])'),
                'isRequired' => '[[c.isRequired]]',
                'sortOrder' => '[[c.sortOrder]]',
                'uid' => '[[c.uid]]',
            ])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();

        return array_map(fn(array $row) => $this->createModelFromRow($row), $rows);
    }

    public function getCategoryById(int $id, ?int $siteId = null): ?CookieCategoryModel
    {
        $siteId = $this->resolveSiteId($siteId);

        $row = (new Query())
            ->from(['c' => CookieCategoryRecord::tableName()])
            ->leftJoin(
                ['sv' => self::SITE_VALUES_TABLE],
                '[[sv.categoryId]] = [[c.id]] AND [[sv.siteId]] = :siteId',
                [':siteId' => $siteId]
            )
            ->select([
                'id' => '[[c.id]]',
                'name' => new Expression('COALESCE([[sv.name]], [[c.name]])'),
                'handle' => '[[c.handle]]',
                'description' => new Expression('COALESCE([[sv.description]], [[c.description]])'),
                'isRequired' => '[[c.isRequired]]',
                'sortOrder' => '[[c.sortOrder]]',
                'uid' => '[[c.uid]]',
            ])
            ->where(['c.id' => $id])
            ->one();

        return $row ? $this->createModelFromRow($row) : null;
    }

    public function saveCategory(CookieCategoryModel $model, ?int $siteId = null): bool
    {
        $siteId = $this->resolveSiteId($siteId);

        if (!$model->validate()) {
            return false;
        }

        if ($model->id) {
            $record = CookieCategoryRecord::findOne($model->id);
            if (!$record) {
                return false;
            }
        } else {
            $record = new CookieCategoryRecord();
            $maxSort = (new Query())->from(CookieCategoryRecord::tableName())->max('sortOrder');
            $model->sortOrder = ((int)($maxSort ?? 0)) + 1;
        }

        $record->name = $model->name;
        $record->handle = $model->handle;
        $record->description = $model->description;
        $record->isRequired = $model->isRequired;
        $record->sortOrder = $model->sortOrder;

        if (!$record->save()) {
            $model->addErrors($record->getErrors());
            return false;
        }

        $model->id = (int)$record->id;

        $now = Db::prepareDateForDb(new \DateTime());
        Craft::$app->getDb()->createCommand()->upsert(self::SITE_VALUES_TABLE, [
            'categoryId' => $record->id,
            'siteId' => $siteId,
            'name' => $model->name,
            'description' => $model->description,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ], [
            'name' => $model->name,
            'description' => $model->description,
            'dateUpdated' => $now,
        ])->execute();

        return true;
    }

    public function deleteCategory(int $id): bool
    {
        $record = CookieCategoryRecord::findOne($id);
        if (!$record) {
            return false;
        }

        return (bool)$record->delete();
    }

    public function reorderCategories(array $ids): bool
    {
        foreach ($ids as $order => $id) {
            $record = CookieCategoryRecord::findOne((int)$id);
            if ($record) {
                $record->sortOrder = $order + 1;
                $record->save(false);
            }
        }

        return true;
    }

    private function createModelFromRow(array $row): CookieCategoryModel
    {
        $model = new CookieCategoryModel();
        $model->id = (int)$row['id'];
        $model->name = (string)$row['name'];
        $model->handle = (string)$row['handle'];
        $model->description = $row['description'] !== null ? (string)$row['description'] : null;
        $model->isRequired = (bool)$row['isRequired'];
        $model->sortOrder = (int)$row['sortOrder'];
        $model->uid = (string)$row['uid'];

        return $model;
    }

    private function resolveSiteId(?int $siteId): int
    {
        if ($siteId) {
            return $siteId;
        }

        $requestedSite = Cp::requestedSite();
        if ($requestedSite) {
            return (int)$requestedSite->id;
        }

        return (int)Craft::$app->getSites()->getCurrentSite()->id;
    }
}
