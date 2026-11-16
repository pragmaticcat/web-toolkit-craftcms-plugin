<?php

namespace CraftCms\DependencyAwareCache\Dependency;

use Illuminate\Cache\Repository;

/**
 * FileDependency represents a dependency based on a file's last modification time.
 *
 * If the last modification time of the file specified via {@see FileDependency::$fileName} is changed,
 * the dependency is considered as changed.
 */
class FileDependency extends Dependency
{
    /**
     * @param  string  $fileName  The file path whose last modification time is used to
     *                            check if the dependency has been changed.
     */
    public function __construct(
        private string $fileName
    ) {}

    protected function generateData(Repository $cache): false|int
    {
        clearstatcache(false, $this->fileName);

        return @filemtime($this->fileName);
    }
}
