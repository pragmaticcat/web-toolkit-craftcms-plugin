<?php

namespace pragmatic\webtoolkit\domains\seo\fields;

use CraftCms\Cms\Component\Component;

class SeoFieldValue extends Component
{
    public string $title = '';
    public string $description = '';
    public ?int $imageId = null;
    public string $imageFieldHandle = '';
    public ?bool $sitemapEnabled = true;
    public ?bool $sitemapIncludeImages = true;


    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'imageId' => $this->imageId,
            'imageFieldHandle' => $this->imageFieldHandle,
            'sitemapEnabled' => $this->sitemapEnabled,
            'sitemapIncludeImages' => $this->sitemapIncludeImages,
        ];
    }
}
