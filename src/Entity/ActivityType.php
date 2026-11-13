<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity;

/**
 * The kinds of entry a session records in its activity.
 *
 * An entry carries a sentence an editor reads and a type a program reads.
 * A reader that groups entries, or renders one of them its own way, keys
 * on the type and never parses the sentence.
 */
enum ActivityType: string {

  // The editor picked a tone, or picked a different one.
  case ToneChanged = 'tone_changed';

  // The editor picked a template, or picked a different one.
  case TemplateChanged = 'template_changed';

  // The editor attached a document to the session.
  case DocumentAdded = 'document_added';

  // The editor removed a document from the session.
  case DocumentRemoved = 'document_removed';

  // A drafting run wrote a draft.
  case DraftGenerated = 'draft_generated';

  // A drafting run stopped before it wrote every group.
  case DraftFailed = 'draft_failed';

  // The editor saved a draft to the CMS.
  case DraftSaved = 'draft_saved';

}
