<?php

namespace pragmatic\webtoolkit\domains\cookies\services;

use pragmatic\webtoolkit\db\Query;
use CraftCms\Cms\Cp\RequestedSite;
use CraftCms\Cms\Support\Query as DbHelper;
use CraftCms\Cms\Support\Str;
use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\cookies\models\CookieModel;
use pragmatic\webtoolkit\domains\cookies\records\CookieRecord;
use CraftCms\Cms\Support\Facades\Sites;
use pragmatic\webtoolkit\db\Command;

class CookiesService
{
    private const SITE_VALUES_TABLE = 'pragmatic_toolkit_cookies_cookie_site_values';

    public function getAllCookies(?int $siteId = null): array
    {
        $siteId = $this->resolveSiteId($siteId);

        $rows = (new Query())
            ->from(['c' => CookieRecord::tableName()])
            ->leftJoin(
                ['sv' => self::SITE_VALUES_TABLE],
                '[[sv.cookieId]] = [[c.id]] AND [[sv.siteId]] = :siteId',
                [':siteId' => $siteId]
            )
            ->select([
                'id' => '[[c.id]]',
                'categoryId' => '[[c.categoryId]]',
                'name' => 'COALESCE([[sv.name]], [[c.name]])',
                'provider' => 'COALESCE([[sv.provider]], [[c.provider]])',
                'description' => 'COALESCE([[sv.description]], [[c.description]])',
                'duration' => 'COALESCE([[sv.duration]], [[c.duration]])',
                'isRegex' => '[[c.isRegex]]',
                'uid' => '[[c.uid]]',
            ])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        return array_map(fn(array $row) => $this->createModelFromRow($row), $rows);
    }

    public function getCookieById(int $id, ?int $siteId = null): ?CookieModel
    {
        $siteId = $this->resolveSiteId($siteId);

        $row = (new Query())
            ->from(['c' => CookieRecord::tableName()])
            ->leftJoin(
                ['sv' => self::SITE_VALUES_TABLE],
                '[[sv.cookieId]] = [[c.id]] AND [[sv.siteId]] = :siteId',
                [':siteId' => $siteId]
            )
            ->select([
                'id' => '[[c.id]]',
                'categoryId' => '[[c.categoryId]]',
                'name' => 'COALESCE([[sv.name]], [[c.name]])',
                'provider' => 'COALESCE([[sv.provider]], [[c.provider]])',
                'description' => 'COALESCE([[sv.description]], [[c.description]])',
                'duration' => 'COALESCE([[sv.duration]], [[c.duration]])',
                'isRegex' => '[[c.isRegex]]',
                'uid' => '[[c.uid]]',
            ])
            ->where(['c.id' => $id])
            ->one();

        return $row ? $this->createModelFromRow($row) : null;
    }

    public function getCookieByName(string $name, ?int $siteId = null): ?CookieModel
    {
        $siteId = $this->resolveSiteId($siteId);

        $row = (new Query())
            ->from(['c' => CookieRecord::tableName()])
            ->leftJoin(
                ['sv' => self::SITE_VALUES_TABLE],
                '[[sv.cookieId]] = [[c.id]] AND [[sv.siteId]] = :siteId',
                [':siteId' => $siteId]
            )
            ->select([
                'id' => '[[c.id]]',
                'categoryId' => '[[c.categoryId]]',
                'name' => 'COALESCE([[sv.name]], [[c.name]])',
                'provider' => 'COALESCE([[sv.provider]], [[c.provider]])',
                'description' => 'COALESCE([[sv.description]], [[c.description]])',
                'duration' => 'COALESCE([[sv.duration]], [[c.duration]])',
                'isRegex' => '[[c.isRegex]]',
                'uid' => '[[c.uid]]',
            ])
            ->where(['c.name' => $name])
            ->orWhere(['sv.name' => $name])
            ->one();

        return $row ? $this->createModelFromRow($row) : null;
    }

    public function getCookiesByCategory(int $categoryId, ?int $siteId = null): array
    {
        $siteId = $this->resolveSiteId($siteId);

        $rows = (new Query())
            ->from(['c' => CookieRecord::tableName()])
            ->leftJoin(
                ['sv' => self::SITE_VALUES_TABLE],
                '[[sv.cookieId]] = [[c.id]] AND [[sv.siteId]] = :siteId',
                [':siteId' => $siteId]
            )
            ->select([
                'id' => '[[c.id]]',
                'categoryId' => '[[c.categoryId]]',
                'name' => 'COALESCE([[sv.name]], [[c.name]])',
                'provider' => 'COALESCE([[sv.provider]], [[c.provider]])',
                'description' => 'COALESCE([[sv.description]], [[c.description]])',
                'duration' => 'COALESCE([[sv.duration]], [[c.duration]])',
                'isRegex' => '[[c.isRegex]]',
                'uid' => '[[c.uid]]',
            ])
            ->where(['c.categoryId' => $categoryId])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        return array_map(fn(array $row) => $this->createModelFromRow($row), $rows);
    }

    public function getCookiesGroupedByCategory(?int $siteId = null): array
    {
        $siteId = $this->resolveSiteId($siteId);
        $categories = PragmaticWebToolkit::$plugin->cookiesCategories->getAllCategories($siteId);
        $grouped = [];

        foreach ($categories as $category) {
            $grouped[] = [
                'category' => $category,
                'cookies' => $this->getCookiesByCategory((int)$category->id, $siteId),
            ];
        }

        $uncategorized = (new Query())
            ->from(['c' => CookieRecord::tableName()])
            ->leftJoin(
                ['sv' => self::SITE_VALUES_TABLE],
                '[[sv.cookieId]] = [[c.id]] AND [[sv.siteId]] = :siteId',
                [':siteId' => $siteId]
            )
            ->select([
                'id' => '[[c.id]]',
                'categoryId' => '[[c.categoryId]]',
                'name' => 'COALESCE([[sv.name]], [[c.name]])',
                'provider' => 'COALESCE([[sv.provider]], [[c.provider]])',
                'description' => 'COALESCE([[sv.description]], [[c.description]])',
                'duration' => 'COALESCE([[sv.duration]], [[c.duration]])',
                'isRegex' => '[[c.isRegex]]',
                'uid' => '[[c.uid]]',
            ])
            ->where(['c.categoryId' => null])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        if (!empty($uncategorized)) {
            $grouped[] = [
                'category' => null,
                'cookies' => array_map(fn(array $row) => $this->createModelFromRow($row), $uncategorized),
            ];
        }

        return $grouped;
    }

    public function saveCookie(CookieModel $model, ?int $siteId = null): bool
    {
        $siteId = $this->resolveSiteId($siteId);

        if (!$model->validate()) {
            return false;
        }

        if ($model->id) {
            $record = CookieRecord::findOne($model->id);
            if (!$record) {
                return false;
            }
        } else {
            $record = new CookieRecord();
        }

        $record->categoryId = $model->categoryId;
        $record->name = $model->name;
        $record->provider = $model->provider;
        $record->description = $model->description;
        $record->duration = $model->duration;
        $record->isRegex = $model->isRegex;

        if (!$record->save()) {
            return false;
        }

        $model->id = (int)$record->id;

        $now = DbHelper::prepareDateForDb(new \DateTime());
        Command::make()->upsert(self::SITE_VALUES_TABLE, [
            'cookieId' => $record->id,
            'siteId' => $siteId,
            'name' => $model->name,
            'provider' => $model->provider,
            'description' => $model->description,
            'duration' => $model->duration,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => Str::uuid()->toString(),
        ], [
            'name' => $model->name,
            'provider' => $model->provider,
            'description' => $model->description,
            'duration' => $model->duration,
            'dateUpdated' => $now,
        ])->execute();

        return true;
    }

    public function upsertSiteValues(int $cookieId, array $values, array $siteIds): void
    {
        $now = DbHelper::prepareDateForDb(new \DateTime());
        foreach ($siteIds as $siteId) {
            Command::make()->upsert(self::SITE_VALUES_TABLE, [
                'cookieId' => $cookieId,
                'siteId' => (int)$siteId,
                'name' => (string)($values['name'] ?? ''),
                'provider' => $values['provider'] ?? null,
                'description' => $values['description'] ?? null,
                'duration' => $values['duration'] ?? null,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => Str::uuid()->toString(),
            ], [
                'name' => (string)($values['name'] ?? ''),
                'provider' => $values['provider'] ?? null,
                'description' => $values['description'] ?? null,
                'duration' => $values['duration'] ?? null,
                'dateUpdated' => $now,
            ])->execute();
        }
    }

    public function deleteCookie(int $id): bool
    {
        $record = CookieRecord::findOne($id);
        if (!$record) {
            return false;
        }

        return (bool)$record->delete();
    }

    private function createModelFromRow(array $row): CookieModel
    {
        $model = new CookieModel();
        $model->id = (int)$row['id'];
        $model->categoryId = $row['categoryId'] !== null ? (int)$row['categoryId'] : null;
        $model->name = (string)$row['name'];
        $model->provider = $row['provider'] !== null ? (string)$row['provider'] : null;
        $model->description = $row['description'] !== null ? (string)$row['description'] : null;
        $model->duration = $row['duration'] !== null ? (string)$row['duration'] : null;
        $model->isRegex = (bool)$row['isRegex'];
        $model->uid = (string)$row['uid'];

        return $model;
    }

    private function resolveSiteId(?int $siteId): int
    {
        if ($siteId) {
            return $siteId;
        }

        $requestedSite = app(RequestedSite::class)->get();
        if ($requestedSite) {
            return (int)$requestedSite->id;
        }

        return (int)Sites::getCurrentSite()->id;
    }
}
