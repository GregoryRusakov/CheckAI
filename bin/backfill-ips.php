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
require dirname(__DIR__) . '/src/collector.php';

$db = openDatabase();
$root = realpath($db->query('SELECT log_root FROM settings')->fetchColumn());
if ($root === false) {
    throw new RuntimeException('Каталог логов недоступен.');
}
$signatures = $db->query('SELECT pattern, bot_id FROM bot_signatures ORDER BY length(pattern) DESC, id')->fetchAll();
$sites = $db->query('SELECT DISTINCT s.id, s.log_path FROM sites s JOIN requests r ON r.site_id = s.id WHERE r.source_ip IS NULL')->fetchAll();
$key = 'site_id, bot_id, requested_at_utc, method, request_path, http_status';
$find = $db->prepare("SELECT MIN(id) FROM requests WHERE (site_id, bot_id, requested_at_utc, method, request_path, http_status)
    = (?, ?, ?, ?, ?, ?) HAVING COUNT(*) = 1 AND COUNT(source_ip) = 0");
$update = $db->prepare('UPDATE requests SET source_ip = ? WHERE id = ? AND source_ip IS NULL');
$restored = 0;
foreach ($sites as $site) {
    $path = resolveLog($root, $site['log_path']);
    if ($path === null) {
        continue;
    }
    $stage = new PDO('sqlite::memory:', null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $stage->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    $stage->exec('ALTER TABLE requests ADD COLUMN source_ip TEXT');
    collectFile($stage, (int) $site['id'], $path, $signatures);
    $db->beginTransaction();
    try {
        foreach ($stage->query("SELECT $key, MIN(source_ip) AS source_ip FROM requests GROUP BY $key
            HAVING COUNT(*) = 1 AND COUNT(source_ip) = 1") as $row) {
            $ip = array_pop($row);
            $find->execute(array_values($row));
            $id = $find->fetchColumn();
            $find->closeCursor();
            if ($id !== false) {
                $update->execute([$ip, $id]);
                $restored += $update->rowCount();
            }
        }
        $db->commit();
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}
echo 'Restored IPs: ', $restored, PHP_EOL;