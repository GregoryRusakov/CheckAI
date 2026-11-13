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

return [
    'OpenAI' => ['GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'OAI-AdsBot'],
    'Anthropic' => ['ClaudeBot', 'Claude-SearchBot', 'Claude-User'],
    'Perplexity' => ['PerplexityBot', 'Perplexity-User'],
    'DeepSeek' => ['DeepSeek-Harness'],
    'Google' => ['Google-CloudVertexBot'],
    'Amazon' => ['Amazonbot', 'Amzn-SearchBot', 'Amzn-User'],
    'ByteDance' => ['Bytespider'],
    'Common Crawl' => ['CCBot'],
];
