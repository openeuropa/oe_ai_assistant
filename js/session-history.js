/**
 * @file
 * Expand/collapse behavior for the session conversation history table.
 *
 * The server renders every message row and detail row visible so the page
 * stays fully readable without JavaScript. On attach this behavior collapses
 * the table to its default state (top-level turns visible, children and
 * detail rows hidden) and reveals the bulk toolbar, which is useless without
 * JavaScript and therefore server-rendered hidden.
 *
 * A message row is itself the toggle for its detail row (click, or Enter or
 * Space while focused); the caret button nested in the role cell intercepts
 * the click and toggles the subtree instead.
 */
(function (Drupal, once) {

  'use strict';

  /**
   * Returns the direct child message rows of a message.
   */
  function childRows(table, id) {
    return table.querySelectorAll('tr.ai-history-message[data-parent-id="' + id + '"]');
  }

  /**
   * Returns the detail row of a message, or null.
   */
  function detailRow(table, id) {
    return table.querySelector('tr.ai-history-detail[data-detail-for="' + id + '"]');
  }

  /**
   * Opens or closes a message row's detail row.
   */
  function setDetail(table, row, open) {
    var detail = detailRow(table, row.dataset.messageId);
    if (!detail) {
      return;
    }
    detail.hidden = !open;
    row.setAttribute('aria-expanded', open ? 'true' : 'false');
    // Tint the open pair so the inspected row stands out.
    row.classList.toggle('ai-history-inspected', open);
    detail.classList.toggle('ai-history-inspected', open);
  }

  /**
   * Hides a message's whole subtree and resets its toggles to collapsed.
   *
   * The message's own rows are left alone: collapsing a turn must not hide
   * that turn's detail row, only everything below it.
   */
  function collapseSubtree(table, id) {
    childRows(table, id).forEach(function (row) {
      var childId = row.dataset.messageId;
      var detail = detailRow(table, childId);
      row.hidden = true;
      row.setAttribute('aria-expanded', 'false');
      row.classList.remove('ai-history-inspected');
      if (detail) {
        detail.hidden = true;
        detail.classList.remove('ai-history-inspected');
      }
      row.querySelectorAll('button[aria-expanded]').forEach(function (button) {
        button.setAttribute('aria-expanded', 'false');
      });
      collapseSubtree(table, childId);
    });
  }

  /**
   * Reveals every message row at every depth, carets included.
   *
   * Detail rows keep their current state; only the tree opens up.
   */
  function expandAll(table) {
    table.querySelectorAll('tr.ai-history-message').forEach(function (row) {
      row.hidden = false;
    });
    table.querySelectorAll('button.ai-history-toggle-children').forEach(function (button) {
      button.setAttribute('aria-expanded', 'true');
    });
  }

  /**
   * Collapses every subtree back to the top-level turns.
   *
   * Mirrors the per-row collapse: hidden rows also lose their open detail
   * and highlight, while top-level rows keep theirs.
   */
  function collapseAll(table) {
    table.querySelectorAll('tr.ai-history-message:not([data-parent-id=""])').forEach(function (row) {
      var detail = detailRow(table, row.dataset.messageId);
      row.hidden = true;
      row.setAttribute('aria-expanded', 'false');
      row.classList.remove('ai-history-inspected');
      if (detail) {
        detail.hidden = true;
        detail.classList.remove('ai-history-inspected');
      }
      row.querySelectorAll('button[aria-expanded]').forEach(function (button) {
        button.setAttribute('aria-expanded', 'false');
      });
    });
    table.querySelectorAll('button.ai-history-toggle-children').forEach(function (button) {
      button.setAttribute('aria-expanded', 'false');
    });
  }

  /**
   * Opens the detail row of every visible message row.
   *
   * Details of rows hidden inside a collapsed subtree stay hidden, exactly
   * as if each visible row had been clicked open.
   */
  function showAllDetails(table) {
    table.querySelectorAll('tr.ai-history-message:not([hidden])').forEach(function (row) {
      setDetail(table, row, true);
    });
  }

  /**
   * Closes every detail row and clears every highlight.
   */
  function hideAllDetails(table) {
    table.querySelectorAll('tr.ai-history-message').forEach(function (row) {
      setDetail(table, row, false);
    });
  }

  /**
   * Handles activation of a message row or its nested caret.
   */
  function onRowActivate(table, event) {
    var caret = event.target.closest('button.ai-history-toggle-children');
    if (caret) {
      var caretRow = caret.closest('tr');
      var expanded = caret.getAttribute('aria-expanded') === 'true';
      caret.setAttribute('aria-expanded', expanded ? 'false' : 'true');
      if (expanded) {
        collapseSubtree(table, caretRow.dataset.messageId);
      }
      else {
        // Reveal direct children only, in collapsed state; deeper levels
        // and their details stay hidden.
        childRows(table, caretRow.dataset.messageId).forEach(function (child) {
          child.hidden = false;
        });
      }
      return;
    }

    // A click while text is selected is a copy gesture, not a toggle.
    var selection = window.getSelection();
    if (selection && selection.toString() !== '') {
      return;
    }

    var row = event.target.closest('tr.ai-history-message');
    if (row) {
      setDetail(table, row, row.getAttribute('aria-expanded') !== 'true');
    }
  }

  Drupal.behaviors.aiSessionHistory = {
    attach: function (context) {
      once('ai-session-history', '.ai-history', context).forEach(function (wrapper) {
        var table = wrapper.querySelector('table.ai-history-table');
        var toolbar = wrapper.querySelector('.ai-history-toolbar');
        if (!table) {
          return;
        }

        // Collapse to the default state: only top-level turns visible, all
        // detail rows hidden, every toggle reported as collapsed.
        table.querySelectorAll('tr.ai-history-message:not([data-parent-id=""])').forEach(function (row) {
          row.hidden = true;
        });
        table.querySelectorAll('tr.ai-history-detail').forEach(function (row) {
          row.hidden = true;
        });
        table.querySelectorAll('tr.ai-history-message, button[aria-expanded]').forEach(function (element) {
          element.setAttribute('aria-expanded', 'false');
        });

        // One delegated listener serves every row and caret in the table;
        // Enter and Space activate the focused row like a click.
        table.addEventListener('click', function (event) {
          onRowActivate(table, event);
        });
        table.addEventListener('keydown', function (event) {
          if ((event.key === 'Enter' || event.key === ' ') && event.target.matches('tr.ai-history-message')) {
            event.preventDefault();
            onRowActivate(table, event);
          }
        });

        if (!toolbar) {
          return;
        }
        // The toolbar only works with JS, so it is server-rendered hidden.
        toolbar.hidden = false;

        // The bulk buttons are command toggles: each flips its own state
        // and label, then applies it to the whole table.
        toolbar.addEventListener('click', function (event) {
          var button = event.target.closest('button[aria-expanded]');
          if (!button) {
            return;
          }
          var expand = button.getAttribute('aria-expanded') !== 'true';
          button.setAttribute('aria-expanded', expand ? 'true' : 'false');
          button.textContent = expand ? button.dataset.labelHide : button.dataset.labelShow;

          if (button.classList.contains('ai-history-expand-all')) {
            if (expand) {
              expandAll(table);
            }
            else {
              collapseAll(table);
            }
          }
          if (button.classList.contains('ai-history-details-all')) {
            if (expand) {
              showAllDetails(table);
            }
            else {
              hideAllDetails(table);
            }
          }
        });
      });
    }
  };

})(Drupal, once);
