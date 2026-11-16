<?php

namespace pragmatic\webtoolkit\events;

/**
 * Esdeveniment per registrar dominis (features) d’extensions del toolkit.
 *
 * ```php
 * Event::listen(function (RegisterToolkitFeaturesEvent $event) {
 *     $event->providers[] = MyFeature::class;
 * });
 * ```
 */
class RegisterToolkitFeaturesEvent
{
    /**
     * @var array<class-string>
     */
    public array $providers = [];
}
