<?php

/**
 * @file
 * AI provider configuration via environment variables.
 *
 * Overrides the default AI provider and model settings using
 * environment variables. Set these in .ddev/.env:
 *
 *   AI_PROVIDER=EC              (default: gpt_at_ec)
 *   AI_MODEL=gpt-5.1            (default: gpt-5.1)
 *
 * This file is included from settings.php during ddev install.
 */

// Skip AI provider overrides during automated tests. When
// OE_AI_SKIP_PROVIDER_OVERRIDE is set, tests control the provider
// via config API instead.
// @see .ddev/docker-compose.phpunit.yaml
if (!getenv('OE_AI_SKIP_PROVIDER_OVERRIDE')) {
  // Read provider and model from environment variables,
  // falling back to Mistral as the default.
  $ai_provider = getenv('AI_PROVIDER') ?: 'gpt_at_ec';
  $ai_model = getenv('AI_MODEL') ?: 'gpt-5.1';
  $ai_embed_model = getenv('AI_EMBED_MODEL') ?: 'gpt-5.1';

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
}
