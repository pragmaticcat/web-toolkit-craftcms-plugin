<?php

namespace pragmatic\webtoolkit\domains\seo\fields;

use craft\base\Model;

class SeoFieldValue extends Model
{
    public string $title = '';
    public string $description = '';
    public ?int $imageId = null;
    public ?bool $sitemapEnabled = true;
    public ?bool $sitemapIncludeImages = true;

    public function rules(): array
    {
        return [
            [['title', 'description'], 'string'],
            [['imageId'], 'integer'],
            [['sitemapEnabled', 'sitemapIncludeImages'], 'boolean'],
        ];
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'imageId' => $this->imageId,
            'sitemapEnabled' => $this->sitemapEnabled,
            'sitemapIncludeImages' => $this->sitemapIncludeImages,
        ];
    }
}
