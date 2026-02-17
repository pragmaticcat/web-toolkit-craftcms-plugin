<?php

namespace pragmatic\webtoolkit\domains\cookies\services;

use pragmatic\webtoolkit\PragmaticWebToolkit;
use pragmatic\webtoolkit\domains\cookies\models\CookieModel;
use pragmatic\webtoolkit\domains\cookies\records\CookieRecord;

class CookiesService
{
    public function getAllCookies(): array
    {
        $records = CookieRecord::find()->orderBy(['name' => SORT_ASC])->all();
        return array_map(fn(CookieRecord $record) => $this->createModelFromRecord($record), $records);
    }

    public function getCookieById(int $id): ?CookieModel
    {
        $record = CookieRecord::findOne($id);
        return $record ? $this->createModelFromRecord($record) : null;
    }

    public function getCookiesByCategory(int $categoryId): array
    {
        $records = CookieRecord::find()
            ->where(['categoryId' => $categoryId])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        return array_map(fn(CookieRecord $record) => $this->createModelFromRecord($record), $records);
    }

    public function getCookiesGroupedByCategory(): array
    {
        $categories = PragmaticWebToolkit::$plugin->cookiesCategories->getAllCategories();
        $grouped = [];

        foreach ($categories as $category) {
            $grouped[] = [
                'category' => $category,
                'cookies' => $this->getCookiesByCategory((int)$category->id),
            ];
        }

        $uncategorized = CookieRecord::find()
            ->where(['categoryId' => null])
            ->orderBy(['name' => SORT_ASC])
            ->all();

        if (!empty($uncategorized)) {
            $grouped[] = [
                'category' => null,
                'cookies' => array_map(fn(CookieRecord $record) => $this->createModelFromRecord($record), $uncategorized),
            ];
        }

        return $grouped;
    }

    public function saveCookie(CookieModel $model): bool
    {
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
            $model->addErrors($record->getErrors());
            return false;
        }

        $model->id = (int)$record->id;

        return true;
    }

    public function deleteCookie(int $id): bool
    {
        $record = CookieRecord::findOne($id);
        if (!$record) {
            return false;
        }

        return (bool)$record->delete();
    }

    private function createModelFromRecord(CookieRecord $record): CookieModel
    {
        $model = new CookieModel();
        $model->id = (int)$record->id;
        $model->categoryId = $record->categoryId !== null ? (int)$record->categoryId : null;
        $model->name = (string)$record->name;
        $model->provider = $record->provider;
        $model->description = $record->description;
        $model->duration = $record->duration;
        $model->isRegex = (bool)$record->isRegex;
        $model->uid = $record->uid;

        return $model;
    }
}
