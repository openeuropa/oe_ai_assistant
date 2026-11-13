<?php

declare(strict_types=1);

namespace Drupal\oe_ai_assistant\Entity;

/**
 * The kinds of entry a session records in its activity.
 *
 * An entry holds a message for an editor to read. Code that groups
 * entries, or renders one kind its own way, matches on the type instead
 * of parsing that message.
 */
enum ActivityType: string {

  // The editor set the tone, or changed it.
  case ToneChanged = 'tone_changed';

  // The editor set the template, or changed it.
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
