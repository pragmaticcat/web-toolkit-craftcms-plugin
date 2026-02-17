<?php

namespace pragmatic\webtoolkit\domains\mcp\models;

use craft\base\Model;

class McpSettingsModel extends Model
{
    public bool $enableEntries = true;
    public bool $enableAssets = true;
    public bool $enableCategories = true;
    public bool $enableUsers = false;
    public array $allowedSections = [];

    public bool $enableSearchTool = true;
    public bool $enableDetailsTool = true;
    public bool $enableCustomQueries = false;

    public int $maxResults = 100;
    public int $maxQueryComplexity = 5;

    public array $customQueries = [];

    public bool $enableCache = true;
    public int $cacheDuration = 3600;

    public string $accessToken = '';
    public array $allowedIpAddresses = [];
    public array $exposedFields = [];

    public function rules(): array
    {
        return [
            [['maxResults', 'maxQueryComplexity', 'cacheDuration'], 'integer'],
            ['maxResults', 'integer', 'min' => 1, 'max' => 1000],
            [
                [
                    'enableEntries',
                    'enableAssets',
                    'enableCategories',
                    'enableUsers',
                    'enableSearchTool',
                    'enableDetailsTool',
                    'enableCustomQueries',
                    'enableCache',
                ],
                'boolean',
            ],
            [['allowedSections', 'customQueries', 'exposedFields', 'allowedIpAddresses'], 'safe'],
            [['accessToken'], 'string'],
        ];
    }
}
