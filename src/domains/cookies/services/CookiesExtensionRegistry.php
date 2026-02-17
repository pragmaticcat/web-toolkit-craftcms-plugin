<?php

namespace pragmatic\webtoolkit\domains\cookies\services;

use Craft;
use craft\base\Component;
use pragmatic\webtoolkit\domains\cookies\events\RegisterCookiesAppearanceProviderEvent;
use pragmatic\webtoolkit\domains\cookies\interfaces\CookiesAppearanceProviderInterface;
use yii\base\Event;

class CookiesExtensionRegistry extends Component
{
    public const EVENT_REGISTER_APPEARANCE_PROVIDER = 'registerAppearanceProvider';

    private ?CookiesAppearanceProviderInterface $appearanceProvider = null;
    private bool $resolved = false;

    public function hasAppearanceProvider(): bool
    {
        return $this->getAppearanceProvider() !== null;
    }

    public function getAppearanceSettings(): array
    {
        $provider = $this->getAppearanceProvider();
        if ($provider === null) {
            return $this->getAppearanceDefaults();
        }

        return array_merge($provider->getAppearanceDefaults(), $provider->getAppearanceSettings());
    }

    public function saveAppearanceSettings(array $input): bool
    {
        $provider = $this->getAppearanceProvider();
        if ($provider === null) {
            return true;
        }

        return $provider->saveAppearanceSettings($input);
    }

    public function getAppearanceDefaults(): array
    {
        return [
            'popupLayout' => 'bar',
            'popupPosition' => 'bottom',
            'primaryColor' => '#2563eb',
            'backgroundColor' => '#ffffff',
            'textColor' => '#1f2937',
        ];
    }

    private function getAppearanceProvider(): ?CookiesAppearanceProviderInterface
    {
        if ($this->resolved) {
            return $this->appearanceProvider;
        }

        $this->resolved = true;
        $event = new RegisterCookiesAppearanceProviderEvent();
        Event::trigger(self::class, self::EVENT_REGISTER_APPEARANCE_PROVIDER, $event);

        foreach ($event->providers as $providerClass) {
            if (!class_exists($providerClass)) {
                continue;
            }

            $provider = Craft::createObject($providerClass);
            if ($provider instanceof CookiesAppearanceProviderInterface) {
                $this->appearanceProvider = $provider;
                break;
            }
        }

        return $this->appearanceProvider;
    }
}
