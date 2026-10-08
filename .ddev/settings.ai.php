<?php

/**
 * @file
 * AI provider and document extraction configuration for this installation.
 *
 * Overrides the default AI provider and model settings using
 * environment variables. Set these in .ddev/.env:
 *
 *   AI_PROVIDER=gpt_at_ec (default: gpt_at_ec)
 *   AI_MODEL=gpt-5.1 (default: gpt-5.1)
 *   AI_EMBED_MODEL=gpt-5.1 (default: gpt-5.1)
 *
 * This file is included from settings.php during ddev install.
 */

// Point the Tika document loader at the services of this installation: the
// tika container from .ddev/docker-compose.tika.yaml and the app JAR baked
// into the web image by .ddev/web-build/Dockerfile.tika. Both sources are
// configured, so switching mode between 'server' and 'executable' below and
// running "drush cr" is enough to test either one. The settings form shows
// these three values as overridden and cannot change them.
$config['document_loader_tika.settings']['mode'] = 'server';
$config['document_loader_tika.settings']['url'] = 'http://tika:9998';
$config['document_loader_tika.settings']['jar_path'] = '/usr/local/lib/tika-app.jar';

// Skip AI provider overrides during automated tests. When
// OE_AI_SKIP_PROVIDER_OVERRIDE is set, tests control the provider
// via config API instead. Toggling it in .ddev/.env requires a
// ddev restart.
// @see .ddev/docker-compose.phpunit.yaml
if (getenv('OE_AI_SKIP_PROVIDER_OVERRIDE')) {
  return;
}

// Read provider and model from environment variables,
// falling back to GPT@EC as the default.
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
