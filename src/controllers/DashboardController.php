<?php

namespace pragmatic\webtoolkit\controllers;

use craft\web\Controller;
use yii\web\Response;

class DashboardController extends Controller
{
    public function actionIndex(): Response
    {
        return $this->renderTemplate('pragmatic-web-toolkit/dashboard/index');
    }

    public function actionConfiguration(): Response
    {
        return $this->renderTemplate('pragmatic-web-toolkit/dashboard/configuration');
    }
}
