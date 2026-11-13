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

require_once __DIR__ . '/ip.php';

function resolveLog(string $root, string $relative): ?string
{
    if ($relative === '' || str_contains($relative, ':')
        || preg_match('~[\x00-\x1f]|(?:^|[\\\\/])\.{1,2}(?:[\\\\/]|$)~', $relative)
        || str_starts_with($relative, '/') || str_starts_with($relative, '\\')) {
        return null;
    }
    $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
    $path = realpath(rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $relative);
    $prefix = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
    $insideRoot = $path !== false && (DIRECTORY_SEPARATOR === '\\'
        ? strncasecmp($path, $prefix, strlen($prefix)) === 0
        : str_starts_with($path, $prefix));
    return $insideRoot && is_file($path) ? $path : null;
}

function collectLogs(PDO $db, bool $automatic = false): array
{
    $lock = fopen(dirname(__DIR__) . '/var/collector.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Не удалось открыть блокировку сборщика.');
    }

    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            return ['busy' => true, 'sites' => 0, 'added' => 0, 'errors' => 0];
        }
        $result = ['busy' => false, 'sites' => 0, 'added' => 0, 'errors' => 0];
        $settings = $db->query('SELECT log_root, auto_collect FROM settings WHERE id = 1')->fetch();
        if ($automatic && !(int) $settings['auto_collect']) {
            return $result;
        }
        $sites = $db->query('SELECT id, log_path FROM sites WHERE enabled = 1')->fetchAll();
        $result['sites'] = count($sites);
        $root = realpath($settings['log_root']);
        $signatures = $db->query('SELECT pattern, bot_id FROM bot_signatures s JOIN bots b ON b.id = s.bot_id
            WHERE s.enabled = 1 AND b.enabled = 1 ORDER BY length(pattern) DESC, s.id')->fetchAll();

        foreach ($sites as $site) {
            try {
                $path = $root !== false ? resolveLog($root, $site['log_path']) : null;
                if ($path === null) {
                    throw new RuntimeException('Лог недоступен.');
                }
                $result['added'] += collectFile($db, (int) $site['id'], $path, $signatures);
            } catch (Throwable $error) {
                $result['errors']++;
                error_log('CheckAI site ' . $site['id'] . ': ' . $error->getMessage());
            }
        }
        return $result;
    } finally {
        fclose($lock);
    }
}

function collectFile(PDO $db, int $site, string $path, array $signatures): int
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Не удалось открыть лог.');
    }
    $added = 0;
    try {
        $stat = fstat($handle);
        if ($stat === false) {
            throw new RuntimeException('Не удалось определить размер лога.');
        }
        $identity = [$site, $stat['dev'], $stat['ino']];
        $cursor = $db->prepare('SELECT byte_offset FROM log_cursors WHERE site_id = ? AND device = ? AND inode = ?');
        $cursor->execute($identity);
        $offset = (int) $cursor->fetchColumn();
        if ($offset > $stat['size']) {
            $offset = 0;
        }
        if (fseek($handle, $offset) !== 0) {
            throw new RuntimeException('Не удалось перейти к позиции в логе.');
        }
        $db->beginTransaction();
        $insert = $db->prepare('INSERT INTO requests (site_id, bot_id, requested_at_utc, method, request_path, http_status, source_ip)
            VALUES (?, ?, ?, ?, ?, ?, ?)');
        while ($offset < $stat['size'] && ($line = fgets($handle)) !== false) {
            if (!str_ends_with($line, "\n") || ftell($handle) > $stat['size']) {
                break;
            }
            $offset = ftell($handle);
            if (!preg_match('~^[^[]+\[([^]]+)\] "([^ "]+) ([^ "]+)(?: [^"]+)?" ([1-5]\d{2}) \S+ "(?:\\\\.|[^"\\\\])*" "((?:\\\\.|[^"\\\\])*)"~', $line, $match)) {
                continue;
            }
            foreach ($signatures as $signature) {
                if (stripos($match[5], $signature['pattern']) === false) {
                    continue;
                }
                $time = DateTimeImmutable::createFromFormat('!d/M/Y:H:i:s O', $match[1]);
                if ($time !== false) {
                    $insert->execute([$site, $signature['bot_id'], $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                        $match[2], explode('?', $match[3], 2)[0], (int) $match[4], ipKey(explode(' ', $line, 2)[0])]);
                    $added++;
                }
                break;
            }
        }
        $save = $db->prepare('INSERT INTO log_cursors (site_id, device, inode, byte_offset, updated_at_utc)
            VALUES (?, ?, ?, ?, ?) ON CONFLICT (site_id, device, inode)
            DO UPDATE SET byte_offset = excluded.byte_offset, updated_at_utc = excluded.updated_at_utc');
        $save->execute([...$identity, $offset, gmdate('Y-m-d\TH:i:s\Z')]);
        $db->commit();
        return $added;
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        fclose($handle);
    }
}
