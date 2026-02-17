<?php
namespace pragmatic\webtoolkit\domains\mcp\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\User;
use pragmatic\webtoolkit\PragmaticWebToolkit;

class ResourceService extends Component
{
    /**
     * Obtiene la lista de recursos disponibles según configuración
     */
    public function getAvailableResources(): array
    {
        $settings = PragmaticWebToolkit::$plugin->mcpSettings->get();
        $resources = [];

        if ($settings->enableEntries) {
            $resources[] = [
                'uri' => 'craft://entries',
                'name' => 'Todas las entradas',
                'mimeType' => 'application/json',
                'description' => 'Acceso a las entradas del sitio'
            ];

            // Recurso por cada sección permitida
            $sections = $this->getAllowedSections();
            foreach ($sections as $section) {
                $resources[] = [
                    'uri' => "craft://entries/{$section->handle}",
                    'name' => "Entradas: {$section->name}",
                    'mimeType' => 'application/json',
                    'description' => "Entradas de la sección {$section->name}"
                ];
            }
        }

        if ($settings->enableAssets) {
            $resources[] = [
                'uri' => 'craft://assets',
                'name' => 'Assets',
                'mimeType' => 'application/json',
                'description' => 'Archivos y recursos multimedia'
            ];
        }

        if ($settings->enableCategories) {
            $resources[] = [
                'uri' => 'craft://categories',
                'name' => 'Categorías',
                'mimeType' => 'application/json',
                'description' => 'Sistema de categorización'
            ];
        }

        if ($settings->enableUsers) {
            $resources[] = [
                'uri' => 'craft://users',
                'name' => 'Usuarios',
                'mimeType' => 'application/json',
                'description' => 'Usuarios del sistema'
            ];
        }

        return $resources;
    }

    /**
     * Lee los datos de un recurso específico
     */
    public function readResource(string $uri): array
    {
        $settings = PragmaticWebToolkit::$plugin->mcpSettings->get();
        
        // Intentar obtener desde cache
        if ($settings->enableCache) {
            $cacheKey = "mcp_resource_" . md5($uri);
            $cached = Craft::$app->cache->get($cacheKey);
            if ($cached !== false) {
                return $cached;
            }
        }

        $data = $this->fetchResourceData($uri);

        // Guardar en cache
        if ($settings->enableCache) {
            Craft::$app->cache->set($cacheKey, $data, $settings->cacheDuration);
        }

        return $data;
    }

    /**
     * Obtiene los datos del recurso
     */
    private function fetchResourceData(string $uri): array
    {
        // Parsear URI y obtener datos correspondientes
        if (preg_match('#^craft://entries/(.+)$#', $uri, $matches)) {
            $sectionHandle = $matches[1];
            return $this->getEntriesBySection($sectionHandle);
        }

        if ($uri === 'craft://entries') {
            return $this->getAllEntries();
        }

        if ($uri === 'craft://assets') {
            return $this->getAllAssets();
        }

        if ($uri === 'craft://categories') {
            return $this->getAllCategories();
        }

        if ($uri === 'craft://users') {
            return $this->getAllUsers();
        }

        throw new \Exception("Recurso no encontrado: {$uri}");
    }

    /**
     * Obtiene entradas de una sección específica
     */
    private function getEntriesBySection(string $sectionHandle): array
    {
        $settings = PragmaticWebToolkit::$plugin->mcpSettings->get();
        
        // Verificar que la sección esté permitida
        if (!empty($settings->allowedSections) && 
            !in_array($sectionHandle, $settings->allowedSections)) {
            throw new \Exception("Sección no permitida: {$sectionHandle}");
        }

        $entries = Entry::find()
            ->section($sectionHandle)
            ->limit($settings->maxResults)
            ->orderBy('postDate DESC')
            ->all();

        return array_map([$this, 'formatEntry'], $entries);
    }

    /**
     * Obtiene todas las entradas permitidas
     */
    private function getAllEntries(): array
    {
        $settings = PragmaticWebToolkit::$plugin->mcpSettings->get();
        
        $query = Entry::find()
            ->limit($settings->maxResults)
            ->orderBy('postDate DESC');
        
        if (!empty($settings->allowedSections)) {
            $query->section($settings->allowedSections);
        }

        $entries = $query->all();
        return array_map([$this, 'formatEntry'], $entries);
    }

    /**
     * Formatea una entrada para la respuesta
     */
    public function formatEntry(Entry $entry): array
    {
        $settings = PragmaticWebToolkit::$plugin->mcpSettings->get();
        
        $data = [
            'id' => $entry->id,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'url' => $entry->url,
            'postDate' => $entry->postDate?->format('c'),
            'dateUpdated' => $entry->dateUpdated?->format('c'),
            'section' => $entry->section->name,
            'sectionHandle' => $entry->section->handle,
            'type' => $entry->type->name,
            'typeHandle' => $entry->type->handle,
            'author' => [
                'id' => $entry->author?->id,
                'fullName' => $entry->author?->fullName,
                'username' => $entry->author?->username,
            ],
        ];

        // Agregar campos personalizados configurados
        if (!empty($settings->exposedFields)) {
            $customFields = [];
            foreach ($settings->exposedFields as $fieldHandle) {
                try {
                    if (isset($entry->$fieldHandle)) {
                        $value = $entry->$fieldHandle;
                        $customFields[$fieldHandle] = $this->formatFieldValue($value);
                    }
                } catch (\Exception $e) {
                    // Ignorar campos que no existan o den error
                    continue;
                }
            }
            if (!empty($customFields)) {
                $data['customFields'] = $customFields;
            }
        }

        return $data;
    }

    /**
     * Formatea el valor de un campo
     */
    private function formatFieldValue($value)
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            // Queries de elementos (relaciones)
            if ($value instanceof \craft\elements\db\ElementQuery) {
                return array_map(function($el) {
                    return [
                        'id' => $el->id,
                        'title' => $el->title,
                        'url' => $el->url ?? null
                    ];
                }, $value->all());
            }
            
            // Objetos con __toString
            if (method_exists($value, '__toString')) {
                return (string)$value;
            }

            // Fechas
            if ($value instanceof \DateTime) {
                return $value->format('c');
            }
        }
        
        return null;
    }

    /**
     * Obtiene todos los assets
     */
    private function getAllAssets(): array
    {
        $settings = PragmaticWebToolkit::$plugin->mcpSettings->get();
        
        $assets = Asset::find()
            ->limit($settings->maxResults)
            ->orderBy('dateCreated DESC')
            ->all();

        return array_map(function($asset) {
            return [
                'id' => $asset->id,
                'title' => $asset->title,
                'filename' => $asset->filename,
                'url' => $asset->url,
                'size' => $asset->size,
                'kind' => $asset->kind,
                'extension' => $asset->extension,
                'dateCreated' => $asset->dateCreated?->format('c'),
                'volume' => $asset->volume->name,
            ];
        }, $assets);
    }

    /**
     * Obtiene todas las categorías
     */
    private function getAllCategories(): array
    {
        $settings = PragmaticWebToolkit::$plugin->mcpSettings->get();
        
        $categories = Category::find()
            ->limit($settings->maxResults)
            ->all();

        return array_map(function($cat) {
            return [
                'id' => $cat->id,
                'title' => $cat->title,
                'slug' => $cat->slug,
                'url' => $cat->url,
                'group' => $cat->group->name,
                'groupHandle' => $cat->group->handle,
            ];
        }, $categories);
    }

    /**
     * Obtiene todos los usuarios (si está habilitado)
     */
    private function getAllUsers(): array
    {
        $settings = PragmaticWebToolkit::$plugin->mcpSettings->get();
        
        if (!$settings->enableUsers) {
            throw new \Exception('Acceso a usuarios no habilitado');
        }

        $users = User::find()
            ->limit($settings->maxResults)
            ->all();

        return array_map(function($user) {
            return [
                'id' => $user->id,
                'username' => $user->username,
                'fullName' => $user->fullName,
                'email' => $user->email,
                'dateCreated' => $user->dateCreated?->format('c'),
            ];
        }, $users);
    }

    /**
     * Obtiene las secciones permitidas
     */
    private function getAllowedSections(): array
    {
        $settings = PragmaticWebToolkit::$plugin->mcpSettings->get();
        $allSections = Craft::$app->entries->getAllSections();

        if (empty($settings->allowedSections)) {
            return $allSections;
        }

        return array_filter($allSections, function($section) use ($settings) {
            return in_array($section->handle, $settings->allowedSections);
        });
    }
}
