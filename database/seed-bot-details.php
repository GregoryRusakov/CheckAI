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

require_once dirname(__DIR__) . '/src/ip.php';
$snapshot = json_decode(file_get_contents(__DIR__ . '/bot-details.json'), true, 512, JSON_THROW_ON_ERROR);
$lookup = $database->prepare('SELECT b.id FROM bots b JOIN companies c ON c.id = b.company_id WHERE c.name = ? AND b.name = ?');
$existing = $database->prepare('SELECT 1 FROM bot_ip_ranges WHERE bot_id = ? LIMIT 1');
$update = $database->prepare('UPDATE bots SET description = ?, website = ?, note = ?, ip_source = ? WHERE id = ?');
$insert = $database->prepare('INSERT INTO bot_ip_ranges (bot_id, entry, first_ip, last_ip) VALUES (?, ?, ?, ?)');
foreach ($snapshot['bots'] as $name => $item) {
    $lookup->execute([$item['company'], $name]);
    $id = $lookup->fetchColumn();
    if ($id === false) {
        continue;
    }
    $existing->execute([$id]);
    $custom = $existing->fetchColumn() !== false;
    $update->execute([$item['description'], $item['website'], $custom ? 'Список IP задан администратором.' : $item['note'],
        $custom ? '' : $item['ip_source'], $id]);
    if (!$custom) {
        foreach ($item['ranges'] as $entry) {
            $insert->execute([$id, $entry, ...ipRange($entry)]);
        }
    }
}
