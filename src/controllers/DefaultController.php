<?php

namespace pragmatic\webtoolkit\controllers;

use pragmatic\webtoolkit\web\Controller;
use Symfony\Component\HttpFoundation\Response;

class DefaultController extends Controller
{
    public function actionIndex(): Response
    {
        return $this->redirect('pragmatic-toolkit/dashboard');
    }
}
