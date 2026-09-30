/**
 * CBD WYSIWYG — upgrade rich .cbd-form textareas into Quill editors.
 *
 * Ported from the WorkSpace theme's Quill integration. Each eligible textarea is
 * hidden and replaced with a Quill "snow" editor; the editor's HTML is mirrored
 * back into the original textarea on every change, so the existing
 * serialize()/FormData submit paths send HTML with no other code changes. The
 * server already stores these fields with wp_kses_post and renders them through
 * the_content()/wpautop(), so nothing server-side changes for formatting.
 *
 * Opt-out: add data-no-wysiwyg to a textarea, or a name in EXCLUDE_NAMES. Fields
 * whose handler strips HTML (reviews) MUST stay plain — they keep the emoji
 * picker via assets/js/emoji-picker.js. Rich editors get their own emoji button.
 *
 * If Quill fails to load (e.g. CDN blocked) this is a no-op and textareas stay
 * plain — nothing breaks.
 */
(function ($) {
  'use strict';

  if (typeof Quill === 'undefined') return; // CDN blocked → leave textareas plain.

  // Server handler strips HTML for these — never richify them.
  var EXCLUDE_NAMES = { review_content: 1 };

  // Minimal inline formatting that renders as plain HTML on the public page:
  // bold/italic/underline/strike + link + ordered/bullet lists. (No header,
  // blockquote, image or clean buttons — and pasted content is stripped to plain
  // text below, so only these styles can ever appear. Manually-added links use
  // Quill's link format and survive wp_kses_post on save / the_content on render.)
  var TOOLBAR = [
    ['bold', 'italic', 'underline', 'strike', 'link'],
    [{ list: 'ordered' }, { list: 'bullet' }]
  ];

  // Quill emits rgb() colours; convert to hex so they survive wp_kses safe-CSS.
  function rgbToHex(html) {
    return html.replace(/rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)/g, function (_, r, g, b) {
      return '#' + [r, g, b].map(function (x) {
        var h = parseInt(x, 10).toString(16);
        return h.length === 1 ? '0' + h : h;
      }).join('');
    });
  }

  function isEmpty(html) {
    return !html || !html.replace(/<p><br><\/p>/g, '').replace(/<[^>]*>/g, '').trim();
  }

  function shouldSkip(ta) {
    if (!ta || ta.dataset.cbdWysiwyg) return true;
    if (ta.getAttribute('data-no-wysiwyg') !== null) return true;
    if (ta.name && EXCLUDE_NAMES[ta.name]) return true;
    return false;
  }

  // Toolbar image button → upload via AJAX, insert the returned URL.
  function uploadImage(quill) {
    var input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.onchange = function () {
      var file = input.files && input.files[0];
      if (!file) return;
      var fd = new FormData();
      fd.append('image', file);
      fd.append('action', 'cbd_upload_editor_image');
      fd.append('cbd_nonce', cbdData.nonce);
      $.ajax({ url: cbdData.ajaxUrl, type: 'POST', data: fd, processData: false, contentType: false })
        .done(function (res) {
          if (res && res.success && res.data && res.data.url) {
            var range = quill.getSelection(true) || { index: quill.getLength() };
            quill.insertEmbed(range.index, 'image', res.data.url, 'user');
            quill.setSelection(range.index + 1, 'silent');
          } else {
            window.alert((res && res.data && res.data.message) || (cbdData.i18n && cbdData.i18n.error) || 'Upload failed.');
          }
        })
        .fail(function () { window.alert((cbdData.i18n && cbdData.i18n.error) || 'Upload failed.'); });
    };
    input.click();
  }

  // Add an emoji button to the Quill toolbar, reusing the shared WSEmojiPicker.
  function addEmojiButton(quill, $container) {
    if (typeof window.WSEmojiPicker === 'undefined') return;
    var $toolbar = $container.prev('.ql-toolbar');
    if (!$toolbar.length) return;
    var $btn = $('<span class="ql-formats"><button type="button" class="cbd-ql-emoji" aria-label="Insert emoji" title="Emoji">😊</button></span>');
    $toolbar.append($btn);

    // Track the editor's last real caret position. Opening the picker blurs the
    // editor, and inserting with the 'silent' source does not fire a (non-silent)
    // selection-change — so without our own tracker, a second emoji is inserted
    // at the pre-insert index, making the caret jump backwards. We keep lastRange
    // in sync ourselves after each insert.
    var lastRange = null;
    quill.on('selection-change', function (range) {
      if (range) lastRange = range;
    });

    $btn.find('button').on('click', function (e) {
      e.preventDefault();
      var trigger = this;
      var picker = new window.WSEmojiPicker({
        onSelect: function (data) {
          var index = lastRange ? lastRange.index : quill.getLength();
          if (lastRange && lastRange.length) {
            quill.deleteText(index, lastRange.length, 'user'); // replace selection
          }
          if (data && data.glyph) {
            quill.insertText(index, data.glyph, 'user');
            index += data.glyph.length;
          } else if (data && data.url) {
            quill.insertEmbed(index, 'image', data.url, 'user');
            index += 1;
          }
          quill.setSelection(index, 'silent');
          lastRange = { index: index, length: 0 }; // keep tracker ahead of the glyph
        }
      });
      picker.open({ trigger: trigger, target: null });
    });
  }

  function convert(ta) {
    if (shouldSkip(ta)) return;
    var $ta = $(ta);

    // The emoji picker may have wrapped this textarea first (load-order race).
    // Undo that — the rich editor provides its own emoji button.
    if ($ta.parent().hasClass('cbd-emoji-field')) {
      $ta.parent().find('.cbd-emoji-trigger').remove();
      $ta.unwrap();
    }

    ta.dataset.cbdWysiwyg = '1';
    ta.dataset.cbdEmoji = '1'; // also blocks the textarea emoji auto-attach.

    // A hidden *required* textarea makes the browser abort submit with a
    // non-focusable-control error and no visible message. Drop native required
    // and re-validate ourselves on submit (see the capture-phase listener).
    if (ta.required) {
      ta.required = false;
      ta.dataset.cbdRequired = '1';
    }

    var initial = ta.value || '';
    var $wrap = $('<div class="cbd-wysiwyg"></div>');
    var $editor = $('<div class="cbd-wysiwyg-editor"></div>').appendTo($wrap);
    $ta.attr('hidden', true).css('display', 'none').after($wrap);

    var quill = new Quill($editor[0], {
      theme: 'snow',
      placeholder: ta.getAttribute('placeholder') || '',
      modules: { toolbar: TOOLBAR }
    });

    if (!isEmpty(initial)) {
      quill.clipboard.dangerouslyPasteHTML(0, initial, 'silent');
    }

    // Strip all formatting from pasted content — keep text and line breaks only.
    // Registered AFTER the seed above so existing (edit-form) content keeps its
    // formatting; this matcher only affects subsequent user pastes. Drops embeds
    // (images) and every inline/line attribute (bold, colour, headings, etc.).
    quill.clipboard.addMatcher(Node.ELEMENT_NODE, function (node, delta) {
      delta.ops = (delta.ops || []).reduce(function (ops, op) {
        if (typeof op.insert === 'string') {
          ops.push({ insert: op.insert }); // text only — no attributes.
        }
        return ops;
      }, []);
      return delta;
    });

    addEmojiButton(quill, $editor);

    function sync() {
      var html = rgbToHex(quill.root.innerHTML);
      ta.value = isEmpty(html) ? '' : html;
    }
    quill.on('text-change', sync);
    sync();

    $ta.data('cbdQuill', quill);

    // If the editor is created inside a form that starts locked (the read-only
    // "Page Profile" tab), disable it immediately — otherwise the contenteditable
    // stays focusable/editable until the lock toggle runs. The lock toggle
    // (frontend.js cbdLockForm) re-enables it when "Edit Page" is clicked.
    if ($ta.closest('form.is-locked').length) {
      quill.enable(false);
    }
  }

  function scan(scope) {
    $(scope || document).find('.cbd-form textarea').each(function () { convert(this); });
  }

  // Re-validate required rich fields before the AJAX submit handlers run. Capture
  // phase + stopImmediatePropagation lets us block the (bubble-phase) handlers.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.matches || !form.matches('.cbd-form')) return;
    var bad = null;
    $(form).find('textarea[data-cbd-required="1"]').each(function () {
      if (!bad && isEmpty(this.value)) bad = this;
    });
    if (bad) {
      e.preventDefault();
      e.stopImmediatePropagation();
      var q = $(bad).data('cbdQuill');
      if (q) q.focus();
      var $wrap = $(bad).next('.cbd-wysiwyg').addClass('cbd-wysiwyg-invalid');
      setTimeout(function () { $wrap.removeClass('cbd-wysiwyg-invalid'); }, 2000);
    }
  }, true);

  // When a form resets (cbdSubmitForm calls form.reset() on success), re-seed each
  // editor from the textarea's restored default value (empty for create forms,
  // original content for edit forms).
  document.addEventListener('reset', function (e) {
    var form = e.target;
    if (!form || !form.matches || !form.matches('.cbd-form')) return;
    setTimeout(function () {
      $(form).find('textarea[data-cbd-wysiwyg]').each(function () {
        var q = $(this).data('cbdQuill');
        if (!q) return;
        q.setContents([]);
        if (!isEmpty(this.value)) {
          q.clipboard.dangerouslyPasteHTML(0, this.value, 'silent');
        }
        this.value = isEmpty(q.root.innerHTML) ? '' : rgbToHex(q.root.innerHTML);
      });
    }, 0);
  }, true);

  $(function () {
    scan(document);
    // Convert textareas added later (modals, AJAX-injected forms). convert()
    // skips already-upgraded ones, so re-scanning is cheap and loop-safe.
    if (window.MutationObserver) {
      var t;
      new MutationObserver(function () {
        clearTimeout(t);
        t = setTimeout(function () { scan(document); }, 150);
      }).observe(document.body, { childList: true, subtree: true });
    }
  });

  // Exposed so other scripts can upgrade freshly-injected markup on demand.
  window.cbdInitWysiwyg = scan;

}(jQuery));
