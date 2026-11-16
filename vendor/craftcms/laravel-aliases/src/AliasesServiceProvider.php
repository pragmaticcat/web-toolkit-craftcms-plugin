<?php

namespace CraftCms\Aliases;

use Illuminate\Support\ServiceProvider;
use Yiisoft\Aliases\Aliases;

class AliasesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Aliases::class, fn () => new Aliases);
        $this->app->alias(Aliases::class, 'aliases');
    }
}
