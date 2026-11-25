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


$siteId = filter_var($_GET['site'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$companyId = filter_var($_GET['company'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$botId = filter_var($_GET['bot'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$savedReportSettings = (array) json_decode($user['report_filters'] ?? '{}', true);
$botTypes = $db->query('SELECT id, name FROM bot_types ORDER BY id')->fetchAll();
$validBotTypeIds = array_map('intval', array_column($botTypes, 'id'));
$isValidSiteSort = static function (mixed $value) use ($validBotTypeIds): bool {
    if (!is_string($value)) {
        return false;
    }
    if (in_array($value, ['domain_asc', 'domain_desc', 'hits_asc', 'hits_desc'], true)) {
        return true;
    }
    return preg_match('/^type_(\d+)_(?:asc|desc)$/D', $value, $matches)
        && in_array((int) $matches[1], $validBotTypeIds, true);
};
$savedSiteSort = $savedReportSettings['site_sort'] ?? 'domain_asc';
$siteSort = $isValidSiteSort($_GET['site_sort'] ?? null)
    ? $_GET['site_sort']
    : ($isValidSiteSort($savedSiteSort) ? $savedSiteSort : 'domain_asc');
$botTypeValue = is_string($_GET['bot_type'] ?? null)
    ? $_GET['bot_type']
    : (is_string($savedReportSettings['bot_type'] ?? null) ? $savedReportSettings['bot_type'] : '');
$botTypeId = filter_var($botTypeValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
if ($botTypeId && !in_array($botTypeId, array_map('intval', $validBotTypeIds), true)) {
    $botTypeId = 0;
}
$botTypeParams = $botTypeId ? ['bot_type' => $botTypeId] : [];
$path = is_string($_GET['path'] ?? null) ? $_GET['path'] : null;
$botParams = ['site' => $siteId, 'company' => $companyId, 'bot' => $botId];
$timeSort = ($_GET['time_sort'] ?? null) === 'asc' ? 'asc' : 'desc';
$page = filter_var($_GET['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;

$company = false;
$bot = false;
$botDescription = '';
$site = false;
if ($siteId) {
    $lookup = $db->prepare('SELECT domain FROM sites WHERE id = ?');
    $lookup->execute([$siteId]);
    $site = $lookup->fetchColumn();
}
$siteParams = ['site' => $siteId];
if ($site !== false && $companyId) {
    $lookup = $db->prepare('SELECT name FROM companies WHERE id = ? AND disabled = 0');
    $lookup->execute([$companyId]);
    $company = $lookup->fetchColumn();
}
if ($company !== false && $botId) {
    $lookup = $db->prepare('SELECT b.name, b.description FROM bots b JOIN companies c ON c.id = b.company_id WHERE b.id = ? AND b.company_id = ? AND b.disabled = 0 AND c.disabled = 0');
    $lookup->execute([$botId, $companyId]);
    $botRow = $lookup->fetch();
    if ($botRow) {
        $bot = $botRow['name'];
        $botDescription = $botRow['description'];
    }
}

$filterLabels = [
    'verified' => 'Только подтверждённые по IP боты',
    'pages' => 'Исключить картинки, css, js и прочие',
    'ok' => 'Только успешные (HTTP 200)',
];
$savedFilters = array_intersect_key($savedReportSettings, $filterLabels);
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
$from = $parseDate($_GET['from'] ?? null) ?? $parseDate($savedReportSettings['period_from'] ?? null);
$to = $parseDate($_GET['to'] ?? null) ?? $parseDate($savedReportSettings['period_to'] ?? null);
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

function reportUrl(array $params = []): string
{
    global $period, $botTypeParams, $siteSort, $filters;
    $params += $botTypeParams;
    $params += $filters;
    $params += $period;
    $params += ['site_sort' => $siteSort, 'filters_set' => '1'];
    return '/' . ($params ? '?' . http_build_query($params) : '') . '#reports';
}

function renderReportSortHeading(string $label, string $key, bool $numeric = false): void
{
    global $siteSort;
    $ascending = $key . '_asc';
    $descending = $key . '_desc';
    $active = in_array($siteSort, [$ascending, $descending], true);
    $nextSort = $numeric
        ? ($siteSort === $descending ? $ascending : $descending)
        : ($siteSort === $ascending ? $descending : $ascending);
    $arrow = !$active ? '↕' : ($siteSort === $ascending ? '↑' : '↓');
    $sortDirection = !$active ? 'none' : ($siteSort === $ascending ? 'ascending' : 'descending');
    echo '<th scope="col"' . ($numeric ? ' class="num"' : '') . ($active ? ' aria-sort="' . $sortDirection . '"' : '') . '>'
        . '<a class="sort-link" href="' . escape(reportUrl(['site_sort' => $nextSort])) . '">'
        . escape($label) . ' <span aria-hidden="true">' . $arrow . '</span></a></th>';
}

function renderPageLink(string $domain, string $path): void
{
    $label = escape(rawurldecode($path));
    if (!str_starts_with($path, '/') || !filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        echo $label;
        return;
    }
    $href = escape('https://' . $domain . $path);
    echo '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>';
}

function statusLabel(int $code): string
{
    $labels = [
        100 => 'Продолжить', 101 => 'Переключение протокола', 103 => 'Ранние подсказки',
        200 => 'OK', 201 => 'Создано', 202 => 'Принято', 203 => 'Неавторитетная информация',
        204 => 'Нет содержимого', 205 => 'Сброс содержимого', 206 => 'Частичное содержимое',
        207 => 'Мультистатус', 208 => 'Уже сообщено', 226 => 'Использовано IM',
        300 => 'Несколько вариантов', 301 => 'Перемещено навсегда', 302 => 'Временное перенаправление',
        303 => 'Смотреть другой ресурс', 304 => 'Не изменено', 305 => 'Использовать прокси',
        307 => 'Временное перенаправление', 308 => 'Постоянное перенаправление',
        400 => 'Некорректный запрос', 401 => 'Требуется авторизация', 402 => 'Требуется оплата',
        403 => 'Доступ запрещён', 404 => 'Не найдено', 405 => 'Метод не разрешён',
        406 => 'Неприемлемый формат', 407 => 'Требуется авторизация прокси', 408 => 'Время ожидания запроса истекло',
        409 => 'Конфликт', 410 => 'Ресурс удалён', 411 => 'Требуется длина запроса',
        412 => 'Предварительное условие не выполнено', 413 => 'Слишком большой запрос',
        414 => 'Слишком длинный URI', 415 => 'Неподдерживаемый тип данных',
        416 => 'Диапазон недоступен', 417 => 'Ожидание не выполнено', 418 => 'Я чайник',
        421 => 'Запрос направлен неверно', 422 => 'Не удалось обработать содержимое',
        423 => 'Заблокировано', 424 => 'Ошибка зависимости', 425 => 'Слишком рано',
        426 => 'Требуется обновление протокола', 428 => 'Требуется предварительное условие',
        429 => 'Слишком много запросов', 431 => 'Слишком большие заголовки запроса',
        451 => 'Недоступно по юридическим причинам', 499 => 'Клиент закрыл соединение',
        500 => 'Внутренняя ошибка сервера', 501 => 'Не реализовано', 502 => 'Ошибка шлюза',
        503 => 'Сервис недоступен', 504 => 'Время ожидания шлюза истекло',
        505 => 'Версия HTTP не поддерживается', 506 => 'Ошибка согласования варианта',
        507 => 'Недостаточно места', 508 => 'Обнаружен цикл', 510 => 'Требуется расширение',
        511 => 'Требуется сетевая авторизация',
    ];
    $classes = [1 => 'Информационный ответ', 2 => 'Успешный ответ', 3 => 'Перенаправление',
        4 => 'Ошибка клиента', 5 => 'Ошибка сервера'];
    return $code . ' (' . ($labels[$code] ?? $classes[intdiv($code, 100)] ?? 'Неизвестный статус') . ')';
}

function countBucket(int $value, int $max): int
{
    if ($value <= 0 || $max <= 0) {
        return 0;
    }
    return max(1, min(10, (int) ceil($value / $max * 10)));
}

function utcOffsetLabel(DateTimeZone $timezone, DateTimeInterface $moment): string
{
    $offset = $timezone->getOffset($moment);
    $minutes = intdiv(abs($offset), 60);
    $hours = intdiv($minutes, 60);
    $rest = $minutes % 60;

    return 'UTC' . ($offset < 0 ? '-' : '+') . $hours
        . ($rest !== 0 ? ':' . str_pad((string) $rest, 2, '0', STR_PAD_LEFT) : '');
}

$scopeTitle = match (true) {
    $bot !== false && $path !== null => 'Запросы к странице',
    $bot !== false => 'Страницы бота ' . $bot,
    $company !== false => 'Боты поставщика',
    $site !== false => 'Поставщики ботов',
    default => 'Сайты',
};

?>
<section id="reports">
    <?php if ($site !== false): ?>
        <nav class="crumbs crumbs--lead" aria-label="Путь в отчёте">
            <ol>
                <li><a href="<?= escape(reportUrl()) ?>">Сайты</a></li>
                <?php if ($company !== false): ?>
                    <li><a href="<?= escape(reportUrl($siteParams)) ?>"><strong><?= escape($site) ?></strong></a></li>
                <?php else: ?>
                    <li><span aria-current="page"><strong><?= escape($site) ?></strong></span></li>
                <?php endif; ?>
                <?php if ($bot !== false): ?>
                    <li><a href="<?= escape(reportUrl($siteParams + ['company' => $companyId])) ?>"><?= escape($company) ?></a></li>
                    <?php if ($path !== null): ?>
                        <li><a href="<?= escape(reportUrl($botParams)) ?>"><?= escape($bot) ?></a></li>
                        <li><span aria-current="page"><?= escape(rawurldecode($path)) ?></span></li>
                    <?php else: ?>
                        <li><span aria-current="page"><?= escape($bot) ?></span></li>
                    <?php endif; ?>
                <?php else: ?>
                    <li><span aria-current="page"><?= escape($company) ?></span></li>
                <?php endif; ?>
            </ol>
        </nav>
    <?php endif; ?>
    <form method="post" action="/" id="report-filters" class="filters" data-busy>
        <input type="hidden" name="csrf" value="<?= escape($token) ?>">
        <input type="hidden" name="action" value="report_filters">
        <input type="hidden" name="filters_set" value="1">
        <input type="hidden" name="site_sort" value="<?= escape($siteSort) ?>">
        <?php foreach (['site' => $siteId, 'company' => $companyId, 'bot' => $botId] as $key => $id): ?>
            <?php if ($id): ?><input type="hidden" name="<?= escape($key) ?>" value="<?= $id ?>"><?php endif; ?>
        <?php endforeach; ?>
        <?php if ($path !== null): ?><input type="hidden" name="path" value="<?= escape($path) ?>"><?php endif; ?>
        <?php if ($path !== null): ?><input type="hidden" name="time_sort" value="<?= escape($timeSort) ?>"><?php endif; ?>
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
            <label class="field filters__inline-field">
                <span>Период с</span>
                <input type="date" name="from" value="<?= escape($period['from']) ?>" autocomplete="off">
            </label>
            <label class="field filters__inline-field">
                <span>По</span>
                <input type="date" name="to" value="<?= escape($period['to']) ?>" autocomplete="off">
            </label>
            <div class="filters__presets" aria-label="Быстрый выбор периода">
                <?php foreach ($quickPeriods as $label => [$quickFrom, $quickTo]): ?>
                    <button class="btn btn--sm" type="button" data-period-from="<?= escape($quickFrom->format('Y-m-d')) ?>" data-period-to="<?= escape($quickTo->format('Y-m-d')) ?>"><?= escape($label) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <fieldset class="filters__flags">
            <legend class="filters__legend">Учитывать в отчёте</legend>
            <div class="filters__checks">
                <?php foreach ($filterLabels as $key => $label): ?>
                    <label class="check">
                        <input type="checkbox" name="<?= escape($key) ?>" value="1"<?= isset($filters[$key]) ? ' checked' : '' ?>>
                        <?= escape($label) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <noscript>
            <button class="btn" type="submit">Применить</button>
        </noscript>
    </form>

    <?php if ($bot !== false && $path !== null): ?>
        <?php
        $count = $db->prepare('SELECT COUNT(*) FROM ' . $requestSource . ' AS r WHERE bot_id = ? AND site_id = ? AND request_path = ?');
        $count->execute([$botId, $siteId, $path]);
        $total = (int) $count->fetchColumn();
        $pageCount = max(1, (int) ceil($total / 50));
        $page = min($page, $pageCount);
        $timezone = new DateTimeZone($db->query('SELECT report_timezone FROM settings WHERE id = 1')->fetchColumn());
        $timeOrder = $timeSort === 'asc' ? 'ASC' : 'DESC';
        $requests = $db->prepare('SELECT r.requested_at_utc, s.domain, r.method, r.request_path, r.http_status, r.source_ip, r.verified
            FROM ' . $requestSource . ' AS r JOIN sites s ON s.id = r.site_id WHERE r.bot_id = ? AND r.site_id = ? AND r.request_path = ?
            ORDER BY r.requested_at_utc ' . $timeOrder . ', r.id ' . $timeOrder . ' LIMIT 50 OFFSET ?');
        $requests->bindValue(1, $botId, PDO::PARAM_INT);
        $requests->bindValue(2, $siteId, PDO::PARAM_INT);
        $requests->bindValue(3, $path, PDO::PARAM_STR);
        $requests->bindValue(4, ($page - 1) * 50, PDO::PARAM_INT);
        $requests->execute();
        ?>
        <div class="scope">
            <h2><?= escape($scopeTitle) ?></h2>
        </div>
        <p class="total"><strong><?= formatCount($total) ?></strong> запросов за период</p>
        <?php if ($total > 0): ?>
            <div class="ledger-wrap">
                <table class="ledger ledger--log">
                    <thead>
                    <tr>
                        <th scope="col">Дата</th>
                        <th scope="col" aria-sort="<?= $timeSort === 'asc' ? 'ascending' : 'descending' ?>">
                            <a class="sort-link" href="<?= escape(reportUrl($botParams + ['path' => $path, 'time_sort' => $timeSort === 'asc' ? 'desc' : 'asc'])) ?>">Время, <?= escape(utcOffsetLabel($timezone, $to)) ?> <span aria-hidden="true"><?= $timeSort === 'asc' ? '↑' : '↓' ?></span></a>
                        </th>
                        <th scope="col" class="col-site">Сайт</th>
                        <th scope="col">Метод</th>
                        <th scope="col">Страница</th>
                        <th scope="col">Статус</th>
                        <th scope="col">IP-адрес</th>
                        <th scope="col">Проверка IP</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($requests as $row): ?>
                        <?php $time = (new DateTimeImmutable($row['requested_at_utc']))->setTimezone($timezone); ?>
                        <tr>
                            <td class="nowrap"><time datetime="<?= escape($time->format('Y-m-d')) ?>"><?= escape($time->format('d.m.Y')) ?></time></td>
                            <td class="nowrap mono"><?= escape($time->format('H:i:s')) ?></td>
                            <td class="col-site"><?= escape($row['domain']) ?></td>
                            <td class="mono"><?= escape($row['method']) ?></td>
                            <td class="path"><?php renderPageLink($site, $row['request_path']); ?></td>
                            <td class="nowrap"><?= escape(statusLabel((int) $row['http_status'])) ?></td>
                            <td class="mono nowrap"><?= escape($row['source_ip'] === null ? '—' : (inet_ntop(hex2bin($row['source_ip'])) ?: '—')) ?></td>
                            <td class="nowrap">
                                <?php if ($row['verified']): ?>
                                    <span class="chip chip--ok">Подтверждён</span>
                                <?php elseif ($row['source_ip'] === null): ?>
                                    <span class="chip chip--muted">IP отсутствует</span>
                                <?php else: ?>
                                    <span class="chip">Не подтверждён</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if ($pageCount > 1): ?>
            <nav class="pager" aria-label="Страницы запросов">
                <?php $params = $botParams + ['path' => $path, 'time_sort' => $timeSort]; ?>
                <?php if ($page > 1): ?><a class="btn btn--sm" href="<?= escape(reportUrl($params + ['page' => $page - 1])) ?>">Назад</a><?php endif; ?>
                <span>Страница <span aria-current="page"><?= $page ?></span> из <?= $pageCount ?></span>
                <?php if ($page < $pageCount): ?><a class="btn btn--sm" href="<?= escape(reportUrl($params + ['page' => $page + 1])) ?>">Далее</a><?php endif; ?>
            </nav>
        <?php endif; ?>
        <?php if ($total === 0): ?>
            <div class="empty">
                <p>За выбранный период запросов нет.</p>
                <p>Расширьте период в фильтрах или <a href="<?= escape(reportUrl($botParams)) ?>">вернитесь к страницам бота</a>.</p>
            </div>
        <?php endif; ?>
    <?php elseif ($bot !== false): ?>
        <?php
        $pages = $db->prepare('SELECT request_path, COUNT(*) AS hits FROM ' . $requestSource . ' AS r
            WHERE site_id = ? AND bot_id = ? GROUP BY request_path ORDER BY hits DESC, request_path');
        $pages->execute([$siteId, $botId]);
        $rows = $pages->fetchAll();
        $max = $rows ? (int) max(array_column($rows, 'hits')) : 0;
        ?>
        <div class="scope">
            <div>
                <h2><?= escape($scopeTitle) ?></h2>
                <p><?= escape(implode(' / ', array_filter([$company, $botDescription], static fn ($value): bool => is_string($value) && $value !== ''))) ?></p>
            </div>
        </div>
        <?php if (!$rows): ?>
            <div class="empty">
                <p>За выбранный период бот не обращался к сайту.</p>
                <p>Расширьте период в фильтрах или <a href="<?= escape(reportUrl($siteParams + ['company' => $companyId])) ?>">вернитесь к ботам поставщика</a>.</p>
            </div>
        <?php else: ?>
            <p class="total"><strong><?= formatCount(array_sum(array_column($rows, 'hits'))) ?></strong> запросов к <?= formatCount(count($rows)) ?> страницам</p>
            <div class="ledger-wrap">
                <table class="ledger ledger--rank">
                    <thead>
                    <tr>
                        <th scope="col">Страница</th>
                        <th scope="col" class="num">Запросов</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php $requestUrl = reportUrl(['site' => $siteId, 'company' => $companyId, 'bot' => $botId, 'path' => $row['request_path']]); ?>
                        <tr>
                            <td class="path">
                                <a href="<?= escape($requestUrl) ?>"><?= escape(rawurldecode($row['request_path'])) ?></a>
                            </td>
                            <td class="num">
                                <a class="rank" href="<?= escape($requestUrl) ?>" aria-label="Показать запросы к <?= escape(rawurldecode($row['request_path'])) ?>">
                                    <span class="bar" data-b="<?= countBucket((int) $row['hits'], $max) ?>" aria-hidden="true"></span>
                                    <span class="rank__count"><?= formatCount((int) $row['hits']) ?></span>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php elseif ($company !== false): ?>
        <?php
        $bots = $db->prepare('SELECT b.id, b.name, COUNT(r.id) AS hits FROM bots b
            JOIN ' . $requestSource . ' AS r ON r.bot_id = b.id WHERE b.company_id = ? AND r.site_id = ?
            AND b.disabled = 0
            GROUP BY b.id ORDER BY hits DESC, b.name');
        $bots->execute([$companyId, $siteId]);
        $rows = $bots->fetchAll();
        $max = $rows ? (int) max(array_column($rows, 'hits')) : 0;
        ?>
        <div class="scope">
            <h2><?= escape($scopeTitle) ?></h2>
        </div>
        <?php if (!$rows): ?>
            <div class="empty">
                <p>Боты этого поставщика не обращались к сайту за выбранный период.</p>
                <p>Расширьте период в фильтрах или <a href="<?= escape(reportUrl($siteParams)) ?>">вернитесь к поставщикам</a>.</p>
            </div>
        <?php else: ?>
            <p class="total"><strong><?= formatCount(array_sum(array_column($rows, 'hits'))) ?></strong> запросов от <?= formatCount(count($rows)) ?> ботов</p>
            <div class="ledger-wrap">
                <table class="ledger ledger--rank">
                    <thead>
                    <tr>
                        <th scope="col">Бот</th>
                        <th scope="col" class="num">Запросов</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><a href="<?= escape(reportUrl($siteParams + ['company' => $companyId, 'bot' => $row['id']])) ?>"><?= escape($row['name']) ?></a></td>
                            <td class="num">
                                <span class="bar" data-b="<?= countBucket((int) $row['hits'], $max) ?>" aria-hidden="true"></span>
                                <?= formatCount((int) $row['hits']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php elseif ($site !== false): ?>
        <?php
        $companies = $db->prepare('SELECT c.id, c.name, COUNT(r.id) AS hits FROM companies c
            JOIN bots b ON b.company_id = c.id JOIN ' . $requestSource . ' AS r ON r.bot_id = b.id
            WHERE r.site_id = ? AND c.disabled = 0 AND b.disabled = 0 GROUP BY c.id ORDER BY hits DESC, c.name');
        $companies->execute([$siteId]);
        $rows = $companies->fetchAll();
        $max = $rows ? (int) max(array_column($rows, 'hits')) : 0;
        ?>
        <div class="scope">
            <h2><?= escape($scopeTitle) ?></h2>
        </div>
        <?php if (!$rows): ?>
            <div class="empty">
                <p>За выбранный период ИИ-боты не обращались к этому сайту.</p>
                <p>Запустите сбор кнопкой «Обновить данные» или <a href="<?= escape(reportUrl()) ?>">выберите другой сайт</a>.</p>
            </div>
        <?php else: ?>
            <p class="total"><strong><?= formatCount(array_sum(array_column($rows, 'hits'))) ?></strong> запросов от <?= formatCount(count($rows)) ?> поставщиков</p>
            <div class="ledger-wrap">
                <table class="ledger ledger--rank">
                    <thead>
                    <tr>
                        <th scope="col">Поставщик</th>
                        <th scope="col" class="num">Запросов</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><a href="<?= escape(reportUrl($siteParams + ['company' => $row['id']])) ?>"><?= escape($row['name']) ?></a></td>
                            <td class="num">
                                <span class="bar" data-b="<?= countBucket((int) $row['hits'], $max) ?>" aria-hidden="true"></span>
                                <?= formatCount((int) $row['hits']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <?php
        $siteOrder = match ($siteSort) {
            'domain_desc' => 's.domain DESC',
            'hits_asc', 'type_' . $botTypeId . '_asc' => 'hits ASC, s.domain ASC',
            'hits_desc', 'type_' . $botTypeId . '_desc' => 'hits DESC, s.domain ASC',
            default => 's.domain ASC',
        };
        if ($botTypeId === 0) {
            $siteTypes = $db->query('SELECT s.id, s.domain, bt.bot_type_id, COUNT(r.id) AS type_hits FROM sites s
                LEFT JOIN ' . $requestSource . ' AS r ON r.site_id = s.id
                LEFT JOIN bots bt ON bt.id = r.bot_id
                WHERE s.archived = 0 OR r.id IS NOT NULL
                GROUP BY s.id, bt.bot_type_id ORDER BY s.domain')->fetchAll();
            $sitesById = [];
            foreach ($siteTypes as $row) {
                $siteId = (int) $row['id'];
                $sitesById[$siteId] ??= ['id' => $siteId, 'domain' => $row['domain'], 'hits' => 0, 'types' => []];
                $typeId = (int) ($row['bot_type_id'] ?? 0);
                $hits = (int) $row['type_hits'];
                if ($typeId > 0) {
                    $sitesById[$siteId]['types'][$typeId] = $hits;
                    $sitesById[$siteId]['hits'] += $hits;
                }
            }
            $sites = array_values($sitesById);
            preg_match('/^type_(\d+)_(asc|desc)$/D', $siteSort, $typeSort);
            usort($sites, static function (array $left, array $right) use ($siteSort, $typeSort): int {
                if ($typeSort) {
                    $leftHits = $left['types'][(int) $typeSort[1]] ?? 0;
                    $rightHits = $right['types'][(int) $typeSort[1]] ?? 0;
                    $comparison = $leftHits <=> $rightHits;
                    if ($typeSort[2] === 'desc') {
                        $comparison *= -1;
                    }
                    return $comparison ?: strcasecmp($left['domain'], $right['domain']);
                }
                return match ($siteSort) {
                    'domain_desc' => strcasecmp($right['domain'], $left['domain']),
                    'hits_asc' => ($left['hits'] <=> $right['hits']) ?: strcasecmp($left['domain'], $right['domain']),
                    'hits_desc' => ($right['hits'] <=> $left['hits']) ?: strcasecmp($left['domain'], $right['domain']),
                    default => strcasecmp($left['domain'], $right['domain']),
                };
            });
            $max = $sites ? (int) max(array_column($sites, 'hits')) : 0;
            $typeMaxima = [0 => $max];
            foreach ($botTypes as $type) {
                $typeId = (int) $type['id'];
                $typeMaxima[$typeId] = 0;
                foreach ($sites as $row) {
                    $typeMaxima[$typeId] = max($typeMaxima[$typeId], $row['types'][$typeId] ?? 0);
                }
            }
        } else {
            $sites = $db->query('SELECT s.id, s.domain, COUNT(r.id) AS hits FROM sites s
                LEFT JOIN ' . $requestSource . ' AS r ON r.site_id = s.id WHERE s.archived = 0 OR r.id IS NOT NULL GROUP BY s.id ORDER BY ' . $siteOrder)->fetchAll();
            $max = $sites ? (int) max(array_column($sites, 'hits')) : 0;
        }
        ?>
        <div class="scope">
            <h2><?= escape($scopeTitle) ?></h2>
        </div>
        <?php if (!$sites): ?>
            <div class="empty">
                <p>Сайтов пока нет. Добавьте домен и путь к access-логу в настройках — сбор начнётся после первого запуска.</p>
                <?php if ($isAdmin ?? false): ?><p><a href="/?view=settings">Перейти в настройки</a></p><?php endif; ?>
            </div>
        <?php else: ?>
            <p class="total"><strong><?= formatCount(array_sum(array_column($sites, 'hits'))) ?></strong> запросов по <?= formatCount(count($sites)) ?> сайтам</p>
            <div class="ledger-wrap">
                <table class="ledger<?= $botTypeId === 0 ? ' ledger--types' : ' ledger--rank' ?>">
                    <thead>
                    <tr>
                        <?php renderReportSortHeading('Сайт', 'domain'); ?>
                        <?php if ($botTypeId === 0): ?>
                            <?php renderReportSortHeading('Все виды', 'hits', true); ?>
                            <?php foreach ($botTypes as $type): ?>
                                <?php renderReportSortHeading($type['name'], 'type_' . (int) $type['id'], true); ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php renderReportSortHeading('Запросов', 'hits', true); ?>
                        <?php endif; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sites as $row): ?>
                        <tr>
                            <td><a href="<?= escape(reportUrl(['site' => $row['id']])) ?>"><?= escape($row['domain']) ?></a></td>
                            <?php if ($botTypeId === 0): ?>
                                <td class="num">
                                    <?php if ($row['hits'] > 0): ?>
                                        <a class="report-count" href="<?= escape(reportUrl(['site' => $row['id'], 'bot_type' => ''])) ?>">
                                            <span class="bar" data-b="<?= countBucket($row['hits'], $typeMaxima[0]) ?>" aria-hidden="true"></span>
                                            <span class="report-count__value"><?= formatCount($row['hits']) ?></span>
                                        </a>
                                    <?php else: ?>
                                        <?= formatCount($row['hits']) ?>
                                    <?php endif; ?>
                                </td>
                                <?php foreach ($botTypes as $type): ?>
                                    <?php $typeHits = $row['types'][(int) $type['id']] ?? 0; ?>
                                    <td class="num">
                                        <?php if ($typeHits > 0): ?>
                                            <a class="report-count" href="<?= escape(reportUrl(['site' => $row['id'], 'bot_type' => (int) $type['id']])) ?>">
                                                <span class="bar" data-b="<?= countBucket($typeHits, $typeMaxima[(int) $type['id']]) ?>" aria-hidden="true"></span>
                                                <span class="report-count__value"><?= formatCount($typeHits) ?></span>
                                            </a>
                                        <?php else: ?>
                                            <?= formatCount($typeHits) ?>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <td class="num">
                                    <?php if ((int) $row['hits'] > 0): ?><span class="bar" data-b="<?= countBucket((int) $row['hits'], $max) ?>" aria-hidden="true"></span><?php endif; ?>
                                    <?= formatCount((int) $row['hits']) ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
