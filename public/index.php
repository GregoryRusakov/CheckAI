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
require dirname(__DIR__) . '/src/digest.php';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('X-Content-Type-Options: nosniff');

session_start([
    'use_strict_mode' => true,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);

$db = openDatabase();
$isValidSiteSort = static function (mixed $value) use ($db): bool {
    if (!is_string($value)) {
        return false;
    }
    if (in_array($value, ['domain_asc', 'domain_desc', 'hits_asc', 'hits_desc'], true)) {
        return true;
    }
    if (!preg_match('/^type_(\d+)_(?:asc|desc)$/D', $value, $matches)) {
        return false;
    }
    $lookup = $db->prepare('SELECT 1 FROM bot_types WHERE id = ?');
    $lookup->execute([(int) $matches[1]]);
    return (bool) $lookup->fetchColumn();
};
$lastCollectionUtc = $db->query('SELECT MAX(updated_at_utc) FROM log_cursors')->fetchColumn();
$lastCollectionAt = is_string($lastCollectionUtc)
    ? DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', $lastCollectionUtc, new DateTimeZone('UTC'))
    : false;
if ($lastCollectionAt !== false) {
    $reportTimezone = new DateTimeZone((string) $db->query('SELECT report_timezone FROM settings WHERE id = 1')->fetchColumn());
    $lastCollectionAt = $lastCollectionAt->setTimezone($reportTimezone);
} else {
    $lastCollectionAt = null;
}
$token = $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$error = '';
$passwordSetupPage = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/set-password';
$settingsPage = ($_GET['view'] ?? '') === 'settings';
$ratingPage = ($_GET['view'] ?? '') === 'rating';
$settingsTab = in_array($_GET['tab'] ?? '', ['bots', 'bot-types', 'users', 'collection', 'digests'], true) ? $_GET['tab'] : 'sites';
if (isset($_GET['company']) || isset($_GET['bot']) || isset($_GET['new_bot']) || isset($_GET['new_company'])) {
    $settingsTab = 'bots';
}
$settingsDetail = isset($_GET['edit_site']) || isset($_GET['company'])
    || isset($_GET['bot']) || isset($_GET['new_bot']) || isset($_GET['new_company']) || isset($_GET['edit_user']);
$notice = $_SESSION['notice'] ?? '';
unset($_SESSION['notice']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf'] ?? null;
    if (!is_string($submittedToken) || !hash_equals($token, $submittedToken)) {
        http_response_code(403);
        exit('Запрос отклонён. Обновите страницу.');
    }

    if (($_POST['action'] ?? '') === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: /', true, 303);
        exit;
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'set_password') {
        $password = $_POST['new_password'] ?? null;
        $confirmation = $_POST['confirm_password'] ?? null;
        $pendingId = (int) ($_SESSION['password_setup_user_id'] ?? 0);
        $account = $db->prepare('SELECT id FROM users WHERE id = ? AND enabled = 1 AND disabled = 0 AND needs_password = 1');
        $account->execute([$pendingId]);
        if ($pendingId > 0 && $account->fetchColumn() && is_string($password) && $password === $confirmation
            && preg_match('/^.{8,}$/us', $password) && strlen($password) <= 72 && !str_contains($password, "\0")) {
            $update = $db->prepare('UPDATE users SET password_hash = ?, needs_password = 0 WHERE id = ? AND enabled = 1 AND disabled = 0 AND needs_password = 1');
            $update->execute([password_hash($password, PASSWORD_DEFAULT), $pendingId]);
            session_regenerate_id(true);
            $_SESSION = ['user_id' => $pendingId];
            header('Location: /', true, 303);
            exit;
        }
        http_response_code(400);
        $error = 'Пароль должен содержать не менее 8 символов, а значения должны совпадать.';
    }
    if (in_array($action, ['create_user', 'save_user_settings', 'reset_user_password', 'save_user_disabled'], true)) {
        $role = $db->prepare('SELECT role FROM users WHERE id = ? AND enabled = 1 AND disabled = 0');
        $role->execute([$_SESSION['user_id'] ?? 0]);
        if ($role->fetchColumn() !== 'admin') {
            http_response_code(403);
            exit('Действие доступно администратору.');
        }
        try {
            if ($action === 'create_user') {
                $login = is_string($_POST['new_login'] ?? null) ? trim($_POST['new_login']) : '';
                $newRole = $_POST['role'] ?? '';
                if (!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/D', $login) || !in_array($newRole, ['admin', 'user'], true)) {
                    throw new InvalidArgumentException('Проверьте логин и тип пользователя.');
                }
                $create = $db->prepare("INSERT INTO users (login, password_hash, role, enabled, created_at_utc, needs_password) VALUES (?, '', ?, 1, ?, 1)");
                $create->execute([$login, $newRole, gmdate('Y-m-d\\TH:i:s\\Z')]);
                $_SESSION['notice'] = 'Пользователь создан. При первом входе он задаст пароль.';
            } elseif ($action === 'save_user_settings') {
                $id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $login = is_string($_POST['login'] ?? null) ? trim($_POST['login']) : '';
                $newRole = $_POST['role'] ?? '';
                if (!$id || !preg_match('/^[a-zA-Z0-9_.-]{3,64}$/D', $login) || !in_array($newRole, ['admin', 'user'], true)) {
                    throw new InvalidArgumentException('Проверьте логин и тип пользователя.');
                }

                $db->exec('BEGIN IMMEDIATE');
                try {
                    $lookup = $db->prepare('SELECT role, disabled FROM users WHERE id = ? AND enabled = 1');
                    $lookup->execute([$id]);
                    $target = $lookup->fetch();
                    if (!$target) {
                        throw new InvalidArgumentException('Пользователь не найден.');
                    }
                    $disabled = ($_POST['disabled'] ?? '') === '1' ? 1 : 0;
                    if ($target['role'] === 'admin' && (int) $target['disabled'] === 0
                        && ($newRole !== 'admin' || $disabled === 1)) {
                        $activeAdmins = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND enabled = 1 AND disabled = 0")->fetchColumn();
                        if ($activeAdmins <= 1) {
                            throw new InvalidArgumentException('Нельзя отключить или изменить тип единственного активного администратора.');
                        }
                    }
                    $save = $db->prepare('UPDATE users SET login = ?, role = ?, disabled = ? WHERE id = ? AND enabled = 1');
                    $save->execute([$login, $newRole, $disabled, $id]);
                    $db->exec('COMMIT');
                } catch (Throwable $error) {
                    $db->exec('ROLLBACK');
                    throw $error;
                }
                $_SESSION['notice'] = 'Настройки пользователя сохранены.';
            } elseif ($action === 'reset_user_password') {
                $id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$id || (int) $id === (int) ($_SESSION['user_id'] ?? 0)) {
                    throw new InvalidArgumentException('Нельзя сбросить пароль текущей учётной записи.');
                }
                $reset = $db->prepare('UPDATE users SET password_hash = \'\', needs_password = 1 WHERE id = ? AND enabled = 1');
                $reset->execute([$id]);
                $_SESSION['notice'] = $reset->rowCount() ? 'Пароль сброшен. Пользователь задаст новый при следующем входе.' : 'Пользователь не найден.';
            } else {
                $id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$id) {
                    throw new InvalidArgumentException('Пользователь не найден.');
                }
                $disabled = ($_POST['disabled'] ?? '') === '1' ? 1 : 0;
                $db->exec('BEGIN IMMEDIATE');
                try {
                    $lookup = $db->prepare('SELECT role, disabled FROM users WHERE id = ? AND enabled = 1');
                    $lookup->execute([$id]);
                    $target = $lookup->fetch();
                    if (!$target) {
                        throw new InvalidArgumentException('Пользователь не найден.');
                    }
                    if ($disabled === 1 && $target['role'] === 'admin' && (int) $target['disabled'] === 0) {
                        $activeAdmins = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND enabled = 1 AND disabled = 0")->fetchColumn();
                        if ($activeAdmins <= 1) {
                            throw new InvalidArgumentException('Нельзя отключить единственного активного администратора.');
                        }
                    }
                    $save = $db->prepare('UPDATE users SET disabled = ? WHERE id = ? AND enabled = 1');
                    $save->execute([$disabled, $id]);
                    $db->exec('COMMIT');
                } catch (Throwable $error) {
                    $db->exec('ROLLBACK');
                    throw $error;
                }
                $_SESSION['notice'] = $disabled === 1 ? 'Пользователь отключён.' : 'Пользователь включён.';
            }
        } catch (PDOException $error) {
            $_SESSION['notice'] = $error->getCode() === '23000' ? 'Такой логин уже занят.' : 'Не удалось выполнить действие.';
        } catch (InvalidArgumentException $error) {
            $_SESSION['notice'] = $error->getMessage();
        }
        header('Location: /?view=settings&tab=users', true, 303);
        exit;
    }
    if ($action === 'report_filters') {
        $filters = [];
        foreach (['verified', 'pages', 'ok'] as $key) {
            if (($_POST[$key] ?? null) === '1') {
                $filters[$key] = '1';
            }
        }
        $siteSort = $_POST['site_sort'] ?? 'domain_asc';
        $filters['site_sort'] = $isValidSiteSort($siteSort)
            ? $siteSort
            : 'domain_asc';
        $periodFrom = $_POST['from'] ?? null;
        $periodTo = $_POST['to'] ?? null;
        $isValidDate = static function (mixed $value): bool {
            if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
                return false;
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            return $date !== false && $date->format('Y-m-d') === $value;
        };
        if ($isValidDate($periodFrom) && $isValidDate($periodTo)) {
            if ($periodFrom > $periodTo) {
                [$periodFrom, $periodTo] = [$periodTo, $periodFrom];
            }
            $filters['period_from'] = $periodFrom;
            $filters['period_to'] = $periodTo;
        }
        $botTypeId = filter_var($_POST['bot_type'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($botTypeId) {
            $typeLookup = $db->prepare('SELECT 1 FROM bot_types WHERE id = ?');
            $typeLookup->execute([$botTypeId]);
            if ($typeLookup->fetchColumn()) {
                $filters['bot_type'] = (string) $botTypeId;
            }
        }
        $save = $db->prepare('UPDATE users SET report_filters = ? WHERE id = ? AND enabled = 1');
        $save->execute([json_encode($filters), $_SESSION['user_id'] ?? 0]);
        $params = array_filter(
            array_intersect_key($_POST, array_flip(['view', 'site', 'company', 'bot', 'bot_type', 'site_sort', 'path', 'from', 'to', 'verified', 'pages', 'ok', 'filters_set'])),
            'is_string'
        );
        header('Location: /?' . http_build_query($params) . ((($params['view'] ?? '') === 'rating') ? '#rating' : '#reports'), true, 303);
        exit;
    }
    if (in_array($action, ['collect', 'save_collection_settings', 'daily_digest', 'save_digest_settings', 'generate_daily_digest', 'edit_digest_directory', 'edit_log_root', 'add_site', 'edit_site', 'bot_ips', 'refresh_bot_ips', 'add_company', 'save_company', 'delete_site', 'delete_bot', 'add_bot_type', 'save_bot_type'], true)) {
        $role = $db->prepare('SELECT role FROM users WHERE id = ? AND enabled = 1');
        $role->execute([$_SESSION['user_id'] ?? 0]);
        if ($role->fetchColumn() !== 'admin') {
            http_response_code(403);
            exit('Действие доступно администратору.');
        }

        try {
            if ($action === 'collect') {
                $result = collectLogs($db);
                $_SESSION['notice'] = match (true) {
                    $result['busy'] => 'Сбор уже выполняется.',
                    $result['sites'] === 0 => 'Добавьте сайт, чтобы начать сбор.',
                    default => "Новых запросов: {$result['added']}. Ошибок чтения: {$result['errors']}.",
                };
            } elseif ($action === 'save_collection_settings') {
                $input = is_string($_POST['log_root'] ?? null) ? trim($_POST['log_root']) : '';
                $root = $input !== '' ? realpath($input) : false;
                if (strlen($input) > 4096 || $root === false || !is_dir($root) || !is_readable($root)) {
                    throw new InvalidArgumentException('Укажите существующий и доступный каталог логов.');
                }
                $update = $db->prepare('UPDATE settings SET log_root = ?, auto_collect = ? WHERE id = 1');
                $update->execute([$root, ($_POST['enabled'] ?? '') === '1' ? 1 : 0]);
                $_SESSION['notice'] = 'Настройки сбора данных сохранены.';
            } elseif ($action === 'daily_digest') {
                $update = $db->prepare('UPDATE settings SET daily_digest = ? WHERE id = 1');
                $update->execute([($_POST['enabled'] ?? '') === '1' ? 1 : 0]);
                $_SESSION['notice'] = 'Настройка ежедневного дайджеста сохранена.';
            } elseif ($action === 'save_digest_settings') {
                $input = is_string($_POST['digest_directory'] ?? null) ? trim($_POST['digest_directory']) : '';
                $directory = $input !== '' ? realpath($input) : false;
                $isAbsolute = str_starts_with($input, '/')
                    || preg_match('/^[A-Za-z]:[\\\\\/]/', $input) === 1
                    || str_starts_with($input, '\\\\');
                if (strlen($input) > 4096 || !$isAbsolute || $directory === false || !is_dir($directory) || !is_writable($directory)) {
                    throw new InvalidArgumentException('Укажите существующий каталог дайджестов, доступный для записи пользователю PHP.');
                }
                $update = $db->prepare('UPDATE settings SET digest_directory = ?, daily_digest = ? WHERE id = 1');
                $update->execute([$directory, ($_POST['enabled'] ?? '') === '1' ? 1 : 0]);
                $_SESSION['notice'] = 'Настройки дайджестов сохранены.';
            } elseif ($action === 'generate_daily_digest') {
                $result = generateDailyDigest($db);
                $_SESSION['notice'] = 'Дайджест за ' . $result['date'] . ' создан: ' . $result['path'];
            } elseif ($action === 'edit_digest_directory') {
                $input = is_string($_POST['digest_directory'] ?? null) ? trim($_POST['digest_directory']) : '';
                $directory = $input !== '' ? realpath($input) : false;
                $isAbsolute = str_starts_with($input, '/')
                    || preg_match('/^[A-Za-z]:[\\\\\/]/', $input) === 1
                    || str_starts_with($input, '\\\\');
                if (strlen($input) > 4096 || !$isAbsolute || $directory === false || !is_dir($directory) || !is_writable($directory)) {
                    throw new InvalidArgumentException('Укажите существующий каталог дайджестов, доступный для записи пользователю PHP.');
                }
                $update = $db->prepare('UPDATE settings SET digest_directory = ? WHERE id = 1');
                $update->execute([$directory]);
                $_SESSION['notice'] = 'Каталог дайджестов сохранён.';
            } elseif ($action === 'add_bot_type') {
                $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
                if ($name === '' || strlen($name) > 200) {
                    throw new InvalidArgumentException('Укажите название вида бота (до 200 символов).');
                }
                $add = $db->prepare('INSERT INTO bot_types (name) VALUES (?) ON CONFLICT (name) DO NOTHING');
                $add->execute([$name]);
                $_SESSION['notice'] = $add->rowCount() ? 'Вид бота добавлен.' : 'Такой вид бота уже существует.';
            } elseif ($action === 'save_bot_type') {
                $botTypeId = filter_var($_POST['bot_type_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
                if (!$botTypeId || $name === '' || strlen($name) > 200) {
                    throw new InvalidArgumentException('Укажите название вида бота (до 200 символов).');
                }
                $existing = $db->prepare('SELECT 1 FROM bot_types WHERE id = ?');
                $existing->execute([$botTypeId]);
                if (!$existing->fetchColumn()) {
                    throw new InvalidArgumentException('Вид бота не найден.');
                }
                $duplicate = $db->prepare('SELECT 1 FROM bot_types WHERE name = ? AND id <> ?');
                $duplicate->execute([$name, $botTypeId]);
                if ($duplicate->fetchColumn()) {
                    throw new InvalidArgumentException('Вид бота с таким названием уже существует.');
                }
                $save = $db->prepare('UPDATE bot_types SET name = ? WHERE id = ?');
                $save->execute([$name, $botTypeId]);
                $_SESSION['notice'] = 'Название вида бота сохранено.';
            } elseif ($action === 'delete_site' || $action === 'delete_bot') {
                $table = $action === 'delete_site' ? 'sites' : 'bots';
                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $delete = $db->prepare("UPDATE $table SET archived = 1, enabled = 0 WHERE id = ? AND archived = 0");
                $delete->execute([$id ?: 0]);
                $_SESSION['notice'] = $delete->rowCount() ? 'Запись удалена из настроек. История запросов сохранена.' : 'Запись не найдена.';
            } elseif ($action === 'add_company') {
                $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
                $description = is_string($_POST['description'] ?? null) ? trim($_POST['description']) : '';
                if ($name === '' || strlen($name) > 400 || strlen($description) > 4000) {
                    throw new InvalidArgumentException('Проверьте название и описание компании.');
                }
                $add = $db->prepare('INSERT INTO companies (name, description) VALUES (?, ?) ON CONFLICT (name) DO NOTHING');
                $add->execute([$name, $description]);
                $createdCompanyId = $add->rowCount() ? (int) $db->lastInsertId() : 0;
                $_SESSION['notice'] = $createdCompanyId ? 'Компания добавлена.' : 'Такая компания уже существует.';
            } elseif ($action === 'save_company') {
                $companyId = filter_var($_POST['company_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
                $description = is_string($_POST['description'] ?? null) ? trim($_POST['description']) : '';
                if (!$companyId || $name === '' || strlen($name) > 400 || strlen($description) > 4000) {
                    throw new InvalidArgumentException('Проверьте название и описание компании.');
                }
                $duplicate = $db->prepare('SELECT 1 FROM companies WHERE name = ? AND id <> ?');
                $duplicate->execute([$name, $companyId]);
                if ($duplicate->fetchColumn()) {
                    throw new InvalidArgumentException('Компания с таким названием уже существует.');
                }
                $save = $db->prepare('UPDATE companies SET name = ?, description = ?, disabled = ? WHERE id = ?');
                $save->execute([$name, $description, ($_POST['disabled'] ?? '') === '1' ? 1 : 0, $companyId]);
                $_SESSION['notice'] = 'Карточка компании сохранена.';
            } elseif ($action === 'refresh_bot_ips') {
                $botId = filter_var($_POST['bot_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$botId) {
                    throw new InvalidArgumentException('Бот не найден.');
                }
                $source = $db->prepare('SELECT ip_source FROM bots WHERE id = ? AND archived = 0');
                $source->execute([$botId]);
                $ipSource = $source->fetchColumn();
                if (!is_string($ipSource) || !preg_match('~\.json(?:\?.*)?$~i', parse_url($ipSource, PHP_URL_PATH) ?: '')) {
                    throw new InvalidArgumentException('Укажите JSON-источник IP-адресов в карточке бота и сохраните его.');
                }
                $entries = fetchIpRangesFromJson($ipSource);
                $timezone = new DateTimeZone($db->query('SELECT report_timezone FROM settings WHERE id = 1')->fetchColumn());
                $updatedAt = (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
                $db->beginTransaction();
                try {
                    $db->prepare('DELETE FROM bot_ip_ranges WHERE bot_id = ?')->execute([$botId]);
                    $insert = $db->prepare('INSERT INTO bot_ip_ranges (bot_id, entry, first_ip, last_ip) VALUES (?, ?, ?, ?)');
                    foreach ($entries as $entry) {
                        [$first, $last] = ipRange($entry);
                        $insert->execute([$botId, $entry, $first, $last]);
                    }
                    $db->prepare('UPDATE bots SET ip_updated_at = ? WHERE id = ? AND archived = 0')->execute([$updatedAt, $botId]);
                    $db->commit();
                } finally {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                }
                $_SESSION['notice'] = 'Список IP-адресов обновлён.';
            } elseif ($action === 'bot_ips') {
                $botId = filter_var($_POST['bot_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $input = $_POST['ip_ranges'] ?? null;
                $signatureInput = $_POST['signatures'] ?? null;
                $newBot = ($_POST['new_bot'] ?? '') === '1';
                $companyId = filter_var($_POST['company_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ((!$botId && !$newBot) || !is_string($input) || strlen($input) > 20000) {
                    throw new InvalidArgumentException('Проверьте список IP-адресов.');
                }
                $signaturePatterns = [];
                if (!$newBot) {
                    if (!is_string($signatureInput) || strlen($signatureInput) > 20000) {
                        throw new InvalidArgumentException('Проверьте список сигнатур User-Agent.');
                    }
                    foreach (preg_split('/\R/', $signatureInput) as $line) {
                        $pattern = trim($line);
                        if ($pattern === '') {
                            continue;
                        }
                        if (strlen($pattern) > 400) {
                            throw new InvalidArgumentException('Длина сигнатуры User-Agent не должна превышать 400 символов.');
                        }
                        $key = strtolower($pattern);
                        if (isset($signaturePatterns[$key])) {
                            throw new InvalidArgumentException('В списке повторяется сигнатура User-Agent: ' . $pattern);
                        }
                        $signaturePatterns[$key] = $pattern;
                    }
                    if (!$signaturePatterns) {
                        throw new InvalidArgumentException('У бота должна быть хотя бы одна сигнатура User-Agent.');
                    }
                }
                $fields = [];
                foreach (['description', 'website', 'note', 'ip_source'] as $field) {
                    $value = $_POST[$field] ?? null;
                    if (!is_string($value) || strlen($value) > 16000) {
                        throw new InvalidArgumentException('Проверьте поля карточки бота.');
                    }
                    $value = trim($value);
                    if (in_array($field, ['website', 'ip_source'], true) && $value !== ''
                        && (!filter_var($value, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $value))) {
                        throw new InvalidArgumentException('Укажите полный адрес сайта или источника.');
                    }
                    $fields[] = $value;
                }
                $ranges = [];
                foreach (preg_split('/\R/', $input) as $line) {
                    $entry = trim($line);
                    if ($entry !== '') {
                        $ranges[$entry] = ipRange($entry);
                    }
                }
                $db->beginTransaction();
                try {
                    $botTypeId = filter_var($_POST['bot_type_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    $validType = $db->prepare('SELECT 1 FROM bot_types WHERE id = ?');
                    $validType->execute([$botTypeId ?: 0]);
                    if (!$validType->fetchColumn()) {
                        throw new InvalidArgumentException('Выберите вид бота из справочника.');
                    }
                    if ($newBot) {
                        $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
                        $signature = is_string($_POST['signature'] ?? null) ? trim($_POST['signature']) : '';
                        if ($name === '' || strlen($name) > 400 || !$companyId || strlen($signature) > 400) {
                            throw new InvalidArgumentException('Укажите наименование бота и компанию.');
                        }
                        $create = $db->prepare('INSERT INTO bots (name, company_id, bot_type_id)
                            SELECT ?, id, ? FROM companies WHERE id = ? ON CONFLICT (company_id, name) DO NOTHING');
                        $create->execute([$name, $botTypeId, $companyId]);
                        if (!$create->rowCount()) {
                            throw new InvalidArgumentException('Компания не найдена или бот с таким именем уже существует.');
                        }
                        $botId = (int) $db->lastInsertId();
                        $addSignature = $db->prepare('INSERT INTO bot_signatures (bot_id, pattern) VALUES (?, ?) ON CONFLICT (pattern) DO NOTHING');
                        $addSignature->execute([$botId, $signature !== '' ? $signature : $name]);
                        if (!$addSignature->rowCount()) {
                            $botId = false;
                            throw new InvalidArgumentException('Эта сигнатура User-Agent уже используется. Укажите другую.');
                        }
                    }
                    $update = $db->prepare('UPDATE bots SET description = ?, website = ?, note = ?, ip_source = ?, bot_type_id = ?, disabled = ? WHERE id = ? AND archived = 0');
                    $update->execute([...$fields, $botTypeId, ($_POST['disabled'] ?? '') === '1' ? 1 : 0, $botId]);
                    if ($update->rowCount() === 0) {
                        throw new InvalidArgumentException('Бот не найден.');
                    }
                    if (!$newBot) {
                        $existingQuery = $db->prepare('SELECT id, pattern FROM bot_signatures WHERE bot_id = ? ORDER BY id');
                        $existingQuery->execute([$botId]);
                        $existingSignatures = $existingQuery->fetchAll();
                        $existingByPattern = [];
                        foreach ($existingSignatures as $signatureRow) {
                            $existingByPattern[strtolower($signatureRow['pattern'])] = $signatureRow;
                        }
                        $claimedIds = [];
                        $conflict = $db->prepare('SELECT 1 FROM bot_signatures WHERE pattern = ? COLLATE NOCASE AND bot_id <> ?');
                        $updateSignature = $db->prepare('UPDATE bot_signatures SET pattern = ? WHERE id = ?');
                        $addSignature = $db->prepare('INSERT INTO bot_signatures (bot_id, pattern) VALUES (?, ?)');
                        foreach ($signaturePatterns as $key => $pattern) {
                            $signatureRow = $existingByPattern[$key] ?? null;
                            if ($signatureRow === null) {
                                foreach ($existingSignatures as $candidate) {
                                    if (!isset($claimedIds[(int) $candidate['id']])) {
                                        $signatureRow = $candidate;
                                        break;
                                    }
                                }
                            }
                            $conflict->execute([$pattern, $botId]);
                            if ($conflict->fetchColumn()) {
                                throw new InvalidArgumentException('Эта сигнатура User-Agent уже назначена другому боту: ' . $pattern);
                            }
                            if ($signatureRow !== null) {
                                $signatureId = (int) $signatureRow['id'];
                                $claimedIds[$signatureId] = true;
                                $updateSignature->execute([$pattern, $signatureId]);
                            } else {
                                $addSignature->execute([$botId, $pattern]);
                            }
                        }
                        $removeSignature = $db->prepare('DELETE FROM bot_signatures WHERE id = ? AND bot_id = ?');
                        foreach ($existingSignatures as $signatureRow) {
                            $signatureId = (int) $signatureRow['id'];
                            if (!isset($claimedIds[$signatureId])) {
                                $removeSignature->execute([$signatureId, $botId]);
                            }
                        }
                    }
                    $db->prepare('DELETE FROM bot_ip_ranges WHERE bot_id = ?')->execute([$botId]);
                    $insert = $db->prepare('INSERT INTO bot_ip_ranges VALUES (?, ?, ?, ?)');
                    foreach ($ranges as $entry => [$first, $last]) {
                        $insert->execute([$botId, $entry, $first, $last]);
                    }
                    $db->commit();
                } finally {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                }
                $_SESSION['notice'] = 'Настройки бота сохранены.';
            } elseif ($action === 'edit_site') {
                $editSiteId = filter_var($_POST['site_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $domain = is_string($_POST['domain'] ?? null) ? strtolower(trim($_POST['domain'])) : '';
                $path = is_string($_POST['log_path'] ?? null) ? trim($_POST['log_path']) : '';
                $root = realpath($db->query('SELECT log_root FROM settings WHERE id = 1')->fetchColumn());
                if (!$editSiteId || !filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
                    || $root === false || resolveLog($root, $path) === null) {
                    throw new InvalidArgumentException('Укажите корректный домен и путь к доступному файлу лога. Проверьте корневой каталог логов в настройках.');
                }
                $note = is_string($_POST['note'] ?? null) ? trim($_POST['note']) : '';
                if (strlen($note) > 2000 || preg_match('/\R/', $note)) {
                    throw new InvalidArgumentException('Примечание должно быть одной строкой.');
                }
                $duplicate = $db->prepare('SELECT 1 FROM sites WHERE domain = ? AND id <> ?');
                $duplicate->execute([$domain, $editSiteId]);
                if ($duplicate->fetchColumn()) {
                    throw new InvalidArgumentException('Такой домен уже зарегистрирован.');
                }
                $exists = $db->prepare('SELECT 1 FROM sites WHERE id = ? AND archived = 0');
                $exists->execute([$editSiteId]);
                if (!$exists->fetchColumn()) {
                    throw new InvalidArgumentException('Сайт не найден.');
                }
                $save = $db->prepare('UPDATE sites SET domain = ?, log_path = ?, note = ? WHERE id = ? AND archived = 0');
                $save->execute([$domain, $path, $note, $editSiteId]);
                $_SESSION['notice'] = 'Настройки сайта сохранены.';
            } else {
                $domain = is_string($_POST['domain'] ?? null) ? strtolower(trim($_POST['domain'])) : '';
                $path = is_string($_POST['log_path'] ?? null) ? trim($_POST['log_path']) : '';
                $path = $path !== '' ? $path : $domain . '.access.log';
                $root = realpath($db->query('SELECT log_root FROM settings WHERE id = 1')->fetchColumn());
                if (!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
                    || $root === false || resolveLog($root, $path) === null) {
                    throw new InvalidArgumentException('Укажите домен и путь к доступному файлу лога. Проверьте корневой каталог логов в настройках.');
                }
                $add = $db->prepare('INSERT INTO sites (domain, log_path) VALUES (?, ?) ON CONFLICT (domain) DO UPDATE SET log_path = excluded.log_path, enabled = 1, archived = 0 WHERE sites.archived = 1');
                $add->execute([$domain, $path]);
                $_SESSION['notice'] = $add->rowCount() === 1 ? 'Сайт добавлен.' : 'Сайт уже добавлен.';
            }
        } catch (InvalidArgumentException $error) {
            $_SESSION['notice'] = $error->getMessage();
        } catch (Throwable $error) {
            error_log($error->getMessage());
            $_SESSION['notice'] = $action === 'generate_daily_digest'
                ? $error->getMessage()
                : 'Не удалось выполнить действие.';
        }
        if ($action === 'collect') {
            $destination = '/';
        } else {
            $destinationTab = match (true) {
                in_array($action, ['generate_daily_digest', 'save_digest_settings'], true) => 'digests',
                $action === 'save_collection_settings' => 'collection',
                in_array($action, ['add_company', 'bot_ips', 'refresh_bot_ips', 'delete_bot'], true) => 'bots',
                in_array($action, ['add_bot_type', 'save_bot_type'], true) => 'bot-types',
                default => 'sites',
            };
            $destination = '/?view=settings&tab=' . $destinationTab;
            if (in_array($action, ['bot_ips', 'refresh_bot_ips'], true) && !empty($botId)) {
                $destination .= '&bot=' . (int) $botId;
            }
            if ($action === 'add_company' && !empty($createdCompanyId)) {
                $destination .= '&company=' . (int) $createdCompanyId;
            }
            if ($action === 'save_company' && !empty($companyId)) {
                $destination .= '&company=' . (int) $companyId;
            }
            if ($action === 'edit_site' && !empty($editSiteId)) {
                $destination .= '&edit_site=' . $editSiteId;
            }
            if ($action === 'bot_ips' && !empty($newBot) && empty($botId)) {
                $destination .= '&new_bot=1&company=' . (int) ($companyId ?? 0);
            }
        }
        header('Location: ' . $destination, true, 303);
        exit;
    }

    $login = $_POST['login'] ?? null;
    $password = $_POST['password'] ?? null;
    if (is_string($login) && is_string($password) && strlen($login) <= 64 && strlen($password) <= 72 && !str_contains($password, "\0")) {
        $query = $db->prepare('SELECT id, password_hash, needs_password, disabled FROM users WHERE login = ? AND enabled = 1');
        $query->execute([trim($login)]);
        $account = $query->fetch();
        if ($account && (int) $account['disabled'] === 1 && password_verify($password, $account['password_hash'])) {
            http_response_code(403);
            $error = 'Учетная запись заблокирована, обратитесь к администратору ресурса';
        } elseif ($account && (int) $account['disabled'] === 1) {
            http_response_code(401);
            $error = 'Неверный логин или пароль.';
        } elseif ($account && (int) $account['needs_password'] === 1) {
            session_regenerate_id(true);
            $_SESSION = ['password_setup_user_id' => (int) $account['id']];
            header('Location: /set-password', true, 303);
            exit;
        } elseif ($account && (int) $account['needs_password'] === 0 && password_verify($password, $account['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION = ['user_id' => (int) $account['id']];
            header('Location: /', true, 303);
            exit;
        } else {
            http_response_code(401);
            $error = 'Неверный логин или пароль.';
        }
    } else {
        http_response_code(401);
        $error = 'Неверный логин или пароль.';
    }
}

$user = null;
if (isset($_SESSION['user_id'])) {
    $query = $db->prepare('SELECT login, role, report_filters, needs_password FROM users WHERE id = ? AND enabled = 1 AND disabled = 0');
    $query->execute([$_SESSION['user_id']]);
    $user = $query->fetch() ?: null;
    if (!$user || (int) $user['needs_password'] === 1) {
        if ($user) {
            $_SESSION['password_setup_user_id'] = (int) $_SESSION['user_id'];
        }
        unset($_SESSION['user_id']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['cancel_password_setup'])) {
    unset($_SESSION['password_setup_user_id']);
    header('Location: /', true, 303);
    exit;
}
$setupId = (int) ($_SESSION['password_setup_user_id'] ?? 0);
if ($setupId > 0 && !$passwordSetupPage && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /set-password', true, 303);
    exit;
}
if ($setupId === 0 && $passwordSetupPage) {
    header('Location: /', true, 303);
    exit;
}

$navigationPeriod = [];
if ($user) {
    $reportSettings = (array) json_decode($user['report_filters'] ?? '{}', true);
    foreach (['from' => 'period_from', 'to' => 'period_to'] as $parameter => $setting) {
        $value = $_GET[$parameter] ?? $reportSettings[$setting] ?? null;
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            $navigationPeriod[$parameter] = $value;
        }
    }
}
$reportsNavUrl = '/' . ($navigationPeriod ? '?' . http_build_query($navigationPeriod) : '');
$ratingNavUrl = '/?' . http_build_query(['view' => 'rating'] + $navigationPeriod);

$requestedSiteSort = $_GET['site_sort'] ?? null;
if ($user && !$settingsPage && !$ratingPage && is_string($requestedSiteSort)
    && $isValidSiteSort($requestedSiteSort)) {
    $reportSettings = (array) json_decode($user['report_filters'] ?? '{}', true);
    if (($reportSettings['site_sort'] ?? null) !== $requestedSiteSort) {
        $reportSettings['site_sort'] = $requestedSiteSort;
        $encodedSettings = json_encode($reportSettings);
        if ($encodedSettings !== false) {
            $save = $db->prepare('UPDATE users SET report_filters = ? WHERE id = ? AND enabled = 1');
            $save->execute([$encodedSettings, $_SESSION['user_id']]);
            $user['report_filters'] = $encodedSettings;
        }
    }
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatCount(int $number): string
{
    return number_format($number, 0, ',', ' ');
}

function pluralize(int $number, string $one, string $few, string $many): string
{
    $mod100 = $number % 100;
    $mod10 = $number % 10;
    if ($mod100 >= 11 && $mod100 <= 14) {
        return $many;
    }

    return match ($mod10) {
        1 => $one,
        2, 3, 4 => $few,
        default => $many,
    };
}

// Версия файла в адресе не даёт браузеру держать старые стили и скрипты
// после обновления панели.
function assetUrl(string $file): string
{
    $path = __DIR__ . '/' . $file;
    $version = is_file($path) ? filemtime($path) : false;

    return '/' . $file . ($version === false ? '' : '?v=' . $version);
}

$isAdmin = $user !== null && $user['role'] === 'admin';
$pageTitle = $settingsPage && $isAdmin ? 'Настройки — CheckAI' : ($ratingPage ? 'Рейтинг — CheckAI' : 'Отчёты — CheckAI');

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f1f2ee">
    <title><?= escape($pageTitle) ?></title>
    <script src="<?= escape(assetUrl('theme.js')) ?>"></script>
    <link rel="stylesheet" href="<?= escape(assetUrl('app.css')) ?>">
    <?php if ($user && !$settingsPage): ?><script src="<?= escape(assetUrl('report-filters.js')) ?>" defer></script><?php endif; ?>
    <?php if ($settingsPage && $isAdmin): ?><script src="<?= escape(assetUrl('settings.js')) ?>" defer></script><?php endif; ?>
    <?php if ($user): ?><script src="<?= escape(assetUrl('app.js')) ?>" defer></script><?php endif; ?>
</head>
<body>
<a class="skip" href="#main">Перейти к содержимому</a>
<?php if ($user): ?>
<div class="app">
    <aside class="rail">
        <a class="brand" href="<?= escape($reportsNavUrl) ?>">
            <span class="brand__name">CheckAI</span>
            <span class="brand__note">Мониторинг ИИ-ботов</span>
        </a>
        <nav class="nav" aria-label="Разделы">
            <a href="<?= escape($reportsNavUrl) ?>"<?= !$settingsPage && !$ratingPage ? ' aria-current="page"' : '' ?>>Отчёты</a>
            <a href="<?= escape($ratingNavUrl) ?>"<?= $ratingPage ? ' aria-current="page"' : '' ?>>Рейтинг</a>
            <?php if ($isAdmin): ?>
                <a href="/?view=settings"<?= $settingsPage ? ' aria-current="page"' : '' ?>>Настройки</a>
            <?php endif; ?>
        </nav>
        <div class="rail__foot">
            <p class="who">
                <strong><?= escape($user['login']) ?></strong>
                <?= $isAdmin ? 'Администратор' : 'Пользователь' ?>
            </p>
            <div class="rail__foot-row">
                <div class="theme-switch" role="group" aria-label="Тема оформления">
                    <button type="button" class="theme-switch__btn" data-theme-set="light">Светлая</button>
                    <button type="button" class="theme-switch__btn" data-theme-set="dark">Тёмная</button>
                </div>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= escape($token) ?>">
                    <button class="btn btn--sm btn--quiet" name="action" value="logout">Выйти</button>
                </form>
            </div>
        </div>
    </aside>
    <main id="main" class="main">
        <header class="page-head">
            <div class="page-head__text">
                <h1><?= $settingsPage && $isAdmin ? 'Настройки' : ($ratingPage ? 'Рейтинг ботов' : 'Отчёты') ?></h1>
                <p><?= $settingsPage && $isAdmin ? 'Сайты, боты и автосбор' : ($ratingPage ? 'Запросы по компаниям, ботам и сайтам' : 'Запросы ИИ-ботов к вашим сайтам') ?><?php if (!$settingsPage && $lastCollectionAt !== null): ?> (<?= escape($lastCollectionAt->format('d.m.Y H:i:s')) ?>)<?php endif; ?></p>
            </div>
            <?php if ($isAdmin && !$settingsPage): ?>
                <div class="actions">
                    <?php $autoCollectEnabled = (int) $db->query('SELECT auto_collect FROM settings WHERE id = 1')->fetchColumn() === 1; ?>
                    <form method="post" action="/" data-busy>
                        <input type="hidden" name="csrf" value="<?= escape($token) ?>">
                        <button class="btn<?= $autoCollectEnabled ? '' : ' btn--primary' ?>" name="action" value="collect">Обновить данные</button>
                    </form>
                </div>
            <?php endif; ?>
        </header>
        <?php if ($notice !== ''): ?><p class="notice" role="status"><?= escape($notice) ?></p><?php endif; ?>
        <?php if ($isAdmin && $settingsPage): ?>
            <?php
            $siteCount = (int) $db->query('SELECT COUNT(*) FROM sites WHERE archived = 0')->fetchColumn();
            $botCount = (int) $db->query('SELECT COUNT(*) FROM bots WHERE archived = 0')->fetchColumn();
            $botTypeCount = (int) $db->query('SELECT COUNT(*) FROM bot_types')->fetchColumn();
            $userCount = (int) $db->query('SELECT COUNT(*) FROM users WHERE enabled = 1')->fetchColumn();
            ?>
            <?php if (!$settingsDetail): ?>
                <nav class="tabs" aria-label="Разделы настроек">
                    <a href="/?view=settings&amp;tab=sites"<?= $settingsTab === 'sites' ? ' aria-current="page"' : '' ?>>Сайты (<?= formatCount($siteCount) ?>)</a>
                    <a href="/?view=settings&amp;tab=bots"<?= $settingsTab === 'bots' ? ' aria-current="page"' : '' ?>>Боты (<?= formatCount($botCount) ?>)</a>
                    <a href="/?view=settings&amp;tab=bot-types"<?= $settingsTab === 'bot-types' ? ' aria-current="page"' : '' ?>>Виды ботов (<?= formatCount($botTypeCount) ?>)</a>
                    <a href="/?view=settings&amp;tab=users"<?= $settingsTab === 'users' ? ' aria-current="page"' : '' ?>>Пользователи (<?= formatCount($userCount) ?>)</a>
                    <a href="/?view=settings&amp;tab=collection"<?= $settingsTab === 'collection' ? ' aria-current="page"' : '' ?>>Сбор данных</a>
                    <a href="/?view=settings&amp;tab=digests"<?= $settingsTab === 'digests' ? ' aria-current="page"' : '' ?>>Дайджесты</a>
                </nav>
            <?php endif; ?>
            <?php if ($settingsTab === 'users' && isset($_GET['edit_user'])): ?>
                <?php
                $editUserId = filter_var($_GET['edit_user'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $userQuery = $db->prepare('SELECT id, login, role, needs_password, disabled FROM users WHERE id = ? AND enabled = 1');
                $userQuery->execute([$editUserId ?: 0]);
                $editUser = $userQuery->fetch();
                ?>
                <nav class="crumbs crumbs--lead" aria-label="Путь">
                    <ol>
                        <li><a href="/?view=settings&amp;tab=users">Пользователи</a></li>
                        <li><span aria-current="page"><?= $editUser ? escape($editUser['login']) : 'Пользователь не найден' ?></span></li>
                    </ol>
                </nav>
                <div class="section">
                    <?php if ($editUser): ?>
                        <h2>Карточка пользователя</h2>
                        <p><?= (int) $editUser['disabled'] === 1 ? 'Отключен' : ($editUser['role'] === 'admin' ? 'Администратор' : 'Пользователь') ?><?= (int) $editUser['needs_password'] === 1 ? ' · ожидает установки пароля' : '' ?></p>
                        <div class="user-settings-card">
                            <form method="post" class="user-settings-form">
                                <input type="hidden" name="csrf" value="<?= escape($token) ?>">
                                <input type="hidden" name="user_id" value="<?= (int) $editUser['id'] ?>">
                                <label class="field">
                                    <span>Логин</span>
                                    <input name="login" value="<?= escape($editUser['login']) ?>" minlength="3" maxlength="64" pattern="[a-zA-Z0-9_.-]{3,64}" required autocomplete="off">
                                </label>
                                <label class="field">
                                    <span>Тип пользователя</span>
                                    <select name="role">
                                        <option value="user"<?= $editUser['role'] === 'user' ? ' selected' : '' ?>>Пользователь</option>
                                        <option value="admin"<?= $editUser['role'] === 'admin' ? ' selected' : '' ?>>Администратор</option>
                                    </select>
                                </label>
                                <label class="check">
                                    <input type="checkbox" name="disabled" value="1"<?= (int) $editUser['disabled'] === 1 ? ' checked' : '' ?>>
                                    Отключен
                                </label>
                                <button class="btn btn--sm btn--primary" name="action" value="save_user_settings">Сохранить настройки</button>
                            </form>
                            <?php if ((int) $editUser['id'] !== (int) ($_SESSION['user_id'] ?? 0)): ?>
                                <form method="post" class="user-settings-form user-settings-actions">
                                    <input type="hidden" name="csrf" value="<?= escape($token) ?>">
                                    <input type="hidden" name="user_id" value="<?= (int) $editUser['id'] ?>">
                                    <button class="btn btn--sm btn--quiet" name="action" value="reset_user_password">Сбросить пароль</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <h2>Пользователь не найден</h2>
                        <p><a href="/?view=settings&amp;tab=users">Вернуться к списку пользователей</a></p>
                    <?php endif; ?>
                </div>
            <?php elseif ($settingsTab === 'users'): ?>
                <?php
                $accounts = $db->query('SELECT id, login, role, needs_password, disabled FROM users WHERE enabled = 1 ORDER BY login')->fetchAll();
                ?>
                <div class="section">
                    <h2>Пользователи</h2>
                    <ul class="rows">
                        <?php foreach ($accounts as $account): ?>
                            <li>
                                <span class="row-main">
                                    <a href="/?view=settings&amp;tab=users&amp;edit_user=<?= (int) $account['id'] ?>"<?= (int) $account['disabled'] === 1 ? ' class="link--disabled"' : '' ?>><?= escape($account['login']) ?></a>
                                    <?php if ((int) $account['disabled'] === 1): ?>
                                        <span class="row-path">(Отключен)</span>
                                    <?php else: ?>
                                        <span class="row-path"><?= $account['role'] === 'admin' ? 'Администратор' : 'Пользователь' ?><?= (int) $account['needs_password'] === 1 ? ' · ожидает установки пароля' : '' ?></span>
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="group"><h3>Создать пользователя</h3>
                        <form method="post" class="user-create-form"><input type="hidden" name="csrf" value="<?= escape($token) ?>">
                            <label class="field"><span>Логин</span><input name="new_login" minlength="3" maxlength="64" required autocomplete="off"></label>
                            <label class="field"><span>Тип учётной записи</span><select name="role"><option value="user">Пользователь</option><option value="admin">Администратор</option></select></label>
                            <button class="btn btn--primary" name="action" value="create_user">Создать</button>
                        </form>
                        <p class="hint user-create-hint">При первом входе пользователь сформирует и установит пароль.</p>
                    </div>
                </div>
            <?php elseif ($settingsTab === 'bots'): ?>
                <?php require dirname(__DIR__) . '/src/bot-settings.php'; ?>
            <?php elseif ($settingsTab === 'bot-types'): ?>
                <?php
                $botTypes = $db->query('SELECT t.id, t.name, COUNT(b.id) AS bot_count
                    FROM bot_types t LEFT JOIN bots b ON b.bot_type_id = t.id AND b.archived = 0
                    GROUP BY t.id ORDER BY t.id')->fetchAll();
                ?>
                <div class="section">
                    <h2>Виды ботов</h2>
                    <p>Классификация ботов по назначению их обращений к сайтам.</p>
                    <ul class="rows">
                        <?php foreach ($botTypes as $type): ?>
                            <li>
                                <form method="post" class="form-row">
                                    <input type="hidden" name="csrf" value="<?= escape($token) ?>">
                                    <input type="hidden" name="bot_type_id" value="<?= (int) $type['id'] ?>">
                                    <label class="field"><input name="name" aria-label="Название вида бота" value="<?= escape($type['name']) ?>" required maxlength="200"></label>
                                    <span class="row-meta"><?= formatCount((int) $type['bot_count']) ?> шт.</span>
                                    <button class="btn btn--sm" name="action" value="save_bot_type">Сохранить</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="group">
                        <h3>Добавить вид</h3>
                        <form method="post" class="form-row">
                            <input type="hidden" name="csrf" value="<?= escape($token) ?>">
                            <label class="field"><span>Название вида</span><input name="name" required maxlength="200"></label>
                            <button class="btn btn--primary" name="action" value="add_bot_type">Добавить вид</button>
                        </form>
                    </div>
                </div>
            <?php elseif ($settingsTab === 'digests'): ?>
                <?php $digestSettings = $db->query('SELECT digest_directory, daily_digest FROM settings WHERE id = 1')->fetch(); ?>
                <?php $digestDirectory = (string) $digestSettings['digest_directory']; ?>
                <?php $dailyDigestEnabled = (int) $digestSettings['daily_digest']; ?>
                <div class="section">
                    <h2>Ежедневный дайджест</h2>
                    <p>Дайджест охватывает предыдущий календарный день. В него входят все активные сайты и виды ботов; отключённые боты и компании исключаются.</p>
                    <p>Файлы создаются в формате <span class="mono">YYYYMMDD-HH-MM_digest.txt</span>.</p>
                    <form method="post" class="stack">
                        <input type="hidden" name="csrf" value="<?= escape($token) ?>">
                        <label class="check">
                            <input type="checkbox" name="enabled" value="1"<?= $dailyDigestEnabled ? ' checked' : '' ?>>
                            Формировать ежедневный дайджест
                        </label>
                        <div class="form-actions">
                            <button class="btn" name="action" value="generate_daily_digest">Сформировать дайджест сейчас</button>
                        </div>
                        <label class="field">
                            <span>Каталог для файлов дайджестов</span>
                            <input name="digest_directory" value="<?= escape($digestDirectory) ?>" required maxlength="4096" spellcheck="false" autocapitalize="off" autocomplete="off" class="mono">
                            <small>Укажите существующий абсолютный путь. Каталог должен быть доступен для записи пользователю PHP (обычно <span class="mono">www-data</span>).</small>
                        </label>
                        <div class="form-actions">
                            <button class="btn" name="action" value="save_digest_settings">Сохранить настройки</button>
                        </div>
                    </form>
                    <p>Включите флажок выше и сохраните настройки, чтобы разрешить автоматическое формирование. В Docker дайджест запускается ежедневно в 01:00. При запуске без Docker добавьте задачу в cron от имени пользователя веб-сервера, которому доступны база данных и выбранный каталог. Для этой установки используйте рабочий каталог проекта <span class="mono">/var/www/checkai.analitica-soft.ru</span>:</p>
                    <p class="mono">sudo crontab -u www-data -e</p>
                    <p>Добавьте строку:</p>
                    <p>0 1 * * * /usr/bin/php /var/www/checkai.analitica-soft.ru/bin/generate-digest.php</p>
                </div>
            <?php elseif ($settingsTab === 'collection'): ?>
                <?php
                $settings = $db->query('SELECT auto_collect, log_root FROM settings WHERE id = 1')->fetch();
                $automatic = (int) $settings['auto_collect'];
                ?>
                <div class="section">
                    <h2>Сбор данных логов</h2>
                    <form method="post" class="stack">
                        <input type="hidden" name="csrf" value="<?= escape($token) ?>">
                        <label class="field">
                            <span>Корень каталога логов</span>
                            <input name="log_root" value="<?= escape($settings['log_root']) ?>" required maxlength="4096" spellcheck="false" autocapitalize="off" autocomplete="off" class="mono">
                            <small>Абсолютный путь к каталогу, доступному PHP. Он хранится в базе этой установки. Для новых баз значение по умолчанию: <span class="mono">/var/logs</span>.</small>
                        </label>
                        <label class="check">
                            <input type="checkbox" name="enabled" value="1"<?= $automatic ? ' checked' : '' ?>>
                            Автосбор каждые пять минут
                        </label>
                        <div class="form-actions">
                            <button class="btn" name="action" value="save_collection_settings">Сохранить настройки</button>
                        </div>
                    </form>
                    <hr style="margin: 1.5rem 0; border: 0; border-top: 1px solid var(--rule);">
                    <p>Обычный сбор запускается кнопкой «Обновить данные» на странице отчётов. Автосбор читает логи каждые пять минут.</p>
                    <p>Если приложение запущено без Docker, добавьте задачу в crontab пользователя, которому доступна база и папка логов. Откройте crontab командой <span class="mono">crontab -e</span> и добавьте строку, заменив путь к проекту и при необходимости путь к PHP:</p>
                    <p class="mono">*/5 * * * * /usr/bin/php /var/www/checkai.analitica-soft.ru/bin/collect.php --checksettings</p>
                    <p>Сохраните crontab, включите автосбор и сохраните настройки. В Docker отдельный сервис запускает сбор автоматически, crontab не нужен.</p>
                </div>
            <?php elseif (isset($_GET['edit_site'])): ?>
                <?php
                $logRoot = $db->query('SELECT log_root FROM settings WHERE id = 1')->fetchColumn();
                $editSiteId = filter_var($_GET['edit_site'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $lookup = $db->prepare('SELECT domain, log_path, note FROM sites WHERE id = ? AND archived = 0');
                $lookup->execute([$editSiteId ?: 0]);
                $editSite = $lookup->fetch();
                ?>
                <nav class="crumbs crumbs--lead" aria-label="Путь">
                    <ol>
                        <li><a href="/?view=settings&amp;tab=sites">Сайты</a></li>
                        <li><span aria-current="page"><?= $editSite ? escape($editSite['domain']) : 'Сайт не найден' ?></span></li>
                    </ol>
                </nav>
                <?php if ($editSite): ?>
                    <div class="section">
                        <h2><?= escape($editSite['domain']) ?> <span>путь к access-логу</span></h2>
                        <form method="post" class="stack">
                            <input type="hidden" name="csrf" value="<?= escape($token) ?>">
                            <input type="hidden" name="site_id" value="<?= $editSiteId ?>">
                            <label class="field">
                                <span>Домен сайта</span>
                                <input name="domain" value="<?= escape($editSite['domain']) ?>" required spellcheck="false" autocapitalize="off" autocomplete="off">
                            </label>
                            <label class="field">
                                <span>Путь к логу</span>
                                <input name="log_path" value="<?= escape($editSite['log_path']) ?>" required spellcheck="false" autocapitalize="off" autocomplete="off" class="mono">
                                <small>Относительно корня логов (<span class="mono"><?= escape(rtrim((string) $logRoot, '/\\') . '/') ?></span>), например <span class="mono">example.com.access.log</span>.</small>
                            </label>
                            <label class="field">
                                <span>Примечание</span>
                                <input name="note" value="<?= escape($editSite['note']) ?>" autocomplete="off">
                                <small>Одна строка, до 2000 символов.</small>
                            </label>
                            <div class="form-actions">
                                <button class="btn btn--primary" name="action" value="edit_site">Сохранить</button>
                                <a class="btn btn--quiet" href="/?view=settings&amp;tab=sites">К списку сайтов</a>
                            </div>
                        </form>
                        <form method="post" class="form-actions" data-confirm="Удалить сайт из мониторинга? История запросов сохранится.">
                            <input type="hidden" name="csrf" value="<?= escape($token) ?>">
                            <input type="hidden" name="id" value="<?= $editSiteId ?>">
                            <button class="btn btn--danger" name="action" value="delete_site">Удалить сайт</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="empty">
                        <p>Сайт не найден: возможно, его уже удалили.</p>
                        <p><a href="/?view=settings&amp;tab=sites">Вернуться к списку сайтов</a></p>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <?php
                $sites = $db->query('SELECT id, domain, log_path FROM sites WHERE archived = 0 ORDER BY domain')->fetchAll();
                $logRoot = $db->query('SELECT log_root FROM settings WHERE id = 1')->fetchColumn();
                ?>
                <div class="section">
                    <h2>Сайты <span>(<?= formatCount(count($sites)) ?>)</span></h2>
                    <?php if ($sites): ?>
                        <ul class="rows">
                            <?php foreach ($sites as $site): ?>
                                <li>
                                    <span class="row-main">
                                        <a href="/?view=settings&amp;tab=sites&amp;edit_site=<?= (int) $site['id'] ?>"><?= escape($site['domain']) ?></a>
                                        <span class="row-path"><?= escape($site['log_path']) ?></span>
                                    </span>
                                    <a class="btn btn--sm btn--quiet" href="/?view=settings&amp;tab=sites&amp;edit_site=<?= (int) $site['id'] ?>">Изменить</a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="empty">
                            <p>Сайтов пока нет. Добавьте домен и путь к access-логу — сбор начнётся после первого запуска «Обновить данные».</p>
                        </div>
                    <?php endif; ?>
                    <div class="group">
                        <h3>Добавить сайт</h3>
                        <form method="post" class="stack">
                            <input type="hidden" name="csrf" value="<?= escape($token) ?>">
                            <label class="field">
                                <span>Домен</span>
                                <input name="domain" placeholder="example.com" required spellcheck="false" autocapitalize="off" autocomplete="off">
                            </label>
                            <label class="field">
                                <span>Путь к логу</span>
                                <input name="log_path" placeholder="example.com.access.log" spellcheck="false" autocapitalize="off" autocomplete="off" class="mono">
                                <small>Путь указывается относительно корня логов (<?= escape(rtrim((string) $logRoot, '/\\') . '/') ?>). Оставьте поле пустым — подставится имя из домена.</small>
                            </label>
                            <button class="btn btn--primary" name="action" value="add_site">Добавить сайт</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        <?php elseif ($ratingPage): ?>
            <?php require dirname(__DIR__) . '/src/rating.php'; ?>
        <?php else: ?>
            <?php require dirname(__DIR__) . '/src/report.php'; ?>
        <?php endif; ?>
    </main>
</div>
<?php else: ?>
<main id="main" class="login">
    <div class="login__panel">
        <a class="brand login__brand" href="/">
            <span class="brand__name">CheckAI</span>
            <span class="brand__note">Мониторинг ИИ-ботов</span>
        </a>
        <h1><?= $setupId > 0 ? 'Установка пароля' : 'Вход в панель' ?></h1>
        <p><?= $setupId > 0 ? 'Придумайте пароль для входа в панель.' : 'Доступ только для приглашённых пользователей.' ?></p>
        <?php if ($error !== ''): ?><p class="alert" role="alert"><?= escape($error) ?></p><?php endif; ?>
        <?php if ($setupId > 0): ?>
        <p><a href="/?cancel_password_setup=1">Вход</a></p>
        <form method="post" class="stack">
            <input type="hidden" name="csrf" value="<?= escape($token) ?>"><input type="hidden" name="action" value="set_password">
            <label class="field"><span>Новый пароль</span><input name="new_password" type="password" autocomplete="new-password" minlength="8" maxlength="72" required></label>
            <label class="field"><span>Повторите пароль</span><input name="confirm_password" type="password" autocomplete="new-password" minlength="8" maxlength="72" required></label>
            <div class="form-actions"><button class="btn btn--primary" type="submit">Сохранить пароль</button></div>
        </form>
        <?php else: ?>
        <form method="post" class="stack">
            <input type="hidden" name="csrf" value="<?= escape($token) ?>">
            <label class="field">
                <span>Логин</span>
                <input name="login" autocomplete="username" maxlength="64" required spellcheck="false" autocapitalize="off">
            </label>
            <label class="field">
                <span>Пароль</span>
                <input name="password" type="password" autocomplete="current-password">
            </label>
            <div class="form-actions">
                <button class="btn btn--primary" type="submit">Войти</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</main>
<?php endif; ?>
</body>
</html>
