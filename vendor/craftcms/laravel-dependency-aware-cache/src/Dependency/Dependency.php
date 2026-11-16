<?php

namespace CraftCms\DependencyAwareCache\Dependency;

use Illuminate\Cache\Repository;

/**
 * Dependency is the base class for cache dependency classes.
 *
 * Child classes should override its {@see Dependency::generateDependencyData()}
 * for generating the actual dependency data.
 */
abstract class Dependency
{
    protected mixed $data = null;

    /**
     * Evaluates the dependency by generating and saving the data related with dependency.
     *
     * The cache invokes this method before writing data into it.
     */
    public function evaluate(Repository $cache): void
    {
        $this->data = $this->generateData($cache);
    }

    public function isChanged(Repository $cache): bool
    {
        return $this->data !== $this->generateData($cache);
    }

    abstract protected function generateData(Repository $cache): mixed;
}
