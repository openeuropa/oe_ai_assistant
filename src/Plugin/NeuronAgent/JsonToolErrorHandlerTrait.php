<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronAgent;

use Drupal\oe_ai_assistant\Exception\ToolRefusalException;
use NeuronAI\Tools\ToolCall;

/**
 * Reports a tool failure to the model as JSON.
 *
 * Our tools answer in JSON, so a failure is reported as JSON too, instead
 * of the plain sentence NeuronAgentPluginBase::toolErrorHandler() builds.
 * It names the tool, so a model that called several tools in one turn can
 * see which one failed. The consuming class has to provide getLogger(), as
 * Drupal\Core\Logger\LoggerChannelTrait does.
 */
trait JsonToolErrorHandlerTrait {

  /**
   * {@inheritdoc}
   */
  protected function toolErrorHandler(): ?callable {
    $logger = $this->getLogger('oe_ai_assistant');

    return static function (\Throwable $exception, ToolCall $call) use ($logger): string {
      // A refusal says what the call got wrong, so the model can correct it
      // and call again. It carries a message written for that reader.
      if ($exception instanceof ToolRefusalException) {
        return (string) json_encode([
          'tool' => $call->getName(),
          'error' => $exception->getMessage(),
        ]);
      }

      // Don't return the exception message to the model: a database driver
      // puts the failing query and the host in it, and the agent repeats
      // whatever we return to the editor. It goes to the log instead.
      $logger->error('The @tool tool failed: @message', [
        '@tool' => $call->getName(),
        '@message' => $exception->getMessage(),
        'exception' => $exception,
      ]);

      // json_encode() returns string|false and the handler has to return a
      // string. It can only fail on data it can't encode, and all we encode
      // is a tool name and a fixed sentence, so the cast is safe.
      return (string) json_encode([
        'tool' => $call->getName(),
        'error' => 'The tool failed.',
      ]);
    };
  }

}
