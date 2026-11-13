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

$botId = filter_var($_GET['bot'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$companyId = filter_var($_GET['company'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$newCompany = ($_GET['new_company'] ?? '') === '1';
$newBot = ($_GET['new_bot'] ?? '') === '1';

$companies = $db->query('SELECT c.id, c.name, c.disabled, COUNT(b.id) AS bot_count
    FROM companies c
    LEFT JOIN bots b ON b.company_id = c.id AND b.archived = 0
    GROUP BY c.id
    ORDER BY c.name')->fetchAll();

$company = false;
if ($companyId) {
    $lookup = $db->prepare('SELECT id, name, description, disabled FROM companies WHERE id = ?');
    $lookup->execute([$companyId]);
    $company = $lookup->fetch();
}

if ($botId || $newBot) {
    $bot = array_fill_keys(['name', 'description', 'website', 'note', 'ip_source'], '');
    $bot['disabled'] = 0;
    $bot['bot_type_id'] = (int) $db->query("SELECT id FROM bot_types WHERE name = 'Прочее'")->fetchColumn();
    $botTypes = $db->query('SELECT id, name FROM bot_types ORDER BY id')->fetchAll();
    $entries = [];
    $signatures = [];
    if ($botId) {
        $lookup = $db->prepare('SELECT b.*, c.name AS company_name, c.disabled AS company_disabled
            FROM bots b JOIN companies c ON c.id = b.company_id
            WHERE b.id = ? AND b.archived = 0');
        $lookup->execute([$botId]);
        $bot = $lookup->fetch();
        if ($bot) {
            $company = ['id' => (int) $bot['company_id'], 'name' => $bot['company_name'], 'disabled' => (int) $bot['company_disabled']];
            $ranges = $db->prepare('SELECT entry FROM bot_ip_ranges WHERE bot_id = ? ORDER BY entry');
            $ranges->execute([$botId]);
            $entries = $ranges->fetchAll(PDO::FETCH_COLUMN);
            $signatureQuery = $db->prepare('SELECT pattern, enabled FROM bot_signatures WHERE bot_id = ? ORDER BY pattern COLLATE NOCASE');
            $signatureQuery->execute([$botId]);
            $signatures = $signatureQuery->fetchAll();
        }
    } elseif (!$company) {
        $bot = false;
    }
    $canRefreshIpSource = $bot && !$newBot
        && preg_match('~\.json$~i', (string) (parse_url($bot['ip_source'] ?? '', PHP_URL_PATH) ?: '')) === 1;
    $ipUpdatedAt = !empty($bot['ip_updated_at'])
        ? DateTimeImmutable::createFromFormat('!Y-m-d', $bot['ip_updated_at'])
        : false;
    ?>
    <?php if ($bot): ?>
        <nav class="crumbs crumbs--lead" aria-label="Путь">
            <ol>
                <li><a href="/?view=settings&amp;tab=bots">Боты</a></li>
                <li><a class="<?= !empty($company['disabled']) ? 'link--disabled' : '' ?>" href="/?view=settings&amp;tab=bots&amp;company=<?= (int) $company['id'] ?>"><?= escape($company['name']) ?></a></li>
                <li><span aria-current="page"><?= $newBot ? 'Новый бот' : escape($bot['name']) ?></span></li>
            </ol>
        </nav>
        <div class="section">
            <h2><?= $newBot ? 'Новый бот' : escape($bot['name']) ?> <span><?= escape($company['name']) ?></span></h2>
            <form method="post" class="stack">
                <input type="hidden" name="csrf" value="<?= escape($token) ?>">
                <?php if ($newBot): ?>
                    <input type="hidden" name="new_bot" value="1">
                    <input type="hidden" name="company_id" value="<?= (int) ($company['id'] ?? 0) ?>">
                    <div class="grid-2">
                        <label class="field">
                            <span>Наименование</span>
                            <input name="name" required autocomplete="off" maxlength="400">
                        </label>
                        <label class="field">
                            <span>Сигнатура User-Agent</span>
                            <input name="signature" placeholder="GPTBot" autocomplete="off" spellcheck="false" maxlength="400" class="mono">
                            <small>Оставьте пустым — подставится наименование.</small>
                        </label>
                    </div>
                    <p>Компания: <strong><?= escape($company['name']) ?></strong></p>
                <?php else: ?>
                    <input type="hidden" name="bot_id" value="<?= (int) $botId ?>">
                <?php endif; ?>
                <div class="fieldset">
                    <div class="stack">
                        <label class="field">
                            <span>Вид бота</span>
                            <select name="bot_type_id" required>
                                <?php foreach ($botTypes as $type): ?>
                                    <option value="<?= (int) $type['id'] ?>"<?= (int) $bot['bot_type_id'] === (int) $type['id'] ? ' selected' : '' ?>><?= escape($type['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="field">
                            <span>Описание</span>
                            <textarea name="description" rows="3" spellcheck="false"><?= escape($bot['description']) ?></textarea>
                        </label>
                        <div class="grid-2">
                            <label class="field">
                                <span>Сайт</span>
                                <input name="website" type="url" value="<?= escape($bot['website']) ?>" placeholder="https://example.com" autocomplete="off" spellcheck="false">
                            </label>
                            <label class="field">
                                <span>Источник IP-адресов</span>
                                <input name="ip_source" type="url" value="<?= escape($bot['ip_source']) ?>" placeholder="https://example.com/ips.json" autocomplete="off" spellcheck="false">
                            </label>
                        </div>
                        <label class="field">
                            <span>Примечание</span>
                            <textarea name="note" rows="2"><?= escape($bot['note']) ?></textarea>
                        </label>
                    </div>
                </div>
                <?php if (!$newBot): ?>
                    <fieldset class="fieldset">
                        <legend>Сигнатуры User-Agent</legend>
                        <label class="field">
                            <span>Одна сигнатура на строку</span>
                            <textarea name="signatures" rows="4" maxlength="20000" spellcheck="false" class="mono"><?= escape(implode("\n", array_column($signatures, 'pattern'))) ?></textarea>
                            <small>Удалите строку, чтобы убрать сигнатуру. Неизменённые сигнатуры сохраняют свой статус; новые и изменённые будут активны.</small>
                        </label>
                        <?php if ($signatures): ?>
                            <ul class="stack">
                                <?php foreach ($signatures as $signature): ?>
                                    <li><code class="mono"><?= escape($signature['pattern']) ?></code> — <?= (int) $signature['enabled'] ? 'Активна' : 'Отключена' ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p>Сигнатуры не заданы.</p>
                        <?php endif; ?>
                    </fieldset>
                <?php endif; ?>
                <fieldset class="fieldset">
                    <legend>IP и диапазоны</legend>
                    <label class="field">
                        <span>Список адресов</span>
                        <textarea name="ip_ranges" rows="10" maxlength="20000" spellcheck="false" autocapitalize="off" class="mono"><?= escape(implode("\n", $entries)) ?></textarea>
                        <small>По одной записи на строку: <span class="mono">203.0.113.7</span>, <span class="mono">203.0.113.0/24</span> или диапазон целиком.</small>
                    </label>
                    <label class="field">
                        <span>Дата актуальности ip-адресов</span>
                        <input type="text" value="<?= escape($ipUpdatedAt instanceof DateTimeImmutable ? $ipUpdatedAt->format('d.m.Y') : '—') ?>" readonly>
                    </label>
                    <?php if ($canRefreshIpSource): ?>
                        <button class="btn" name="action" value="refresh_bot_ips" data-confirm="Обновить список IP-адресов из указанного JSON-источника?">Обновить ip адреса</button>
                    <?php endif; ?>
                </fieldset>
                <label class="check"><input type="checkbox" name="disabled" value="1"<?= !empty($bot['disabled']) ? ' checked' : '' ?>> Disabled</label>
                <div class="form-actions">
                    <button class="btn btn--primary" name="action" value="bot_ips">Сохранить бота</button>
                    <a class="btn btn--quiet" href="/?view=settings&amp;tab=bots&amp;company=<?= (int) $company['id'] ?>">К ботам компании</a>
                </div>
            </form>
            <?php if (!$newBot): ?>
                <form method="post" class="form-actions" data-confirm="Удалить бота из мониторинга? История запросов сохранится.">
                    <input type="hidden" name="csrf" value="<?= escape($token) ?>">
                    <input type="hidden" name="id" value="<?= (int) $botId ?>">
                    <button class="btn btn--danger" name="action" value="delete_bot">Удалить бота</button>
                </form>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="empty">
            <p><?= $newBot ? 'Компания не найдена — откройте форму добавления бота из списка компании.' : 'Бот не найден: возможно, его уже удалили.' ?></p>
            <p><a href="/?view=settings&amp;tab=bots">Вернуться к списку компаний</a></p>
        </div>
    <?php endif; ?>
<?php } elseif ($companyId || $newCompany) { ?>
    <?php if ($newCompany): ?>
        <nav class="crumbs crumbs--lead" aria-label="Путь">
            <ol>
                <li><a href="/?view=settings&amp;tab=bots">Боты</a></li>
                <li><span aria-current="page">Новая компания</span></li>
            </ol>
        </nav>
        <div class="section">
            <h2>Новая компания</h2>
            <form method="post" class="stack">
                <input type="hidden" name="csrf" value="<?= escape($token) ?>">
                <label class="field"><span>Наименование компании</span><input name="name" required autocomplete="off" maxlength="400"></label>
                <label class="field"><span>Описание</span><textarea name="description" rows="4" maxlength="4000"></textarea></label>
                <div class="form-actions">
                    <button class="btn btn--primary" name="action" value="add_company">Добавить компанию</button>
                    <a class="btn btn--quiet" href="/?view=settings&amp;tab=bots">Отмена</a>
                </div>
            </form>
        </div>
    <?php elseif ($company): ?>
        <?php
        $list = $db->prepare('SELECT id, name, disabled FROM bots WHERE company_id = ? AND archived = 0 ORDER BY name');
        $list->execute([$companyId]);
        $bots = $list->fetchAll();
        ?>
        <nav class="crumbs crumbs--lead" aria-label="Путь">
            <ol>
                <li><a href="/?view=settings&amp;tab=bots">Боты</a></li>
                <li><span aria-current="page"><?= escape($company['name']) ?></span></li>
            </ol>
        </nav>
        <div class="section">
            <h2><?= escape($company['name']) ?> <span>(<?= formatCount(count($bots)) ?>)</span></h2>
            <form method="post" class="stack">
                <input type="hidden" name="csrf" value="<?= escape($token) ?>">
                <input type="hidden" name="company_id" value="<?= (int) $company['id'] ?>">
                <label class="field"><span>Наименование компании</span><input name="name" value="<?= escape($company['name']) ?>" required maxlength="400"></label>
                <label class="field"><span>Описание</span><textarea name="description" rows="4" maxlength="4000"><?= escape($company['description']) ?></textarea></label>
                <label class="check"><input type="checkbox" name="disabled" value="1"<?= !empty($company['disabled']) ? ' checked' : '' ?>> Disabled</label>
                <div class="form-actions"><button class="btn btn--primary" name="action" value="save_company">Сохранить</button></div>
            </form>
        </div>
        <div class="section">
            <h2>Боты компании <span>(<?= formatCount(count($bots)) ?>)</span></h2>
            <?php if ($bots): ?>
                <ul class="rows">
                    <?php foreach ($bots as $row): ?>
                        <li>
                            <span class="row-main">
                                <a class="<?= !empty($row['disabled']) ? 'link--disabled' : '' ?>" href="/?view=settings&amp;tab=bots&amp;bot=<?= (int) $row['id'] ?>"><?= escape($row['name']) ?></a>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="empty">
                    <p>У компании пока нет ботов. Добавьте первого бота — он появится в отчётах после сбора логов.</p>
                </div>
            <?php endif; ?>
            <p class="form-actions">
                <a class="btn btn--primary" href="/?view=settings&amp;tab=bots&amp;company=<?= (int) $company['id'] ?>&amp;new_bot=1">Добавить бота</a>
            </p>
        </div>
    <?php else: ?>
        <div class="empty">
            <p>Компания не найдена: возможно, её уже удалили.</p>
            <p><a href="/?view=settings&amp;tab=bots">Вернуться к списку компаний</a></p>
        </div>
    <?php endif; ?>
<?php } else { ?>
    <div class="section">
        <h2>Компании <span>(<?= formatCount(count($companies)) ?>)</span></h2>
        <p>Бот определяется по сигнатуре User-Agent. Компания объединяет ботов одного поставщика ИИ. Включённые боты выделены жирным шрифтом.</p>
        <?php if ($companies): ?>
            <ul class="rows">
                <?php foreach ($companies as $row): ?>
                    <?php $count = (int) $row['bot_count']; ?>
                    <li>
                        <span class="row-main">
                            <a class="<?= !empty($row['disabled']) ? 'link--disabled' : '' ?>" href="/?view=settings&amp;tab=bots&amp;company=<?= (int) $row['id'] ?>"><?= escape($row['name']) ?> (<?= formatCount($count) ?>)</a>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <div class="empty">
            <p>Компаний пока нет. Добавьте поставщика ИИ, чтобы завести его ботов.</p>
            </div>
        <?php endif; ?>
        <div class="group">
            <a class="btn btn--primary" href="/?view=settings&amp;tab=bots&amp;new_company=1">Добавить компанию</a>
        </div>
    </div>
<?php } ?>
