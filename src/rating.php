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

$companyId = filter_var($_GET['company'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$botId = filter_var($_GET['bot'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$siteId = filter_var($_GET['site'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$savedSettings = (array) json_decode($user['report_filters'] ?? '{}', true);
$savedSiteSort = match ($savedSettings['site_sort'] ?? 'domain_asc') {
    'domain', 'domain_asc' => 'domain_asc',
    'domain_desc' => 'domain_desc',
    'hits', 'hits_desc' => 'hits_desc',
    'hits_asc' => 'hits_asc',
    default => 'domain_asc',
};
$siteSort = $savedSiteSort;
$botTypeValue = is_string($_GET['bot_type'] ?? null)
    ? $_GET['bot_type']
    : (is_string($savedSettings['bot_type'] ?? null) ? $savedSettings['bot_type'] : '');
$botTypeId = filter_var($botTypeValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$botTypes = $db->query('SELECT id, name FROM bot_types ORDER BY id')->fetchAll();
$validBotTypeIds = array_map('intval', array_column($botTypes, 'id'));
if ($botTypeId && !in_array($botTypeId, $validBotTypeIds, true)) {
    $botTypeId = 0;
}

$filterLabels = [
    'verified' => 'Только подтверждённые по IP боты',
    'pages' => 'Исключить картинки, css, js и прочие',
    'ok' => 'Только успешные (HTTP 200)',
];
$savedFilters = array_intersect_key($savedSettings, $filterLabels);
$filters = isset($_GET['filters_set']) ? array_intersect_key($_GET, $filterLabels) : $savedFilters;

$timezone = new DateTimeZone($db->query('SELECT report_timezone FROM settings WHERE id = 1')->fetchColumn());
$today = new DateTimeImmutable('today', $timezone);
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
$period = ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
$utc = new DateTimeZone('UTC');
$start = $db->quote($from->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'));
$end = $db->quote($to->modify('+1 day')->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'));

$verifiedSql = 'EXISTS (SELECT 1 FROM bot_ip_ranges ip WHERE ip.bot_id = r.bot_id
    AND length(ip.first_ip) = length(r.source_ip) AND r.source_ip BETWEEN ip.first_ip AND ip.last_ip)';
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
    'verified' => $verifiedSql,
    'pages' => 'is_site_page(r.request_path) = 1',
    'ok' => 'r.http_status = 200',
];
$requestSource = '(SELECT r.*, ' . $verifiedSql . ' AS verified FROM requests r'
    . " WHERE r.requested_at_utc >= $start AND r.requested_at_utc < $end"
    . ' AND EXISTS (SELECT 1 FROM bots active_bot JOIN companies active_company ON active_company.id = active_bot.company_id WHERE active_bot.id = r.bot_id AND active_bot.disabled = 0 AND active_company.disabled = 0)'
    . ($botTypeId ? " AND EXISTS (SELECT 1 FROM bots bt WHERE bt.id = r.bot_id AND bt.bot_type_id = $botTypeId)" : '')
    . ($filters ? ' AND ' . implode(' AND ', array_intersect_key($conditions, $filters)) : '') . ')';

$state = ['view' => 'rating', 'from' => $period['from'], 'to' => $period['to'], 'filters_set' => '1'];
foreach ($filterLabels as $key => $_label) {
    if (isset($filters[$key])) {
        $state[$key] = '1';
    }
}
if ($botTypeId) {
    $state['bot_type'] = (string) $botTypeId;
}

function ratingUrl(array $params = []): string
{
    global $state;
    return '/?' . http_build_query($params + $state) . '#rating';
}

function ratingReportUrl(array $params = []): string
{
    global $state;
    $reportState = $state;
    unset($reportState['view']);
    return '/?' . http_build_query($params + $reportState) . '#reports';
}

function ratingCountBucket(int $value, int $max): int
{
    if ($value <= 0 || $max <= 0) {
        return 0;
    }

    return max(1, min(10, (int) ceil($value / $max * 10)));
}

$company = false;
$bot = false;
$site = false;
if ($companyId) {
    $lookup = $db->prepare('SELECT id, name FROM companies WHERE id = ? AND disabled = 0');
    $lookup->execute([$companyId]);
    $company = $lookup->fetch();
    if (!$company) {
        $companyId = 0;
        $botId = 0;
        $siteId = 0;
    }
}
if ($company && $botId) {
    $lookup = $db->prepare('SELECT id, name, description FROM bots WHERE id = ? AND company_id = ? AND disabled = 0');
    $lookup->execute([$botId, $companyId]);
    $bot = $lookup->fetch();
    if (!$bot) {
        $botId = 0;
        $siteId = 0;
    }
}
if ($bot && $siteId) {
    $lookup = $db->prepare('SELECT id, domain FROM sites WHERE id = ?');
    $lookup->execute([$siteId]);
    $site = $lookup->fetch();
    if (!$site) {
        $siteId = 0;
    }
}

?>
<section id="rating">
    <nav class="crumbs crumbs--lead" aria-label="Путь в рейтинге">
        <ol>
            <li><?= $company ? '<a href="' . escape(ratingUrl()) . '">Рейтинг</a>' : '<span aria-current="page">Рейтинг</span>' ?></li>
            <?php if ($company): ?>
                <li><?= $bot ? '<a href="' . escape(ratingUrl(['company' => $companyId])) . '">' . escape($company['name']) . '</a>' : '<span aria-current="page">' . escape($company['name']) . '</span>' ?></li>
            <?php endif; ?>
            <?php if ($bot): ?>
                <li><?= $site ? '<a href="' . escape(ratingUrl(['company' => $companyId, 'bot' => $botId])) . '">' . escape($bot['name']) . '</a>' : '<span aria-current="page">' . escape($bot['name']) . '</span>' ?></li>
            <?php endif; ?>
            <?php if ($site): ?><li><span aria-current="page"><?= escape($site['domain']) ?></span></li><?php endif; ?>
        </ol>
    </nav>

    <form method="post" action="/" id="report-filters" class="filters" data-busy>
        <input type="hidden" name="csrf" value="<?= escape($token) ?>">
        <input type="hidden" name="action" value="report_filters">
        <input type="hidden" name="view" value="rating">
        <input type="hidden" name="filters_set" value="1">
        <input type="hidden" name="site_sort" value="<?= escape($siteSort) ?>">
        <?php foreach (['company' => $companyId, 'bot' => $botId, 'site' => $siteId] as $key => $id): ?>
            <?php if ($id): ?><input type="hidden" name="<?= escape($key) ?>" value="<?= $id ?>"><?php endif; ?>
        <?php endforeach; ?>
        <div class="filters__controls">
            <label class="field filters__inline-field">
                <span>Вид</span>
                <select name="bot_type">
                    <option value="">Все виды</option>
                    <?php foreach ($botTypes as $type): ?>
                        <option value="<?= (int) $type['id'] ?>"<?= $botTypeId === (int) $type['id'] ? ' selected' : '' ?>><?= escape($type['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field filters__inline-field"><span>Период с</span><input type="date" name="from" value="<?= escape($period['from']) ?>" autocomplete="off"></label>
            <label class="field filters__inline-field"><span>По</span><input type="date" name="to" value="<?= escape($period['to']) ?>" autocomplete="off"></label>
            <div class="filters__presets" aria-label="Быстрый выбор периода">
                <?php foreach ($quickPeriods as $label => [$quickFrom, $quickTo]): ?>
                    <button class="btn btn--sm" type="button" data-period-from="<?= escape($quickFrom->format('Y-m-d')) ?>" data-period-to="<?= escape($quickTo->format('Y-m-d')) ?>"><?= escape($label) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <fieldset class="filters__flags">
            <legend class="filters__legend">Учитывать в рейтинге</legend>
            <div class="filters__checks">
                <?php foreach ($filterLabels as $key => $label): ?>
                    <label class="check"><input type="checkbox" name="<?= escape($key) ?>" value="1"<?= isset($filters[$key]) ? ' checked' : '' ?>> <?= escape($label) ?></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <noscript><button class="btn" type="submit">Применить</button></noscript>
    </form>

    <?php if ($site): ?>
        <?php
        $query = $db->prepare('SELECT r.request_path, COUNT(*) AS hits FROM ' . $requestSource . ' AS r
            WHERE r.site_id = ? AND r.bot_id = ? GROUP BY r.request_path ORDER BY hits DESC, r.request_path');
        $query->execute([$siteId, $botId]);
        $rows = $query->fetchAll();
        $hits = array_sum(array_column($rows, 'hits'));
        $max = $rows ? (int) max(array_column($rows, 'hits')) : 0;
        ?>
        <div class="scope"><h2>Страницы на <?= escape($site['domain']) ?></h2></div>
        <p class="total"><strong><?= formatCount($hits) ?></strong> запросов к <?= formatCount(count($rows)) ?> страницам</p>
        <?php if (!$rows): ?>
            <div class="empty"><p>За выбранный период обращений к страницам нет.</p></div>
        <?php else: ?>
            <div class="ledger-wrap"><table class="ledger ledger--rank">
                <thead><tr><th scope="col">Страница</th><th scope="col" class="num">Запросов</th></tr></thead>
                <tbody><?php foreach ($rows as $row): ?>
                    <?php $url = ratingReportUrl([
                        'site' => $siteId,
                        'company' => $companyId,
                        'bot' => $botId,
                        'path' => $row['request_path'],
                    ]); ?>
                    <tr>
                        <td class="path"><a href="<?= escape($url) ?>"><?= escape(rawurldecode($row['request_path'])) ?></a></td>
                        <td class="num"><span class="bar" data-b="<?= ratingCountBucket((int) $row['hits'], $max) ?>" aria-hidden="true"></span><?= formatCount((int) $row['hits']) ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    <?php elseif ($bot): ?>
        <?php
        $query = $db->prepare('SELECT s.id, s.domain, COUNT(r.id) AS hits FROM sites s
            JOIN ' . $requestSource . ' AS r ON r.site_id = s.id
            WHERE r.bot_id = ? GROUP BY s.id ORDER BY hits DESC, s.domain');
        $query->execute([$botId]);
        $rows = $query->fetchAll();
        $max = $rows ? (int) max(array_column($rows, 'hits')) : 0;
        ?>
        <div class="scope"><h2>Сайты для <?= escape($bot['name']) ?></h2></div>
        <?php if (!$rows): ?>
            <div class="empty"><p>За выбранный период запросов к сайтам нет.</p></div>
        <?php else: ?>
            <p class="total"><strong><?= formatCount(array_sum(array_column($rows, 'hits'))) ?></strong> запросов по <?= formatCount(count($rows)) ?> сайтам</p>
            <div class="ledger-wrap"><table class="ledger ledger--rank">
                <thead><tr><th scope="col">Сайт</th><th scope="col" class="num">Запросов</th></tr></thead>
                <tbody><?php foreach ($rows as $row): ?>
                    <?php $url = ratingUrl(['company' => $companyId, 'bot' => $botId, 'site' => $row['id']]); ?>
                    <tr>
                        <td><a href="<?= escape($url) ?>"><?= escape($row['domain']) ?></a></td>
                        <td class="num"><span class="bar" data-b="<?= ratingCountBucket((int) $row['hits'], $max) ?>" aria-hidden="true"></span><?= formatCount((int) $row['hits']) ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    <?php elseif ($company): ?>
        <?php
        $query = $db->prepare('SELECT b.id, b.name, COUNT(r.id) AS hits FROM bots b
            JOIN ' . $requestSource . ' AS r ON r.bot_id = b.id
            WHERE b.company_id = ? AND b.disabled = 0 GROUP BY b.id ORDER BY hits DESC, b.name');
        $query->execute([$companyId]);
        $rows = $query->fetchAll();
        $max = $rows ? (int) max(array_column($rows, 'hits')) : 0;
        ?>
        <div class="scope"><h2>Боты компании <?= escape($company['name']) ?></h2></div>
        <?php if (!$rows): ?>
            <div class="empty"><p>За выбранный период запросов от ботов компании нет.</p></div>
        <?php else: ?>
            <p class="total"><strong><?= formatCount(array_sum(array_column($rows, 'hits'))) ?></strong> запросов от <?= formatCount(count($rows)) ?> ботов</p>
            <div class="ledger-wrap"><table class="ledger ledger--rank">
                <thead><tr><th scope="col">Бот</th><th scope="col" class="num">Запросов</th></tr></thead>
                <tbody><?php foreach ($rows as $row): ?>
                    <?php $url = ratingUrl(['company' => $companyId, 'bot' => $row['id']]); ?>
                    <tr>
                        <td><a href="<?= escape($url) ?>"><?= escape($row['name']) ?></a></td>
                        <td class="num"><span class="bar" data-b="<?= ratingCountBucket((int) $row['hits'], $max) ?>" aria-hidden="true"></span><?= formatCount((int) $row['hits']) ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    <?php else: ?>
        <?php
        $rows = $db->query('SELECT c.id, c.name, COUNT(r.id) AS hits FROM companies c
            JOIN bots b ON b.company_id = c.id AND b.disabled = 0
            JOIN ' . $requestSource . ' AS r ON r.bot_id = b.id
            WHERE c.disabled = 0 GROUP BY c.id ORDER BY hits DESC, c.name')->fetchAll();
        $max = $rows ? (int) max(array_column($rows, 'hits')) : 0;
        ?>
        <div class="scope"><h2>Компании</h2></div>
        <?php if (!$rows): ?>
            <div class="empty"><p>За выбранный период обращений ботов нет.</p></div>
        <?php else: ?>
            <p class="total"><strong><?= formatCount(array_sum(array_column($rows, 'hits'))) ?></strong> запросов от <?= formatCount(count($rows)) ?> компаний</p>
            <div class="ledger-wrap"><table class="ledger ledger--rank">
                <thead><tr><th scope="col">Компания</th><th scope="col" class="num">Запросов</th></tr></thead>
                <tbody><?php foreach ($rows as $row): ?>
                    <?php $url = ratingUrl(['company' => $row['id']]); ?>
                    <tr>
                        <td><a href="<?= escape($url) ?>"><?= escape($row['name']) ?></a></td>
                        <td class="num"><span class="bar" data-b="<?= ratingCountBucket((int) $row['hits'], $max) ?>" aria-hidden="true"></span><?= formatCount((int) $row['hits']) ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    <?php endif; ?>
</section>
