<?php

declare(strict_types=1);

// --- PSR-4-ish autoloader for the App\ namespace -----------------------------
// A composer.json is provided for when Phase 2+ introduces real third-party
// packages (PDF/DOCX parsing, etc.). Until `composer install` has been run,
// this manual autoloader keeps the app runnable — if vendor/autoload.php
// exists, we prefer it (it will also autoload App\ once composer.json's
// autoload map is dumped).
$vendorAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require $vendorAutoload;
} else {
    spl_autoload_register(function (string $class): void {
        $map = [
            'App\\' => __DIR__ . '/app/',
            'Admin\\' => __DIR__ . '/admin/',
        ];
        foreach ($map as $prefix => $baseDir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = substr($class, strlen($prefix));
            $path = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (is_file($path)) {
                require $path;
            }
            return;
        }
    });
}

// --- Config + environment ----------------------------------------------------
require_once __DIR__ . '/app/Core/Env.php';
\App\Core\Env::load(__DIR__ . '/.env');

$config = require __DIR__ . '/config/config.php';

date_default_timezone_set((string) ($config['app']['timezone'] ?? 'UTC'));

// --- Error handling ------------------------------------------------------------
$debug = (bool) ($config['app']['debug'] ?? false);
ini_set('display_errors', $debug ? '1' : '0');
error_reporting(E_ALL);

set_exception_handler(function (\Throwable $e) use ($debug): void {
    \App\Core\Logger::error('unhandled_exception', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    http_response_code(500);
    if ($debug) {
        echo '<pre>' . htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8') . '</pre>';
    } else {
        echo 'An unexpected error occurred. Please try again later.';
    }
});
