<?php

declare(strict_types=1);

const WEBHOOK_SECRET_FILE = '/home/main/scripts/repos/secrets/checkai-webhook.secret';
const WEBHOOK_PENDING_FILE = '/home/main/scripts/repos/pending/checkai-webhook.pending';
const WEBHOOK_MAX_PAYLOAD_BYTES = 1048576;
const WEBHOOK_REPOSITORY = 'GregoryRusakov/checkai';
const WEBHOOK_BRANCH = 'refs/heads/main';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Type: text/plain; charset=UTF-8');

function respond(int $status, string $message = ''): never
{
    http_response_code($status);
    if ($message !== '') {
        echo $message . "\n";
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, 'Method not allowed.');
}

$length = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
if ($length !== false && $length !== null && $length > WEBHOOK_MAX_PAYLOAD_BYTES) {
    respond(413, 'Payload too large.');
}

$payload = file_get_contents('php://input', false, null, 0, WEBHOOK_MAX_PAYLOAD_BYTES + 1);
if ($payload === false || strlen($payload) > WEBHOOK_MAX_PAYLOAD_BYTES) {
    respond(413, 'Payload too large.');
}

$secret = @file_get_contents(WEBHOOK_SECRET_FILE);
if ($secret === false) {
    error_log('CheckAI webhook secret is unavailable.');
    respond(503, 'Webhook is not configured.');
}
$secret = rtrim($secret, "\r\n");

$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if ($secret === '' || !preg_match('/^sha256=[a-f0-9]{64}$/iD', $signature)) {
    respond(401, 'Invalid signature.');
}

$expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);
if (!hash_equals($expectedSignature, strtolower($signature))) {
    respond(401, 'Invalid signature.');
}

$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event === 'ping') {
    respond(204);
}
if ($event !== 'push') {
    respond(202, 'Event ignored.');
}

try {
    $data = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    respond(400, 'Invalid JSON payload.');
}
if (!is_array($data)) {
    respond(400, 'Invalid JSON payload.');
}

if (($data['repository']['full_name'] ?? null) !== WEBHOOK_REPOSITORY
    || ($data['ref'] ?? null) !== WEBHOOK_BRANCH) {
    respond(202, 'Repository or branch ignored.');
}

$delivery = $_SERVER['HTTP_X_GITHUB_DELIVERY'] ?? '';
if (!preg_match('/^[A-Za-z0-9-]{1,128}$/D', $delivery)) {
    respond(400, 'Invalid delivery identifier.');
}

$handle = @fopen(WEBHOOK_PENDING_FILE, 'x');
if ($handle === false) {
    if (is_file(WEBHOOK_PENDING_FILE)) {
        respond(202, 'Deployment is already queued.');
    }
    error_log('CheckAI webhook could not create the deployment marker.');
    respond(503, 'Deployment could not be queued.');
}

$written = fwrite($handle, $delivery . "\n");
$closed = fclose($handle);
if ($written === false || !$closed) {
    error_log('CheckAI webhook could not write the deployment marker.');
    respond(503, 'Deployment could not be queued.');
}

respond(202, 'Deployment queued.');
