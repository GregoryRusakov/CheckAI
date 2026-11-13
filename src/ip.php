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

function ipKey(string $address): ?string
{
    $binary = @inet_pton($address);
    return $binary === false ? null : bin2hex($binary);
}

function ipRange(string $entry): array
{
    $parts = preg_split('~\s*([/-])\s*~', $entry, 2, PREG_SPLIT_DELIM_CAPTURE);
    $first = ipKey($parts[0]);
    if ($first !== null && count($parts) === 1) {
        return [$first, $first];
    }
    if ($first !== null && $parts[1] === '-') {
        $last = ipKey($parts[2]);
        if ($last !== null && strlen($first) === strlen($last) && strcmp($first, $last) <= 0) {
            return [$first, $last];
        }
    }
    if ($first !== null && $parts[1] === '/' && ctype_digit($parts[2]) && (int) $parts[2] <= strlen($first) * 4) {
        $min = $max = '';
        $prefix = (int) $parts[2];
        foreach (str_split($first) as $index => $digit) {
            $bits = max(0, min(4, $prefix - $index * 4));
            $mask = (15 << (4 - $bits)) & 15;
            $min .= dechex(hexdec($digit) & $mask);
            $max .= dechex((hexdec($digit) & $mask) | (15 ^ $mask));
        }
        return [$min, $max];
    }
    throw new InvalidArgumentException('Некорректный IP-адрес или диапазон: ' . $entry);
}

function fetchIpRangesFromJson(string $url): array
{
    $parts = parse_url($url);
    $host = is_array($parts) ? ($parts['host'] ?? '') : '';
    if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || $host === ''
        || isset($parts['user']) || isset($parts['pass'])) {
        throw new InvalidArgumentException('Источник IP-адресов должен быть HTTP(S)-адресом JSON.');
    }

    $records = filter_var($host, FILTER_VALIDATE_IP)
        ? [['ip' => $host]]
        : array_merge(dns_get_record($host, DNS_A) ?: [], dns_get_record($host, DNS_AAAA) ?: []);
    $addresses = array_map(static fn (array $record): string => (string) ($record['ip'] ?? $record['ipv6'] ?? ''), $records);
    $addresses = array_values(array_filter($addresses, static fn ($address): bool => is_string($address) && $address !== ''));
    if (!$addresses) {
        throw new InvalidArgumentException('Не удалось определить адрес источника IP.');
    }
    foreach ($addresses as $address) {
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new InvalidArgumentException('Источник IP-адресов должен находиться на общедоступном сервере.');
        }
    }

    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'timeout' => 10,
        'follow_location' => 0,
        'max_redirects' => 0,
        'ignore_errors' => true,
        'header' => "Accept: application/json\r\nUser-Agent: CheckAI IP updater\r\n",
    ]]);
    $stream = @fopen($url, 'rb', false, $context);
    if ($stream === false) {
        throw new InvalidArgumentException('Не удалось загрузить источник IP-адресов.');
    }
    try {
        $body = stream_get_contents($stream, 1048577);
        $responseHeaders = $http_response_header ?? [];
    } finally {
        fclose($stream);
    }
    if (!is_string($body) || strlen($body) > 1048576) {
        throw new InvalidArgumentException('Источник IP-адресов вернул слишком большой файл.');
    }
    $status = 0;
    foreach ($responseHeaders as $header) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $match)) {
            $status = (int) $match[1];
        }
    }
    if ($status < 200 || $status >= 300) {
        throw new InvalidArgumentException('Источник IP-адресов вернул ошибку HTTP ' . $status . '.');
    }

    try {
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $payload = decodeEmbeddedJson($body);
    }

    $entries = [];
    $collect = static function (mixed $value) use (&$collect, &$entries): void {
        if (is_string($value)) {
            $entry = trim($value);
            if ($entry === '' || strlen($entry) > 128) {
                return;
            }
            try {
                ipRange($entry);
                $entries[$entry] = true;
            } catch (InvalidArgumentException) {
            }
            return;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $collect($item);
            }
        }
    };
    $collect($payload);
    if (!$entries) {
        throw new InvalidArgumentException('В источнике не найдено IP-адресов или диапазонов.');
    }

    return array_keys($entries);
}

function decodeEmbeddedJson(string $body): array
{
    $text = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $marker = strpos($text, '"creationTime"');
    $start = $marker === false ? false : strrpos(substr($text, 0, $marker), '{');
    if ($start === false) {
        throw new InvalidArgumentException('Источник IP-адресов содержит некорректный JSON.');
    }

    $depth = 0;
    $inString = false;
    $escaped = false;
    for ($index = $start, $length = strlen($text); $index < $length; $index++) {
        $character = $text[$index];
        if ($inString) {
            if ($escaped) {
                $escaped = false;
            } elseif ($character === '\\') {
                $escaped = true;
            } elseif ($character === '"') {
                $inString = false;
            }
            continue;
        }
        if ($character === '"') {
            $inString = true;
        } elseif ($character === '{') {
            $depth++;
        } elseif ($character === '}' && --$depth === 0) {
            try {
                $payload = json_decode(substr($text, $start, $index - $start + 1), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                break;
            }
            if (is_array($payload) && isset($payload['prefixes']) && is_array($payload['prefixes'])) {
                return $payload;
            }
            break;
        }
    }
    throw new InvalidArgumentException('Источник IP-адресов содержит некорректный JSON.');
}
