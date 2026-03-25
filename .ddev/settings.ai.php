<?php

/**
 * @file
 * AI provider configuration via environment variables.
 *
 * Overrides the default AI provider and model settings using
 * environment variables. Set these in .ddev/.env:
 *
 *   AI_PROVIDER=openai          (default: mistral)
 *   AI_MODEL=gpt-4o             (default: mistral-large-latest)
 *
 * This file is included from settings.php during ddev install.
 */

// Read provider and model from environment variables,
// falling back to Mistral as the default.
$ai_provider = getenv('AI_PROVIDER') ?: 'mistral';
$ai_model = getenv('AI_MODEL') ?: 'mistral-large-latest';
$ai_embed_model = getenv('AI_EMBED_MODEL') ?: 'mistral-embed';

// Set the default provider for all AI operation types.
$config['ai.settings']['default_providers'] = [
  'chat' => [
    'provider_id' => $ai_provider,
    'model_id' => $ai_model,
  ],
  'chat_with_complex_json' => [
    'provider_id' => $ai_provider,
    'model_id' => $ai_model,
  ],
  'chat_with_image_vision' => [
    'provider_id' => $ai_provider,
    'model_id' => $ai_model,
  ],
  'chat_with_structured_response' => [
    'provider_id' => $ai_provider,
    'model_id' => $ai_model,
  ],
  'chat_with_tools' => [
    'provider_id' => $ai_provider,
    'model_id' => $ai_model,
  ],
  'embeddings' => [
    'provider_id' => $ai_provider,
    'model_id' => $ai_embed_model,
  ],
];
