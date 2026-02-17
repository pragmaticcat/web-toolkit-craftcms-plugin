<?php

namespace pragmatic\webtoolkit\controllers;

use craft\web\Controller;
use yii\web\Response;

class DefaultController extends Controller
{
    public function actionIndex(): Response
    {
        return $this->renderTemplate('pragmatic-web-toolkit/_layout/index', [
            'title' => 'Pragmatic Web Toolkit',
        ]);
    }
}
