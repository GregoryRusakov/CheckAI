<?php

$timezone = new DateTimeZone($db->query('SELECT report_timezone FROM settings WHERE id = 1')->fetchColumn());
$today = new DateTimeImmutable('today', $timezone);
$savedSettings = (array) json_decode($user['report_filters'] ?? '{}', true);
$filterLabels = [
    'verified' => 'Только подтверждённые по IP боты',
    'pages' => 'Исключить картинки, css, js и прочие',
    'ok' => 'Только успешные (HTTP 200)',
];
$filters = isset($_GET['filters_set'])
    ? array_intersect_key($_GET, $filterLabels)
    : array_intersect_key($savedSettings, $filterLabels);
$parseDate = static function (mixed $value) use ($timezone): ?DateTimeImmutable {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
    return $date && $date->format('Y-m-d') === $value ? $date : null;
};
$from = $parseDate($_GET['from'] ?? null) ?? $parseDate($savedSettings['period_from'] ?? null);
$to = $parseDate($_GET['to'] ?? null) ?? $parseDate($savedSettings['period_to'] ?? null);
$from ??= $to ?? $today;
$to ??= $from;
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$quickPeriods = [
    '30 дней' => [$today->modify('-29 days'), $today],
    'Этот месяц' => [$today->modify('first day of this month'), $today],
    '14 дней' => [$today->modify('-13 days'), $today],
    'Эта неделя' => [$today->modify('monday this week'), $today],
    'Вчера' => [$today->modify('-1 day'), $today->modify('-1 day')],
    'Сегодня' => [$today, $today],
];
$sites = $db->query('SELECT id, domain FROM sites WHERE archived = 0 OR EXISTS (SELECT 1 FROM requests WHERE site_id = sites.id) ORDER BY domain')->fetchAll();
$siteId = filter_var($_GET['site'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
if ($siteId && !in_array($siteId, array_map('intval', array_column($sites, 'id')), true)) {
    $siteId = 0;
}
$botTypes = $db->query('SELECT id, name FROM bot_types ORDER BY id')->fetchAll();
$disabledTypes = is_array($savedSettings['chart_disabled_types'] ?? null) ? $savedSettings['chart_disabled_types'] : [];
$utc = new DateTimeZone('UTC');
$db->sqliteCreateFunction('chart_local_date', static function (string $value) use ($timezone): string {
    return (new DateTimeImmutable($value))->setTimezone($timezone)->format('Y-m-d');
}, 1, PDO::SQLITE_DETERMINISTIC);
if (isset($filters['pages'])) {
    $db->sqliteCreateFunction('is_site_page', static function (string $path): int {
        $path = strtolower(rawurldecode($path));
        $filename = basename($path);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        return (int) !in_array($extension, [
            'js', 'mjs', 'cjs', 'css', 'map', 'wasm',
            'woff', 'woff2', 'ttf', 'otf', 'eot', 'ttc',
            'png', 'jpg', 'jpeg', 'jfif', 'gif', 'webp', 'avif', 'svg', 'svgz', 'ico',
            'bmp', 'tif', 'tiff', 'apng', 'heic', 'heif', 'jxl',
            'mp3', 'wav', 'ogg', 'oga', 'flac', 'aac', 'm4a',
            'mp4', 'webm', 'ogv', 'mov', 'm4v', 'm3u8', 'ts', 'vtt',
            'json', 'webmanifest', 'manifest', 'appcache', 'rss', 'atom',
        ], true);
    }, 1, PDO::SQLITE_DETERMINISTIC);
}
$conditions = [
    'verified' => 'EXISTS (SELECT 1 FROM bot_ip_ranges ip WHERE ip.bot_id = r.bot_id
        AND length(ip.first_ip) = length(r.source_ip) AND r.source_ip BETWEEN ip.first_ip AND ip.last_ip)',
    'pages' => 'is_site_page(r.request_path) = 1',
    'ok' => 'r.http_status = 200',
];
$query = $db->prepare('SELECT chart_local_date(r.requested_at_utc) AS day, b.bot_type_id, COUNT(*) AS hits
    FROM requests r JOIN bots b ON b.id = r.bot_id JOIN companies c ON c.id = b.company_id
    WHERE r.requested_at_utc >= :start AND r.requested_at_utc < :end
        AND b.disabled = 0 AND c.disabled = 0' . ($siteId ? ' AND r.site_id = :site' : '')
        . ($filters ? ' AND ' . implode(' AND ', array_intersect_key($conditions, $filters)) : '') . '
    GROUP BY day, b.bot_type_id');
$query->bindValue(':start', $from->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'));
$query->bindValue(':end', $to->modify('+1 day')->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'));
if ($siteId) {
    $query->bindValue(':site', $siteId, PDO::PARAM_INT);
}
$query->execute();
$counts = [];
foreach ($query as $row) {
    $counts[$row['day']][(int) $row['bot_type_id']] = (int) $row['hits'];
}
$days = [];
for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
    $date = $day->format('Y-m-d');
    $values = [];
    foreach ($botTypes as $type) {
        $values[] = $counts[$date][(int) $type['id']] ?? 0;
    }
    $days[] = ['date' => $date, 'values' => $values];
}
$chartData = json_encode([
    'types' => array_column($botTypes, 'name'),
    'typeIds' => array_map('intval', array_column($botTypes, 'id')),
    'csrf' => $token,
    'site' => $siteId,
    'filters' => array_fill_keys(array_keys($filters), '1'),
    'days' => $days,
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
?>
<section id="charts">
    <form method="get" action="/" id="report-filters" class="filters">
        <input type="hidden" name="view" value="charts">
        <input type="hidden" name="filters_set" value="1">
        <div class="filters__controls">
            <label class="field filters__inline-field">
                <span>Сайт</span>
                <select name="site">
                    <option value="">Все сайты</option>
                    <?php foreach ($sites as $site): ?>
                        <option value="<?= (int) $site['id'] ?>"<?= $siteId === (int) $site['id'] ? ' selected' : '' ?>><?= escape($site['domain']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field filters__inline-field"><span>Период с</span><input type="date" name="from" value="<?= escape($from->format('Y-m-d')) ?>" autocomplete="off"></label>
            <label class="field filters__inline-field"><span>По</span><input type="date" name="to" value="<?= escape($to->format('Y-m-d')) ?>" autocomplete="off"></label>
            <div class="filters__presets" aria-label="Быстрый выбор периода">
                <?php foreach ($quickPeriods as $label => [$quickFrom, $quickTo]): ?>
                    <button class="btn btn--sm" type="button" data-period-from="<?= escape($quickFrom->format('Y-m-d')) ?>" data-period-to="<?= escape($quickTo->format('Y-m-d')) ?>"><?= escape($label) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <fieldset class="filters__flags">
            <legend class="filters__legend">Учитывать в графике</legend>
            <div class="filters__checks">
                <?php foreach ($filterLabels as $key => $label): ?>
                    <label class="check"><input type="checkbox" name="<?= escape($key) ?>" value="1"<?= isset($filters[$key]) ? ' checked' : '' ?>> <?= escape($label) ?></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <noscript><button class="btn" type="submit">Применить</button></noscript>
    </form>
    <div class="scope"><h2>Запросы по дням</h2></div>
    <p class="chart__hint">Количество запросов за день по видам ботов. Часовой пояс: <?= escape($timezone->getName()) ?>.</p>
    <div class="chart" data-chart="<?= escape($chartData) ?>">
        <div class="chart__scroll"><svg class="chart__plot" preserveAspectRatio="xMinYMin meet" role="group" aria-label="Диаграмма запросов по дням и видам ботов"></svg></div>
        <p class="chart__empty" hidden>За выбранный период запросов выбранных видов нет.</p>
        <div class="chart__tooltip" id="chart-tooltip" role="tooltip" hidden>
            <strong class="chart__tooltip-total"></strong>
            <span class="chart__tooltip-type"></span>
        </div>
        <fieldset class="filters__flags chart__legend">
            <legend class="filters__legend">Виды ботов</legend>
            <div class="filters__checks">
                <?php foreach ($botTypes as $index => $type): ?>
                    <label class="check"><input type="checkbox" data-chart-type="<?= $index ?>"<?= in_array((int) $type['id'], $disabledTypes, true) ? '' : ' checked' ?>> <span class="chart__swatch" data-chart-swatch="<?= $index ?>" aria-hidden="true"></span> <?= escape($type['name']) ?></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
    </div>
    <details class="chart__table">
        <summary>Данные по дням</summary>
        <div class="ledger-wrap"><table class="ledger">
            <thead><tr><th scope="col">Дата</th><?php foreach ($botTypes as $type): ?><th scope="col" class="num"><?= escape($type['name']) ?></th><?php endforeach; ?></tr></thead>
            <tbody><?php foreach ($days as $row): ?><tr><th scope="row"><?= escape($row['date']) ?></th><?php foreach ($row['values'] as $value): ?><td class="num"><?= formatCount($value) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
        </table></div>
    </details>
</section>
