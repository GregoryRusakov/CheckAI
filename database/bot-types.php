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
    'Внутреннее обучение' => ['GPTBot', 'ClaudeBot', 'Bytespider', 'CCBot', 'Amazonbot'],
    'Поисковые сканеры' => ['OAI-SearchBot', 'Claude-SearchBot', 'PerplexityBot', 'Amzn-SearchBot'],
    'Пользовательские запросы' => ['ChatGPT-User', 'Claude-User', 'Perplexity-User', 'DeepSeek-Harness', 'Google-CloudVertexBot', 'Amzn-User'],
    'Прочее' => ['OAI-AdsBot'],
];
