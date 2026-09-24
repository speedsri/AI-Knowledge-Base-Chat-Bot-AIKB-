<?php

declare(strict_types=1);

namespace Admin\Controllers;

use App\Auth\AuthService;
use App\Core\View;

final class DashboardController
{
    public function index(): void
    {
        $user = AuthService::user();

        View::render('dashboard/index', [
            'title' => 'Dashboard',
            'user' => $user,
        ], 'layouts/base');
    }
}
