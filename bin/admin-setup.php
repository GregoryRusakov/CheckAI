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

require dirname(__DIR__) . '/src/database.php';

function prompt(string $label): string
{
    fwrite(STDOUT, $label);
    $input = fgets(STDIN);
    if ($input === false) {
        throw new RuntimeException('Ввод прерван.');
    }

    return rtrim($input, "\r\n");
}

function promptPassword(string $label): string
{
    $state = shell_exec('stty -g < /dev/tty');
    if ($state === null || trim($state) === '') {
        throw new RuntimeException('Для скрытого ввода пароля требуется интерактивный терминал.');
    }

    exec('stty -echo < /dev/tty', $output, $status);
    if ($status !== 0) {
        throw new RuntimeException('Не удалось отключить отображение ввода.');
    }

    try {
        return prompt($label);
    } finally {
        exec('stty ' . escapeshellarg(trim($state)) . ' < /dev/tty');
        fwrite(STDOUT, PHP_EOL);
    }
}

try {
    $db = openDatabase();
    $adminExists = "SELECT EXISTS(SELECT 1 FROM users WHERE role = 'admin')";
    if ((int) $db->query($adminExists)->fetchColumn() !== 0) {
        throw new RuntimeException('Администратор уже создан.');
    }

    $login = trim(prompt('Логин: '));
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/D', $login)) {
        throw new RuntimeException('Логин должен содержать 3–64 латинские буквы, цифры, точки, дефисы или подчёркивания.');
    }

    $password = promptPassword('Пароль: ');
    $characterCount = preg_match_all('/./us', $password);
    if ($characterCount < 8 || strlen($password) > 72 || str_contains($password, "\0")) {
        throw new RuntimeException('Пароль должен содержать не менее 8 символов и занимать не более 72 байт.');
    }
    if ($password !== promptPassword('Повторите пароль: ')) {
        throw new RuntimeException('Пароли не совпадают.');
    }

    $insert = $db->prepare(
        "INSERT INTO users (login, password_hash, role, created_at_utc)
         SELECT ?, ?, 'admin', ? WHERE NOT EXISTS (SELECT 1 FROM users WHERE role = 'admin')"
    );
    $insert->execute([$login, password_hash($password, PASSWORD_DEFAULT), gmdate('Y-m-d\TH:i:s\Z')]);
    if ($insert->rowCount() !== 1) {
        throw new RuntimeException('Администратор уже создан.');
    }

    fwrite(STDOUT, "Администратор создан.\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
