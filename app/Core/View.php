<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Plain PHP templates (no template-engine dependency for Phase 1).
 * All output is escaped by default via e(); templates opt into raw output
 * explicitly and rarely.
 */
final class View
{
    private static string $basePath = __DIR__ . '/../../admin/views';

    public static function render(string $template, array $data = [], ?string $layout = 'layouts/base'): void
    {
        $content = self::capture($template, $data);

        if ($layout === null) {
            echo $content;
            return;
        }

        echo self::capture($layout, array_merge($data, ['content' => $content]));
    }

    private static function capture(string $template, array $data): string
    {
        $path = self::$basePath . '/' . $template . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException("View not found: {$template}");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        include $path;
        return (string) ob_get_clean();
    }
}

/** Global HTML-escape helper used throughout views. */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
