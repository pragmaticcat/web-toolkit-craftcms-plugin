<?php

namespace pragmatic\webtoolkit\controllers;

use Craft;
use craft\web\Controller;
use yii\web\Response;

class MonitorController extends Controller
{
    protected array|bool|int $allowAnonymous = ['health'];

    public $enableCsrfValidation = false;

    public function actionHealth(): Response
    {
        $request = Craft::$app->getRequest();
        $timestamp = (string)$request->getHeaders()->get('X-PWT-Timestamp', '');
        $signature = (string)$request->getHeaders()->get('X-PWT-Signature', '');
        $site = (string)$request->getHeaders()->get('X-PWT-Site', '');
        $expectedSite = (string)Craft::parseEnv('$PWT_MONITOR_SITE_ID');
        $secret = (string)Craft::parseEnv('$PWT_MONITOR_SECRET');

        if ($secret === '' || $expectedSite === '' || $site !== $expectedSite || !ctype_digit($timestamp) || abs(time() - (int)$timestamp) > 300) {
            throw new \yii\web\UnauthorizedHttpException('Invalid monitoring credentials.');
        }

        $expected = hash_hmac('sha256', $timestamp."\nGET\n", $secret);
        if (!hash_equals($expected, $signature)) {
            throw new \yii\web\UnauthorizedHttpException('Invalid monitoring signature.');
        }

        Craft::$app->getDb()->open();

        return $this->asJson([
            'status' => 'healthy',
            'timestamp' => gmdate(DATE_ATOM),
            'craft_version' => Craft::$app->getVersion(),
            'php_version' => PHP_VERSION,
            'environment' => Craft::$app->getConfig()->getGeneral()->environment,
            'queue_size' => Craft::$app->getQueue()->getTotalJobs(),
            'fingerprints' => [
                'project_config' => is_file(Craft::$app->getPath()->getConfigPath().'/project/project.yaml')
                    ? hash_file('sha256', Craft::$app->getPath()->getConfigPath().'/project/project.yaml')
                    : null,
            ],
        ]);
    }
}
