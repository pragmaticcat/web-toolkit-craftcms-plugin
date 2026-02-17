<?php

namespace pragmatic\webtoolkit\controllers;

use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class DomainController extends Controller
{
    protected array|int|bool $allowAnonymous = [
        'analytics-track',
        'cookies-consent-save',
        'seo-sitemap-xml',
    ];

    public function actionView(string $domain): Response
    {
        $allowed = ['analytics', 'cookies', 'mcp', 'seo', 'translations', 'plus18'];
        if (!in_array($domain, $allowed, true)) {
            throw new NotFoundHttpException();
        }

        return $this->renderTemplate('pragmatic-web-toolkit/' . $domain . '/index', [
            'domain' => $domain,
        ]);
    }

    public function actionAnalyticsTrack(): Response
    {
        return $this->asJson(['ok' => true]);
    }

    public function actionCookiesConsentSave(): Response
    {
        return $this->asJson(['ok' => true]);
    }

    public function actionSeoSitemapXml(): Response
    {
        return $this->asRaw('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>');
    }
}
