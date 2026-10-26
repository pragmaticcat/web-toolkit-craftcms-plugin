<?php

namespace pragmatic\webtoolkit\controllers;

use craft\web\Controller;
use yii\web\Response;

class MaintenanceController extends Controller
{
    public function actionIndex(): Response
    {
        return $this->renderTemplate('pragmatic-web-toolkit/maintenance');
    }
}
