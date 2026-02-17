<?php

namespace pragmatic\webtoolkit\domains\cookies\models;

use craft\base\Model;

class SiteSettingsModel extends Model
{
    public string $popupTitle = 'Cookie Settings';
    public string $popupDescription = 'We use cookies to enhance your browsing experience and analyze site traffic. Please choose your cookie preferences below.';
    public string $acceptAllLabel = 'Accept All';
    public string $rejectAllLabel = 'Reject All';
    public string $savePreferencesLabel = 'Save Preferences';
    public string $cookiePolicyUrl = '';

    public function defineRules(): array
    {
        return [
            [['popupTitle', 'acceptAllLabel', 'rejectAllLabel', 'savePreferencesLabel'], 'required'],
        ];
    }
}
