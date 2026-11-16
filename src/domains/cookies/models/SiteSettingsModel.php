<?php

namespace pragmatic\webtoolkit\domains\cookies\models;

use CraftCms\Cms\Component\Component;

class SiteSettingsModel extends Component
{
    public string $popupTitle = 'Cookie Settings';
    public string $popupDescription = 'We use cookies to enhance your browsing experience and analyze site traffic. Please choose your cookie preferences below.';
    public string $acceptAllLabel = 'Accept All';
    public string $rejectAllLabel = 'Reject All';
    public string $savePreferencesLabel = 'Save Preferences';
    public string $cookiePolicyUrl = '';
    public string $popupLayout = 'bar';
    public string $popupPosition = 'bottom';
    public string $primaryColor = '#2563eb';
    public string $backgroundColor = '#ffffff';
    public string $textColor = '#1f2937';
    public string $overlayEnabled = 'true';
    public string $autoShowPopup = 'true';
    public string $consentExpiry = '365';
    public string $logConsent = 'true';
    public string $showPreferencesButton = 'true';
    public string $preferencesButtonLabel = 'Cookie Settings';

    public function getRules(): array
    {
        return [
            'popupTitle' => ['required'],
            'acceptAllLabel' => ['required'],
            'rejectAllLabel' => ['required'],
            'savePreferencesLabel' => ['required'],
            'popupLayout' => ['in:bar,box,modal'],
            'popupPosition' => ['in:bottom,top,center'],
        ];
    }
}
