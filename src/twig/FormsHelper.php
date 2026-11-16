<?php

namespace pragmatic\webtoolkit\twig;

use CraftCms\Cms\Cp\FormFields;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\Markup;

/**
 * Substitut de les macros de `_includes/forms` de Craft 5, que Craft 6 ja no inclou.
 *
 * Les plantilles del plugin fan `{% set forms = pwtForms %}` i continuen cridant
 * `forms.textField({...})`, `forms.lightswitchField({...})`, etc. Cada crida es resol amb el
 * mètode equivalent de {@see FormFields} (`textFieldHtml()`, `lightswitchFieldHtml()`…).
 */
class FormsHelper extends AbstractExtension implements GlobalsInterface
{
    public function getGlobals(): array
    {
        return ['pwtForms' => $this];
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public function __call(string $name, array $arguments): Markup
    {
        $config = (array)($arguments[0] ?? []);

        foreach ([$name . 'Html', $name . 'FromConfig'] as $method) {
            if (method_exists(FormFields::class, $method)) {
                return new Markup((string)FormFields::$method($config), 'UTF-8');
            }
        }

        throw new \BadMethodCallException(sprintf('Unknown form helper: %s', $name));
    }
}
