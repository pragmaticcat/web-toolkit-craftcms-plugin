<?php

namespace pragmatic\webtoolkit\jobs;

use CraftCms\Cms\Queue\Job;

/**
 * Base de les feines del plugin.
 *
 * Accepta un array de configuració per omplir les propietats públiques (com a Craft 5) i
 * executa `execute()` quan Laravel processa la feina.
 */
abstract class BaseJob extends Job
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        parent::__construct();

        foreach ($config as $name => $value) {
            if (property_exists($this, $name)) {
                $this->{$name} = $value;
            }
        }

        $this->init();
    }

    protected function init(): void
    {
    }

    abstract protected function execute(): void;

    public function handle(): void
    {
        $this->execute();
    }

    /**
     * @param float $fraction Progrés entre 0 i 1.
     */
    protected function reportProgress(float $fraction, ?string $label = null): void
    {
        $this->setProgress((int)round(max(0, min(1, $fraction)) * 100), $label);
    }
}
