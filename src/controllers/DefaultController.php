<?php

namespace pragmatic\webtoolkit\controllers;

use craft\web\Controller;
use yii\web\Response;

class DefaultController extends Controller
{
    public function actionIndex(): Response
    {
        return $this->redirect('pragmatic-toolkit/dashboard');
    }
}
