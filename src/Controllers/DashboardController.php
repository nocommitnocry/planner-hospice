<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Routing\Request;
use App\Routing\Response;

/**
 * Placeholder. Verrà ampliato a partire dalla sessione 2.
 */
final class DashboardController extends BaseController
{
    public function index(Request $request): Response
    {
        return $this->render('dashboard/index.twig');
    }
}
