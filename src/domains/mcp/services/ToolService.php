<?php
namespace pragmatic\webtoolkit\domains\mcp\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\Asset;
use pragmatic\webtoolkit\PragmaticWebToolkit;

class ToolService extends Component
{
    /**
     * Obtiene la lista de tools disponibles
     */
    public function getAvailableTools(): array
    {
        $settings = PragmaticWebToolkit::$plugin->mcpSettings->get();
        $tools = [];

        if ($settings->enableSearchTool) {
            $tools[] = [
                'name' => 'search_entries',
                'description' => 'Busca entradas por título, contenido o campos personalizados',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Texto a buscar en título y contenido'
                        ],
                        'section' => [
                            'type' => 'string',
                            'description' => 'Handle de la sección para filtrar (opcional)'
                        ],
                        'limit' => [
                            'type' => 'number',
                            'description' => 'Número máximo de resultados (máx: ' . $settings->maxResults . ')'
                        ]
                    ],
                    'required' => ['query']
                ]
            ];
        }

        if ($settings->enableDetailsTool) {
            $tools[] = [
                'name' => 'get_entry_details',
                'description' => 'Obtiene todos los detalles de una entrada específica incluyendo campos personalizados',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'entryId' => [
                            'type' => 'number',
                            'description' => 'ID de la entrada a consultar'
                        ],
                        'includeRelated' => [
                            'type' => 'boolean',
                            'description' => 'Incluir entradas relacionadas (opcional, default: false)'
                        ]
                    ],
                    'required' => ['entryId']
                ]
            ];
        }

        // Tool para buscar assets
        if ($settings->enableAssets) {
            $tools[] = [
                'name' => 'search_assets',
                'description' => 'Busca archivos y assets por nombre, tipo o volumen',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Texto a buscar en nombre de archivo'
                        ],
                        'kind' => [
                            'type' => 'string',
                            'description' => 'Tipo de archivo (image, video, audio, document, etc.)'
                        ],
                        'limit' => [
                            'type' => 'number',
                            'description' => 'Número máximo de resultados'
                        ]
                    ]
                ]
            ];
        }

        return $tools;
    }

    /**
     * Ejecuta un tool específico
     */
    public function executeTool(string $toolName, array $arguments): array
    {
        $settings = PragmaticWebToolkit::$plugin->mcpSettings->get();

        switch ($toolName) {
            case 'search_entries':
                if (!$settings->enableSearchTool) {
                    throw new \Exception('Tool de búsqueda deshabilitado en configuración');
                }
                return $this->searchEntries($arguments);

            case 'get_entry_details':
                if (!$settings->enableDetailsTool) {
                    throw new \Exception('Tool de detalles deshabilitado en configuración');
                }
                return $this->getEntryDetails($arguments);

            case 'search_assets':
                if (!$settings->enableAssets) {
                    throw new \Exception('Acceso a assets deshabilitado en configuración');
                }
                return $this->searchAssets($arguments);

            default:
                throw new \Exception("Tool desconocido: {$toolName}");
        }
    }

    /**
     * Busca entradas por texto
     */
    private function searchEntries(array $args): array
    {
        $settings = PragmaticWebToolkit::$plugin->mcpSettings->get();
        
        $limit = min(
            $args['limit'] ?? $settings->maxResults,
            $settings->maxResults
        );

        $query = Entry::find()
            ->search($args['query'])
            ->limit($limit)
            ->orderBy('score');

        // Filtrar por sección si se especifica
        if (!empty($args['section'])) {
            if (!empty($settings->allowedSections) && 
                !in_array($args['section'], $settings->allowedSections)) {
                throw new \Exception('Sección no permitida: ' . $args['section']);
            }
            $query->section($args['section']);
        } elseif (!empty($settings->allowedSections)) {
            $query->section($settings->allowedSections);
        }

        $entries = $query->all();
        
        return array_map(function($entry) {
            return PragmaticWebToolkit::$plugin->mcpResource->formatEntry($entry);
        }, $entries);
    }

    /**
     * Obtiene detalles completos de una entrada
     */
    private function getEntryDetails(array $args): array
    {
        $settings = PragmaticWebToolkit::$plugin->mcpSettings->get();
        
        $entry = Entry::find()
            ->id($args['entryId'])
            ->one();

        if (!$entry) {
            throw new \Exception('Entrada no encontrada con ID: ' . $args['entryId']);
        }

        // Verificar permisos de sección
        if (!empty($settings->allowedSections) && 
            !in_array($entry->section->handle, $settings->allowedSections)) {
            throw new \Exception('Acceso denegado a la sección: ' . $entry->section->name);
        }

        $data = PragmaticWebToolkit::$plugin->mcpResource->formatEntry($entry);

        // Incluir entradas relacionadas si se solicita
        if (!empty($args['includeRelated'])) {
            $data['relatedEntries'] = $this->getRelatedEntries($entry);
        }

        return $data;
    }

    /**
     * Busca assets
     */
    private function searchAssets(array $args): array
    {
        $settings = PragmaticWebToolkit::$plugin->mcpSettings->get();
        
        $limit = min(
            $args['limit'] ?? $settings->maxResults,
            $settings->maxResults
        );

        $query = Asset::find()->limit($limit);

        if (!empty($args['query'])) {
            $query->search($args['query']);
        }

        if (!empty($args['kind'])) {
            $query->kind($args['kind']);
        }

        $assets = $query->all();

        return array_map(function($asset) {
            return [
                'id' => $asset->id,
                'title' => $asset->title,
                'filename' => $asset->filename,
                'url' => $asset->url,
                'size' => $asset->size,
                'kind' => $asset->kind,
                'extension' => $asset->extension,
                'width' => $asset->width ?? null,
                'height' => $asset->height ?? null,
                'dateCreated' => $asset->dateCreated?->format('c'),
            ];
        }, $assets);
    }

    /**
     * Obtiene entradas relacionadas
     */
    private function getRelatedEntries(Entry $entry): array
    {
        $related = [];
        
        // Buscar en campos de tipo Entries
        $fieldLayout = $entry->getFieldLayout();
        
        foreach ($fieldLayout->getCustomFields() as $field) {
            if ($field instanceof \craft\fields\Entries) {
                $relatedEntries = $entry->{$field->handle}->all();
                foreach ($relatedEntries as $relEntry) {
                    $related[] = [
                        'id' => $relEntry->id,
                        'title' => $relEntry->title,
                        'url' => $relEntry->url,
                        'section' => $relEntry->section->name,
                        'fieldHandle' => $field->handle,
                        'fieldName' => $field->name,
                    ];
                }
            }
        }

        return $related;
    }
}
