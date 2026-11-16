<?php

namespace pragmatic\webtoolkit\controllers;

use CraftCms\Cms\Support\Env;
use CraftCms\Cms\Support\Url;
use pragmatic\webtoolkit\web\Controller;
use Symfony\Component\HttpFoundation\Response;

class MaintenanceController extends Controller
{
    public function actionIndex(): Response
    {
        if (!filter_var(env('PWT_CP_MAINTENANCE_MODE'), FILTER_VALIDATE_BOOLEAN)) {
            return $this->redirect(Url::cpUrl('dashboard'));
        }

        return $this->renderTemplate('pragmatic-web-toolkit/maintenance');
    }
}
