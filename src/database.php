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

function openDatabase(bool $create = false): PDO
{
    $path = dirname(__DIR__) . '/var/checkai.sqlite3';
    $directory = dirname($path);

    if ($create && !is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create the SQLite directory.');
    }

    if (!$create && !is_file($path)) {
        throw new RuntimeException('Initialize SQLite with php bin/init-db.php.');
    }

    $database = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $database->exec('PRAGMA busy_timeout = 5000');
    $database->exec('PRAGMA foreign_keys = ON');

    return $database;
}