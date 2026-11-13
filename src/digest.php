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

/**
 * Creates a text digest for the previous calendar day in the report timezone.
 *
 * @return array{path: string, date: string, total: int}
 */
function generateDailyDigest(PDO $database, ?DateTimeImmutable $now = null, ?string $directory = null): array
{
    $settings = $database->query(
        'SELECT report_timezone, digest_directory FROM settings WHERE id = 1'
    )->fetch();
    $timezone = new DateTimeZone((string) $settings['report_timezone']);
    $directory ??= (string) $settings['digest_directory'];
    $now = ($now ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone);
    $digestDate = $now->setTime(0, 0)->modify('-1 day');
    $startUtc = $digestDate->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    $endUtc = $digestDate->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

    $sites = $database->query('SELECT id, domain FROM sites WHERE archived = 0 ORDER BY domain COLLATE NOCASE')->fetchAll();
    $types = $database->query('SELECT id, name FROM bot_types ORDER BY id')->fetchAll();
    $counts = $database->prepare('SELECT b.bot_type_id, COUNT(*) AS request_count
        FROM requests r
        JOIN bots b ON b.id = r.bot_id AND b.disabled = 0 AND b.archived = 0
        JOIN companies c ON c.id = b.company_id AND c.disabled = 0
        WHERE r.site_id = ? AND r.requested_at_utc >= ? AND r.requested_at_utc < ?
        GROUP BY b.bot_type_id');

    $lines = [
        'Ежедневный дайджест посещений ИИ-ботов за ' . $digestDate->format('d.m.Y'),
        '',
    ];
    $overall = array_fill_keys(array_map(static fn (array $type): int => (int) $type['id'], $types), 0);
    $grandTotal = 0;

    foreach ($sites as $site) {
        $counts->execute([(int) $site['id'], $startUtc, $endUtc]);
        $siteCounts = $overall;
        foreach ($counts->fetchAll() as $row) {
            $typeId = (int) $row['bot_type_id'];
            if (array_key_exists($typeId, $siteCounts)) {
                $siteCounts[$typeId] = (int) $row['request_count'];
                $overall[$typeId] += (int) $row['request_count'];
            }
        }
        $siteTotal = array_sum($siteCounts);
        $grandTotal += $siteTotal;
        $lines[] = $site['domain'] . ' — всего: ' . $siteTotal . ' посещений ИИ-ботов';
        foreach ($types as $type) {
            $lines[] = '  ' . $type['name'] . ': ' . $siteCounts[(int) $type['id']];
        }
        $lines[] = '';
    }

    $lines[] = 'Итого по всем сайтам — ' . $grandTotal . ' посещений ИИ-ботов';
    foreach ($types as $type) {
        $lines[] = '  ' . $type['name'] . ': ' . $overall[(int) $type['id']];
    }
    $content = implode(PHP_EOL, $lines) . PHP_EOL;

    if (!is_dir($directory) || !is_writable($directory)) {
        throw new RuntimeException(sprintf('Каталог дайджестов %s отсутствует или недоступен для записи.', $directory));
    }

    $filename = $now->format('Ymd-H-i') . '_digest.txt';
    $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
    $handle = @fopen($path, 'x');
    if ($handle === false) {
        throw new RuntimeException(sprintf('Не удалось создать файл дайджеста %s. Возможно, файл с таким именем уже существует.', $path));
    }
    try {
        if (fwrite($handle, $content) !== strlen($content)) {
            throw new RuntimeException(sprintf('Не удалось полностью записать файл дайджеста %s.', $path));
        }
    } catch (Throwable $error) {
        fclose($handle);
        throw $error;
    }
    fclose($handle);

    return ['path' => $path, 'date' => $digestDate->format('d.m.Y'), 'total' => $grandTotal];
}
