<?php

namespace pragmatic\webtoolkit\domains\seo\fields;

use craft\base\Model;

class SeoFieldValue extends Model
{
    public string $title = '';
    public string $description = '';
    public ?int $imageId = null;
    public bool $useSectionSeo = true;
    public ?bool $sitemapEnabled = null;
    public ?bool $sitemapIncludeImages = null;

    public function rules(): array
    {
        return [
            [['title', 'description'], 'string'],
            [['imageId'], 'integer'],
            [['useSectionSeo'], 'boolean'],
            [['sitemapEnabled', 'sitemapIncludeImages'], 'boolean'],
        ];
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'imageId' => $this->imageId,
            'useSectionSeo' => $this->useSectionSeo,
            'sitemapEnabled' => $this->sitemapEnabled,
            'sitemapIncludeImages' => $this->sitemapIncludeImages,
        ];
    }
}
