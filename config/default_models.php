<?php

declare(strict_types=1);

/**
 * Default model pricing shipped with the bundle.
 * Users can override any model in their lunetics_llm_cost_tracking.yaml config.
 *
 * Prices are in USD per 1 million tokens (as of February 2026).
 */
return [
    // Anthropic
    'claude-sonnet-4-6' => [
        'display_name' => 'Claude Sonnet 4.6',
        'provider' => 'Anthropic',
        'input_price_per_million' => 3.00,
        'output_price_per_million' => 15.00,
        'cached_input_price_per_million' => 0.30,
        'thinking_price_per_million' => 15.00,
    ],
    'claude-opus-4-6' => [
        'display_name' => 'Claude Opus 4.6',
        'provider' => 'Anthropic',
        'input_price_per_million' => 5.00,
        'output_price_per_million' => 25.00,
        'cached_input_price_per_million' => 0.50,
        'thinking_price_per_million' => 25.00,
    ],

    // OpenAI
    'gpt-5' => [
        'display_name' => 'GPT-5',
        'provider' => 'OpenAI',
        'input_price_per_million' => 1.25,
        'output_price_per_million' => 10.00,
    ],
    'gpt-5-mini' => [
        'display_name' => 'GPT-5 Mini',
        'provider' => 'OpenAI',
        'input_price_per_million' => 0.25,
        'output_price_per_million' => 2.00,
    ],
    'gpt-4o-mini' => [
        'display_name' => 'GPT-4o Mini',
        'provider' => 'OpenAI',
        'input_price_per_million' => 0.15,
        'output_price_per_million' => 0.60,
    ],
    'gpt-4.1-mini' => [
        'display_name' => 'GPT-4.1 Mini',
        'provider' => 'OpenAI',
        'input_price_per_million' => 0.40,
        'output_price_per_million' => 1.60,
    ],

    // Google
    'gemini-2.5-flash' => [
        'display_name' => 'Gemini 2.5 Flash',
        'provider' => 'Google',
        'input_price_per_million' => 0.30,
        'output_price_per_million' => 2.50,
        'thinking_price_per_million' => 2.50,
    ],
    'gemini-3-flash-preview' => [
        'display_name' => 'Gemini 3 Flash',
        'provider' => 'Google',
        'input_price_per_million' => 0.50,
        'output_price_per_million' => 3.00,
    ],
    'gemini-2.5-pro' => [
        'display_name' => 'Gemini 2.5 Pro',
        'provider' => 'Google',
        'input_price_per_million' => 1.25,
        'output_price_per_million' => 10.00,
        'thinking_price_per_million' => 10.00,
    ],
    'gemini-3-pro-preview' => [
        'display_name' => 'Gemini 3 Pro',
        'provider' => 'Google',
        'input_price_per_million' => 2.00,
        'output_price_per_million' => 12.00,
    ],
];
