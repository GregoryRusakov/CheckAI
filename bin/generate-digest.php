<?php

/**
 * CheckAI - Инструмент для анализа и проверки посещаемости сайтов ИИ ботами и краулерами
 *
 * @author Григорий Русаков
 * @copyright 2024-2026 Григорий Русаков
 * @license Проприетарная лицензия (файл LICENSE в корне проекта)
 *          Запрещено бесплатное коммерческое использование.
 *          Для получения коммерческой лицензии свяжитесь: hello@analitica-soft.ru
 *
 * @version 1.0.0
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/database.php';
require dirname(__DIR__) . '/src/digest.php';

try {
    $result = generateDailyDigest(openDatabase());
    fwrite(STDOUT, sprintf("Digest for %s saved to %s (%d visits).\n", $result['date'], $result['path'], $result['total']));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
