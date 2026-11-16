<?php

namespace CraftCms\Aliases;

use InvalidArgumentException;
use Yiisoft\Aliases\Aliases as YiiAliases;

final class Aliases
{
    /** {@see \Yiisoft\Aliases\Aliases::set} */
    public static function set(string $alias, string $path): void
    {
        app(YiiAliases::class)->set($alias, $path);
    }

    /** {@see \Yiisoft\Aliases\Aliases::remove} */
    public static function remove(string $alias): void
    {
        app(YiiAliases::class)->remove($alias);
    }

    /** {@see \Yiisoft\Aliases\Aliases::get} */
    public static function get(string $alias, bool $throw = true): string|false
    {
        try {
            return app(YiiAliases::class)->get($alias);
        } catch (InvalidArgumentException $e) {
            if ($throw) {
                throw $e;
            }

            return false;
        }
    }

    /**
     * @param  string[]  $aliases  Aliases to be translated.
     * @return string[] The paths corresponding to the aliases.
     *                  {@see \Yiisoft\Aliases\Aliases::getArray}
     */
    public static function getArray(array $aliases): array
    {
        return app(YiiAliases::class)->getArray($aliases);
    }

    /**
     * @return array<string, string>
     *                               {@see \Yiisoft\Aliases\Aliases::getAll}
     */
    public static function getAll(): array
    {
        /** @var array<string, string> $all */
        $all = app(YiiAliases::class)->getAll();

        return $all;
    }
}
