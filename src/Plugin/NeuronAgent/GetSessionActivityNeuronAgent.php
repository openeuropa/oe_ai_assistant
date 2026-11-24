<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronAgent;

use Drupal\ai_neuron\Agent\NeuronAgentPluginBase;
use Drupal\ai_neuron\Attribute\NeuronAgent;
use Drupal\ai_neuron\Providers\ProviderFactoryInterface;
use Drupal\ai_neuron\Tools\NeuronToolManagerInterface;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionActivityInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use NeuronAI\UniqueIdGenerator;

/**
 * Answers one question about what happened in an editorial session.
 *
 * The editorial agent re-sends its whole thread on every turn, and Neuron
 * keeps every tool result in that thread. Return the record there and we
 * pay for it on every later request. The record goes in this agent's
 * instructions instead, and the tool returns only the answer.
 */
#[NeuronAgent(
  id: GetSessionActivityNeuronAgent::PLUGIN_ID,
  label: new TranslatableMarkup('Session activity'),
  description: new TranslatableMarkup('Answers one question about what happened in an editorial session.'),
  operation_type: 'chat',
  context_definitions: [
    'session' => new EntityContextDefinition(
      data_type: 'entity:ai_editorial_session',
      label: new TranslatableMarkup('Editorial session'),
    ),
  ],
)]
final class GetSessionActivityNeuronAgent extends NeuronAgentPluginBase {

  /**
   * The id this agent is registered under.
   */
  public const string PLUGIN_ID = 'get_session_activity';

  /**
   * How many of the most recent entries the prompt carries.
   *
   * The record grows for as long as the editor works in the session, so an
   * uncapped prompt would grow with it. The prompt carries the most recent
   * entries and says how many it does not show.
   */
  public const int MAX_ENTRIES = 100;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ProviderFactoryInterface $providers,
    NeuronToolManagerInterface $toolManager,
    private readonly AccountProxyInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $providers, $toolManager);
  }

  /**
   * {@inheritdoc}
   *
   * The record is the only thing this agent answers from, so it goes in the
   * instructions. GetSessionActivityNeuronTool sends the question as the
   * user message.
   */
  protected function instructions(): string {
    $instructions = <<<'INSTRUCTIONS'
    You answer one question about what happened in one editorial session.

    Answer from the record below and from nothing else. You answer another
    agent and not the editor, so write no greeting, no apology and no offer
    of more help.

    Be precise, and report every value the record holds for what you answer:
    the tone and the template by name, including the one they replaced, the
    document by its file name, the draft by its number, the node by its id,
    who did it, and when. An answer that reports a change without its values
    is incomplete. Say how many times something happened when the question
    counts, and name each occurrence.

    Never guess a time or an order of events. Say that the record does not
    hold the answer when it does not.

    Each line of the record gives the time in UTC, who did it, and what they
    did. The editor who asks the question is named "you".

    The first line of the record says what the session was created with. It
    is the state the session started from and not a change, so a question
    that counts changes does not count it. It does hold: a value it names
    stays in force until a later line changes it, so a question about the
    tone or the template of the session right now is answered from the last
    line that names one, and from the first line when no other does.
    INSTRUCTIONS;

    return $instructions . "\n\n" . $this->record() . "\n";
  }

  /**
   * Renders the record the answer comes from.
   *
   * @return string
   *   The entries, oldest first, or a note that the record holds none.
   */
  private function record(): string {
    $entries = $this->session()->getLatestActivity(self::MAX_ENTRIES);
    if ($entries === []) {
      return 'The record is empty. Nothing has happened in this session yet.';
    }

    $lines = ['The record, oldest first.'];
    $omitted = $this->omitted(count($entries));
    if ($omitted > 0) {
      $lines[] = sprintf('The %d entries before the first one below are not shown.', $omitted);
    }
    $lines[] = '';

    foreach ($entries as $entry) {
      // Drupal formats dates in the timezone of the account that asks, so
      // date() would give two editors different times for the same entry.
      // The instructions above promise UTC, so use gmdate().
      $lines[] = sprintf(
        '%s | %s | %s',
        gmdate('c', $entry->getCreatedTime()),
        $this->actor($entry),
        $entry->getMessage(),
      );
    }

    return implode("\n", $lines);
  }

  /**
   * {@inheritdoc}
   *
   * A reused thread would replay the record we sent with the last question,
   * which is the cost this agent exists to avoid. Every run opens a
   * conversation of its own. The session id is in the key as well, so a
   * reader of the stored conversations can tell which session a run is for.
   */
  protected function threadKey(): string {
    return 'session_' . $this->session()->id()
      . '.activity.' . UniqueIdGenerator::generateUUID();
  }

  /**
   * The session this run answers for.
   *
   * @return \Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface
   *   The session.
   */
  private function session(): AiEditorialSessionInterface {
    $session = $this->getContextValue('session');
    assert($session instanceof AiEditorialSessionInterface);

    return $session;
  }

  /**
   * Names the editor an entry belongs to.
   *
   * An editor asks what they did themselves, and a name alone does not tell
   * the model which name is theirs. The editor asking reads as "you", and
   * everyone else under the name the CMS shows.
   *
   * @param \Drupal\oe_ai_assistant\Entity\AiEditorialSessionActivityInterface $entry
   *   The entry to name the author of.
   *
   * @return string
   *   The name the prompt gives that author.
   */
  private function actor(AiEditorialSessionActivityInterface $entry): string {
    if ((int) $entry->getOwnerId() === (int) $this->currentUser->id()) {
      return 'you';
    }

    return (string) ($entry->getOwner()?->getDisplayName() ?? 'a deleted account');
  }

  /**
   * Counts the entries the prompt does not show.
   *
   * @param int $shown
   *   How many entries the prompt carries.
   *
   * @return int
   *   How many older entries the prompt does not show.
   */
  private function omitted(int $shown): int {
    // Fewer entries than the cap means we read the whole record, so skip
    // the count query a capped prompt needs.
    if ($shown < self::MAX_ENTRIES) {
      return 0;
    }

    return max(0, $this->session()->countActivity() - $shown);
  }

}
