<?php

namespace pragmatic\webtoolkit\controllers;

use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\web\Response;

class MaintenanceController extends Controller
{
    public function actionIndex(): Response
    {
        if (!filter_var(App::env('PWT_CP_MAINTENANCE_MODE'), FILTER_VALIDATE_BOOLEAN)) {
            return $this->redirect(UrlHelper::cpUrl('dashboard'));
        }

        return $this->renderTemplate('pragmatic-web-toolkit/maintenance');
    }
}
