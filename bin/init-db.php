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

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/database.php';

try {
    $database = openDatabase(create: true);
    if ($database->query('PRAGMA journal_mode = WAL')->fetchColumn() !== 'wal') {
        throw new RuntimeException('Cannot enable SQLite WAL mode.');
    }

    $database->exec('BEGIN IMMEDIATE');
    try {
        $version = (int) $database->query('PRAGMA user_version')->fetchColumn();
        if ($version === 0) {
            $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
            if ($schema === false) {
                throw new RuntimeException('Cannot read the database schema.');
            }
            $database->exec($schema);

            $catalog = require dirname(__DIR__) . '/database/catalog.php';
            $types = require dirname(__DIR__) . '/database/bot-types.php';
            $signatureOverrides = require dirname(__DIR__) . '/database/bot-signatures.php';
            $typeIds = [];
            foreach ($database->query('SELECT id, name FROM bot_types')->fetchAll() as $type) {
                $typeIds[$type['name']] = (int) $type['id'];
            }
            $typeByBot = [];
            foreach ($types as $typeName => $bots) {
                foreach ($bots as $botName) {
                    $typeByBot[$botName] = $typeIds[$typeName];
                }
            }
            $company = $database->prepare('INSERT INTO companies (name) VALUES (?)');
            $bot = $database->prepare('INSERT INTO bots (company_id, bot_type_id, name, disabled) VALUES (?, ?, ?, ?)');
            $signature = $database->prepare('INSERT INTO bot_signatures (bot_id, pattern) VALUES (?, ?)');

            foreach ($catalog as $companyName => $bots) {
                $company->execute([$companyName]);
                $companyId = (int) $database->lastInsertId();
                $disabled = in_array($companyName, ['ByteDance', 'Common Crawl'], true) ? 1 : 0;

                foreach ($bots as $name) {
                    $bot->execute([$companyId, $typeByBot[$name] ?? $typeIds['Прочее'], $name, $disabled]);
                    $signature->execute([(int) $database->lastInsertId(), $signatureOverrides[$name] ?? $name]);
                }
            }
            $database->exec('PRAGMA user_version = 1');
        }
        if ($version < 2) {
            $database->exec('ALTER TABLE settings ADD COLUMN auto_collect INTEGER NOT NULL DEFAULT 0 CHECK (auto_collect IN (0, 1))');
            $database->exec('PRAGMA user_version = 2');
        }
        if ($version < 3) {
            $database->exec('ALTER TABLE requests ADD COLUMN source_ip TEXT');
            $database->exec('CREATE TABLE bot_ip_ranges (
                bot_id INTEGER NOT NULL REFERENCES bots(id) ON DELETE CASCADE,
                entry TEXT NOT NULL, first_ip TEXT NOT NULL, last_ip TEXT NOT NULL,
                PRIMARY KEY (bot_id, entry)
            )');
            $database->exec('PRAGMA user_version = 3');
        }
        if ($version < 4) {
            foreach (['description', 'website', 'note', 'ip_source'] as $column) {
                $database->exec("ALTER TABLE bots ADD COLUMN $column TEXT NOT NULL DEFAULT ''");
            }
            require dirname(__DIR__) . '/database/seed-bot-details.php';
            $database->exec('PRAGMA user_version = 4');
        }
        if ($version < 5) {
            $database->exec("ALTER TABLE users ADD COLUMN report_filters TEXT NOT NULL DEFAULT '{}'");
            $database->exec('PRAGMA user_version = 5');
        }
        if ($version < 6) {
            $database->exec("ALTER TABLE sites ADD COLUMN note TEXT NOT NULL DEFAULT ''");
            $database->exec('ALTER TABLE sites ADD COLUMN archived INTEGER NOT NULL DEFAULT 0');
            $database->exec('ALTER TABLE bots ADD COLUMN archived INTEGER NOT NULL DEFAULT 0');
            $database->exec('PRAGMA user_version = 6');
        }
        if ($version > 0 && $version < 7) {
            $database->exec("CREATE TABLE bot_types (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL COLLATE NOCASE UNIQUE
            )");
            $database->exec("INSERT INTO bot_types (id, name) VALUES
                (1, 'Внутреннее обучение'),
                (2, 'Поисковые сканеры'),
                (3, 'Пользовательские запросы'),
                (4, 'Прочее')");
            $database->exec('ALTER TABLE bots ADD COLUMN bot_type_id INTEGER REFERENCES bot_types(id)');
            $classification = require dirname(__DIR__) . '/database/bot-types.php';
            $ids = [];
            foreach ($database->query('SELECT id, name FROM bot_types')->fetchAll() as $type) {
                $ids[$type['name']] = (int) $type['id'];
            }
            $typeByBot = [];
            foreach ($classification as $typeName => $bots) {
                foreach ($bots as $botName) {
                    $typeByBot[$botName] = $ids[$typeName];
                }
            }
            $updateType = $database->prepare('UPDATE bots SET bot_type_id = ? WHERE name = ? COLLATE NOCASE');
            foreach ($typeByBot as $botName => $typeId) {
                $updateType->execute([$typeId, $botName]);
            }
            $database->exec("UPDATE bots SET bot_type_id = {$ids['Прочее']} WHERE bot_type_id IS NULL");
            $database->exec('CREATE INDEX bots_type ON bots (bot_type_id)');
            $database->exec('PRAGMA user_version = 7');
        } elseif ($version > 21) {
            throw new RuntimeException("Unsupported SQLite schema version: $version");
        }
        if ($version > 0 && $version < 8) {
            $database->exec('ALTER TABLE users ADD COLUMN needs_password INTEGER NOT NULL DEFAULT 0 CHECK (needs_password IN (0, 1))');
        }
        if ($version > 0 && $version < 9) {
            $database->exec("ALTER TABLE companies ADD COLUMN description TEXT NOT NULL DEFAULT ''");
        }
        if ($version > 0 && $version < 10) {
            $database->exec('ALTER TABLE companies ADD COLUMN disabled INTEGER NOT NULL DEFAULT 0 CHECK (disabled IN (0, 1))');
            $database->exec('ALTER TABLE bots ADD COLUMN disabled INTEGER NOT NULL DEFAULT 0 CHECK (disabled IN (0, 1))');
            $database->exec('PRAGMA user_version = 10');
        }
        if ($version > 0 && $version < 11) {
            $database->exec('ALTER TABLE bots ADD COLUMN ip_updated_at TEXT');
            $database->exec('PRAGMA user_version = 11');
        }
        if ($version > 0 && $version < 12) {
            $database->exec('ALTER TABLE users ADD COLUMN disabled INTEGER NOT NULL DEFAULT 0 CHECK (disabled IN (0, 1))');
            $database->exec('PRAGMA user_version = 12');
        }
        if ($version > 0 && $version < 13) {
            $database->exec("ALTER TABLE settings ADD COLUMN digest_directory TEXT NOT NULL DEFAULT '/var/www/checkai.analitica-soft.ru/digests'");
            $database->exec('PRAGMA user_version = 13');
        }
        if ($version > 0 && $version < 14) {
            $database->exec("CREATE TABLE settings_new (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                log_root TEXT NOT NULL DEFAULT '/var/logs',
                report_timezone TEXT NOT NULL DEFAULT 'Asia/Yekaterinburg',
                auto_collect INTEGER NOT NULL DEFAULT 0 CHECK (auto_collect IN (0, 1)),
                digest_directory TEXT NOT NULL DEFAULT '/var/www/checkai.analitica-soft.ru/digests'
            )");
            $database->exec("INSERT INTO settings_new (id, log_root, report_timezone, auto_collect, digest_directory)
                SELECT id, log_root, report_timezone, auto_collect,
                    CASE WHEN digest_directory = '/var/www/checkai.analitica-soft.ru/digest'
                         THEN '/var/www/checkai.analitica-soft.ru/digests'
                         ELSE digest_directory END
                FROM settings");
            $database->exec('DROP TABLE settings');
            $database->exec('ALTER TABLE settings_new RENAME TO settings');
            $database->exec('PRAGMA user_version = 14');
        }
        if ($version > 0 && $version < 15) {
            $oldTypeId = $database->query("SELECT id FROM bot_types WHERE name = 'Сборщики данных для обучения'")->fetchColumn();
            $newTypeId = $database->query("SELECT id FROM bot_types WHERE name = 'Внутреннее обучение'")->fetchColumn();
            if ($oldTypeId !== false && $newTypeId !== false) {
                $mergeBots = $database->prepare('UPDATE bots SET bot_type_id = ? WHERE bot_type_id = ?');
                $mergeBots->execute([(int) $newTypeId, (int) $oldTypeId]);
                $deleteType = $database->prepare('DELETE FROM bot_types WHERE id = ?');
                $deleteType->execute([(int) $oldTypeId]);
            } elseif ($oldTypeId !== false) {
                $renameType = $database->prepare('UPDATE bot_types SET name = ? WHERE id = ?');
                $renameType->execute(['Внутреннее обучение', (int) $oldTypeId]);
            }
            $database->exec('PRAGMA user_version = 15');
        }
        if ($version === 0) {
            $database->exec('PRAGMA user_version = 16');
        }
        if ($version > 0 && $version < 16) {
            $database->exec('ALTER TABLE settings ADD COLUMN daily_digest INTEGER NOT NULL DEFAULT 0 CHECK (daily_digest IN (0, 1))');
            $database->exec('PRAGMA user_version = 16');
        }
        if ($version < 17) {
            $company = $database->prepare('SELECT id FROM companies WHERE name = ?');
            $company->execute(['DeepSeek']);
            $companyId = $company->fetchColumn();
            if ($companyId === false) {
                $database->prepare('INSERT INTO companies (name) VALUES (?)')->execute(['DeepSeek']);
                $companyId = (int) $database->lastInsertId();
            }

            $type = $database->prepare('SELECT id FROM bot_types WHERE name = ?');
            $type->execute(['Пользовательские запросы']);
            $typeId = $type->fetchColumn();
            if ($typeId === false) {
                throw new RuntimeException('Bot type for DeepSeek-Harness is missing.');
            }

            $bot = $database->prepare('SELECT id FROM bots WHERE company_id = ? AND name = ?');
            $bot->execute([$companyId, 'DeepSeek-Harness']);
            $botId = $bot->fetchColumn();
            if ($botId === false) {
                $database->prepare('INSERT INTO bots (company_id, bot_type_id, name) VALUES (?, ?, ?)')
                    ->execute([$companyId, $typeId, 'DeepSeek-Harness']);
                $botId = (int) $database->lastInsertId();
            } else {
                $database->prepare('UPDATE bots SET name = ?, bot_type_id = ? WHERE id = ?')
                    ->execute(['DeepSeek-Harness', $typeId, $botId]);
            }

            $signature = $database->prepare('SELECT id FROM bot_signatures WHERE bot_id = ?');
            $signature->execute([$botId]);
            $signatureId = $signature->fetchColumn();
            if ($signatureId === false) {
                $database->prepare('INSERT INTO bot_signatures (bot_id, pattern) VALUES (?, ?)')
                    ->execute([$botId, 'deepseek-harness']);
            } else {
                $database->prepare('UPDATE bot_signatures SET pattern = ? WHERE id = ?')
                    ->execute(['deepseek-harness', $signatureId]);
            }
            $database->exec('PRAGMA user_version = 17');
        }
        if ($version < 18) {
            $database->prepare('INSERT INTO companies (name) VALUES (?) ON CONFLICT (name) DO NOTHING')
                ->execute(['Amazon']);
            $company = $database->prepare('SELECT id FROM companies WHERE name = ?');
            $company->execute(['Amazon']);
            $companyId = (int) $company->fetchColumn();
            $types = [];
            foreach ($database->query('SELECT id, name FROM bot_types')->fetchAll() as $type) {
                $types[$type['name']] = (int) $type['id'];
            }
            $bots = [
                'Amazonbot' => 'Внутреннее обучение',
                'Amzn-SearchBot' => 'Поисковые сканеры',
                'Amzn-User' => 'Пользовательские запросы',
            ];
            $findBot = $database->prepare('SELECT id FROM bots WHERE company_id = ? AND name = ?');
            $addBot = $database->prepare('INSERT INTO bots (company_id, bot_type_id, name) VALUES (?, ?, ?)');
            $addSignature = $database->prepare('INSERT INTO bot_signatures (bot_id, pattern) VALUES (?, ?) ON CONFLICT (pattern) DO NOTHING');
            foreach ($bots as $name => $typeName) {
                $findBot->execute([$companyId, $name]);
                $botId = $findBot->fetchColumn();
                if ($botId === false) {
                    $addBot->execute([$companyId, $types[$typeName], $name]);
                    $botId = (int) $database->lastInsertId();
                } else {
                    $botId = (int) $botId;
                    $database->prepare('UPDATE bots SET bot_type_id = ? WHERE id = ?')->execute([$types[$typeName], $botId]);
                }
                $addSignature->execute([$botId, $name]);
            }
            $details = json_decode(file_get_contents(dirname(__DIR__) . '/database/bot-details.json'), true, 512, JSON_THROW_ON_ERROR);
            $updateDetails = $database->prepare("UPDATE bots SET
                description = CASE WHEN description = '' THEN ? ELSE description END,
                website = CASE WHEN website = '' THEN ? ELSE website END,
                note = CASE WHEN note = '' THEN ? ELSE note END,
                ip_source = CASE WHEN ip_source = '' THEN ? ELSE ip_source END
                WHERE company_id = ? AND name = ?");
            foreach ($details['bots'] as $name => $item) {
                if ($item['company'] === 'Amazon') {
                    $updateDetails->execute([$item['description'], $item['website'], $item['note'], $item['ip_source'], $companyId, $name]);
                }
            }
            $database->exec('PRAGMA user_version = 18');
        }
        if ($version < 19) {
            require_once dirname(__DIR__) . '/src/ip.php';
            $snapshot = json_decode(file_get_contents(dirname(__DIR__) . '/database/bot-details.json'), true, 512, JSON_THROW_ON_ERROR);
            $findBot = $database->prepare('SELECT b.id FROM bots b JOIN companies c ON c.id = b.company_id WHERE c.name = ? AND b.name = ?');
            $hasRanges = $database->prepare('SELECT 1 FROM bot_ip_ranges WHERE bot_id = ? LIMIT 1');
            $addRange = $database->prepare('INSERT INTO bot_ip_ranges (bot_id, entry, first_ip, last_ip) VALUES (?, ?, ?, ?)');
            foreach ($snapshot['bots'] as $name => $item) {
                if ($item['company'] !== 'Amazon') {
                    continue;
                }
                $findBot->execute(['Amazon', $name]);
                $botId = $findBot->fetchColumn();
                if ($botId === false) {
                    continue;
                }
                $hasRanges->execute([(int) $botId]);
                if ($hasRanges->fetchColumn() !== false) {
                    continue;
                }
                foreach ($item['ranges'] as $entry) {
                    $addRange->execute([(int) $botId, $entry, ...ipRange($entry)]);
                }
            }
            $database->exec('PRAGMA user_version = 19');
        }
        if ($version < 20) {
            $database->prepare("UPDATE bot_signatures SET pattern = 'deepseek-harness'
                WHERE bot_id = (SELECT b.id FROM bots b JOIN companies c ON c.id = b.company_id
                    WHERE c.name = 'DeepSeek' AND b.name = 'DeepSeek-Harness')
                    AND pattern = 'DeepSeek-Harness'")
                ->execute();
            $database->exec('PRAGMA user_version = 20');
        }
        if ($version < 21) {
            $descriptions = require dirname(__DIR__) . '/database/company-descriptions.php';
            $update = $database->prepare("UPDATE companies SET description = ? WHERE name = ? AND trim(description) = ''");
            foreach ($descriptions as $name => $description) {
                $update->execute([$description, $name]);
            }
            $database->exec('PRAGMA user_version = 21');
        }
        $database->exec('COMMIT');
    } catch (Throwable $error) {
        $database->exec('ROLLBACK');
        throw $error;
    }

    echo "CheckAI SQLite is ready.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
