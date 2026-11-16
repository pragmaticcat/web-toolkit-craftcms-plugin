<?php

namespace CraftCms\DependencyAwareCache\Dependency;

use Illuminate\Cache\Repository;
use InvalidArgumentException;

/**
 * AllDependencies represents a dependency which is composed of a list of other dependencies.
 *
 * The dependency is reported as changed if all sub-dependencies are changed.
 */
class AllDependencies extends Dependency
{
    /**
     * @var Dependency[]
     */
    private array $dependencies;

    /**
     * @param  Dependency[]  $dependencies  list of dependencies that this dependency is composed of.
     *                                      Each array element must be a dependency object.
     */
    public function __construct(array $dependencies = [])
    {
        foreach ($dependencies as $dependency) {
            if (! $dependency instanceof Dependency) {
                throw new InvalidArgumentException(sprintf(
                    'The dependency must be a "%s" instance, "%s" received',
                    Dependency::class,
                    get_debug_type($dependency),
                ));
            }
        }

        $this->dependencies = $dependencies;
    }

    public function evaluate(Repository $cache): void
    {
        foreach ($this->dependencies as $dependency) {
            $dependency->evaluate($cache);
        }
    }

    protected function generateData(Repository $cache): null
    {
        return null;
    }

    public function isChanged(Repository $cache): bool
    {
        foreach ($this->dependencies as $dependency) {
            if (! $dependency->isChanged($cache)) {
                return false;
            }
        }

        return true;
    }
}
