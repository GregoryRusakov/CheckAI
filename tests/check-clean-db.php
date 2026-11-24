<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/database.php';

$database = openDatabase();
$version = (int) $database->query('PRAGMA user_version')->fetchColumn();
if ($version !== 21) {
    throw new RuntimeException("Expected SQLite schema version 21, got $version.");
}

$logRoot = $database->query('SELECT log_root FROM settings WHERE id = 1')->fetchColumn();
if ($logRoot !== '/var/logs') {
    throw new RuntimeException('Fresh database has an unexpected log root.');
}

$findCompany = $database->prepare('SELECT description FROM companies WHERE name = ?');
foreach (['Amazon', 'Anthropic', 'ByteDance', 'Common Crawl', 'DeepSeek', 'Google', 'OpenAI', 'Perplexity'] as $name) {
    $findCompany->execute([$name]);
    $description = $findCompany->fetchColumn();
    if (!is_string($description) || trim($description) === '') {
        throw new RuntimeException("Fresh database is missing the description for $name.");
    }
}

$botCount = (int) $database->query('SELECT COUNT(*) FROM bots')->fetchColumn();
$userCount = (int) $database->query('SELECT COUNT(*) FROM users')->fetchColumn();
$signatureCount = (int) $database->query('SELECT COUNT(*) FROM bot_signatures')->fetchColumn();
if ($botCount === 0 || $signatureCount === 0 || $userCount !== 0) {
    throw new RuntimeException('Fresh database is missing its bot catalog or contains user data.');
}

echo "Fresh SQLite database is valid ($botCount bots, $signatureCount signatures).\n";
