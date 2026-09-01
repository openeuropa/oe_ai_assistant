<?php

/**
 * @file
 * AI provider configuration via .ddev/.env.
 *
 * Overrides the default AI provider and model settings using
 * variables read directly from the .ddev/.env file:
 *
 *   AI_PROVIDER=gpt_at_ec (default: gpt_at_ec)
 *   AI_MODEL=gpt-5.1 (default: gpt-5.1)
 *   AI_EMBED_MODEL=gpt-5.1 (default: gpt-5.1)
 *   OE_AI_PROVIDER_OVERRIDE=1
 *
 * The .env file is parsed on every request, so changes take effect
 * without restarting the containers.
 *
 * The overrides apply only when OE_AI_PROVIDER_OVERRIDE is set.
 * Leave it unset so automated tests can set the provider via the
 * config API.
 *
 * This file is included from settings.php during ddev install.
 */

use Symfony\Component\Dotenv\Dotenv;

// Parse .ddev/.env into an array without touching the real
// environment. The project root is one level above the docroot.
$oe_ai_env = [];
$oe_ai_env_file = DRUPAL_ROOT . '/../.ddev/.env';
if (is_readable($oe_ai_env_file)) {
  $oe_ai_env = (new Dotenv())->parse(file_get_contents($oe_ai_env_file));
}

// Apply the overrides only when explicitly enabled in .ddev/.env.
if (!empty($oe_ai_env['OE_AI_PROVIDER_OVERRIDE'])) {
  // Read provider and model from the .env values, falling back to
  // GPT@EC as the default.
  $ai_provider = ($oe_ai_env['AI_PROVIDER'] ?? '') ?: 'gpt_at_ec';
  $ai_model = ($oe_ai_env['AI_MODEL'] ?? '') ?: 'gpt-5.1';
  $ai_embed_model = ($oe_ai_env['AI_EMBED_MODEL'] ?? '') ?: 'gpt-5.1';

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
