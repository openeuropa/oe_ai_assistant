<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Plugin\NeuronAgent;

use Drupal\ai_neuron\Agent\NeuronAgentPluginBase;
use Drupal\ai_neuron\Attribute\NeuronAgent;
use Drupal\ai_neuron\Providers\ProviderFactoryInterface;
use Drupal\ai_neuron\Tools\NeuronToolManagerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionActivityInterface;
use Drupal\oe_ai_assistant\Entity\AiEditorialSessionInterface;
use NeuronAI\UniqueIdGenerator;
use Symfony\Component\Serializer\Encoder\CsvEncoder;

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

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ProviderFactoryInterface $providers,
    NeuronToolManagerInterface $toolManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly DateFormatterInterface $dateFormatter,
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

    The record is the CSV between the BEGIN_ACTIVITY_CSV and END_ACTIVITY_CSV
    markers below, and it holds every entry of the session. Its first row
    reads "time,who,what": the time the entry was recorded, the editor it
    belongs to, and what they did. Any field can be
    wrapped in double quotes, and those wrapping quotes are not part of the
    value. Inside a wrapped field, a doubled double quote is one quote
    character. The rows run oldest first. The editor who asks the question
    is named "you".

    The first row after the column names says what the session was created
    with. It is the state the session started from and not a change, so a
    question that counts changes does not count it. Its values stay in
    force: a value it names holds until a later row changes it, so a
    question about the tone or the template of the session right now is
    answered from the last row that names one, and from the first row when
    no other does.
    INSTRUCTIONS;

    return $instructions . "\n\n" . $this->record() . "\n";
  }

  /**
   * Renders the record the answer comes from.
   *
   * @return string
   *   The entries as CSV between the BEGIN_ACTIVITY_CSV and
   *   END_ACTIVITY_CSV markers, oldest first, or a note that the record is
   *   empty.
   */
  private function record(): string {
    // A long session records hundreds of entries. Each one costs a few
    // tokens as a CSV row, so we read the record without a limit and the
    // agent answers from all of it.
    $entries = $this->session()->getLatestActivity();
    if ($entries === []) {
      return 'The record is empty. Nothing has happened in this session yet.';
    }

    $rows = [];
    foreach ($entries as $entry) {
      $rows[] = [
        'time' => $this->dateFormatter->format($entry->getCreatedTime(), 'short'),
        'who' => $this->actor($entry),
        'what' => $entry->getMessage(),
      ];
    }

    // The encoder writes the header from the keys above and quotes a value
    // that needs it, which these messages do: a tone name reads Tone set to
    // "Formal".
    $csv = (new CsvEncoder())->encode($rows, CsvEncoder::FORMAT);

    return "BEGIN_ACTIVITY_CSV\n" . $csv . 'END_ACTIVITY_CSV';
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

}
