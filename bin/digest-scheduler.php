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

date_default_timezone_set('Asia/Yekaterinburg');

$generator = __DIR__ . '/generate-digest.php';
$databasePath = dirname(__DIR__) . '/var/checkai.sqlite3';

while (true) {
    $now = new DateTimeImmutable();
    $nextRun = $now->setTime(1, 0);

    if ($nextRun <= $now) {
        $nextRun = $nextRun->modify('+1 day');
    }

    sleep(max(1, $nextRun->getTimestamp() - $now->getTimestamp()));

    try {
        $database = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $enabled = (int) $database->query('SELECT daily_digest FROM settings WHERE id = 1')->fetchColumn() === 1;
        $database = null;

        if (!$enabled) {
            fwrite(STDOUT, "Daily digest is disabled; skipping generation.\n");
            continue;
        }
    } catch (Throwable $error) {
        fwrite(STDERR, "Cannot read daily digest setting: {$error->getMessage()}\n");
        continue;
    }

    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($generator), $exitCode);

    if ($exitCode !== 0) {
        fwrite(STDERR, "Digest generation failed with exit code {$exitCode}.\n");
    }
}
