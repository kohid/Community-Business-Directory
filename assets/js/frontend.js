/* Community Business Directory v4.0 — Frontend JS */
/* global cbdData, jQuery */
(function ($) {
  'use strict';
  if (typeof cbdData === 'undefined') return;

  /* ── Generic AJAX form handler ─────────────────────────── */
  function cbdSubmitForm($form, onSuccess) {
    var $btn  = $form.find('[type=submit]');
    var $msg  = $form.find('.cbd-form-msg');
    var data  = new FormData($form[0]);

    $btn.find('.btn-text').hide();
    $btn.find('.btn-loading').show();
    $btn.prop('disabled', true);
    $msg.hide().removeClass('cbd-notice-success cbd-notice-error');

    $.ajax({
      url: cbdData.ajaxUrl, type: 'POST', data: data,
      processData: false, contentType: false,
      success: function (res) {
        if (res.success) {
          $msg.addClass('cbd-notice-success').text(res.data.message).show();
          $form[0].reset();
          if (res.data.redirect) {
            setTimeout(function () { window.location.href = res.data.redirect; }, 1500);
          } else if (typeof onSuccess === 'function') {
            onSuccess(res, $form);
          }
        } else {
          var err = (res.data && res.data.message) ? res.data.message : cbdData.i18n.error;
          $msg.addClass('cbd-notice-error').text(err).show();
        }
      },
      error: function () { $msg.addClass('cbd-notice-error').text(cbdData.i18n.error).show(); },
      complete: function () {
        $btn.find('.btn-text').show();
        $btn.find('.btn-loading').hide();
        $btn.prop('disabled', false);
      }
    });
  }

  /* ═══════════════════════════════════════════════════════════
     AJAX DIRECTORY
  ═══════════════════════════════════════════════════════════ */
  var dirTimer, dirPage = 1, dirPages = 1, dirLoading = false;

  // Escape arbitrary text for safe HTML insertion.
  function cbdEsc(s) { return $('<span>').text(s == null ? '' : s).html(); }

  // Which layout is the directory currently in?
  function cbdDirView() {
    var $l = $('#cbd-listings');
    if ($l.hasClass('cbd-view-list'))   return 'list';
    if ($l.hasClass('cbd-view-slides')) return 'slides';
    return 'grid';
  }

  // Build one business card (shared by initial load and infinite-scroll append).
  function cbdBuildCard(biz) {
    var stars = '';
    if (biz.rating_avg > 0) {
      for (var s = 1; s <= 5; s++) stars += s <= Math.round(biz.rating_avg) ? '★' : '☆';
    }
    var img = biz.thumb
      ? '<img src="' + biz.thumb + '" class="cbd-card-img" loading="lazy" alt="' + cbdEsc(biz.title) + '">'
      : '<div class="cbd-card-img cbd-card-img-placeholder"><span>' + cbdEsc((biz.title[0] || '?').toUpperCase()) + '</span></div>';

    var badges = biz.is_featured ? '<span class="cbd-badge cbd-badge-featured">⭐</span>' : '';
    if (biz.category) badges += '<span class="cbd-badge cbd-badge-cat">' + cbdEsc(biz.category) + '</span>';

    var followBtn = '', favBtn = '';
    if (biz.logged_in) {
      followBtn = '<button class="cbd-btn cbd-btn-sm cbd-btn-icon cbd-follow-btn ' + (biz.is_fol ? 'is-following' : '') + '" data-business-id="' + biz.id + '">' +
        (biz.is_fol ? '✓ Following' : '+ Follow') + '</button>';
      favBtn = '<button class="cbd-btn cbd-btn-sm cbd-btn-icon cbd-fav-btn ' + (biz.is_fav ? 'is-favorited' : '') + '" data-business-id="' + biz.id + '" title="' + (biz.is_fav ? 'Remove from favourites' : 'Save to favourites') + '">' +
        (biz.is_fav ? '♥' : '♡') + '</button>';
    }

    var ratingHtml = biz.rating_avg > 0
      ? '<span class="cbd-card-rating"><span class="cbd-stars">' + stars + '</span> <small>(' + biz.review_count + ')</small></span>'
      : '<span class="cbd-card-rating cbd-card-rating-empty"></span>';

    // Prefer the full address; fall back to the city for older payloads.
    var loc = biz.address || biz.city || '';

    var chevron = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>';

    var html = '';
    html += '<div class="cbd-business-card' + (biz.is_featured ? ' is-featured' : '') + '">';
    html += '<a href="' + biz.url + '" class="cbd-card-img-wrap">';
    html += img;
    if (badges) html += '<div class="cbd-badges">' + badges + '</div>';
    html += '</a>';
    html += '<div class="cbd-card-body">';
    html += '<h3 class="cbd-card-title"><a href="' + biz.url + '">' + cbdEsc(biz.title) + '</a></h3>';
    if (loc) html += '<p class="cbd-card-location"><span class="cbd-pin">' + '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="#009ef7" d="M12 11.5A2.5 2.5 0 0 1 9.5 9A2.5 2.5 0 0 1 12 6.5A2.5 2.5 0 0 1 14.5 9a2.5 2.5 0 0 1-2.5 2.5M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7Z"></path></svg>' + '</span> <span>' + cbdEsc(loc) + '</span></p>';
    html += '<p class="cbd-card-excerpt">' + cbdEsc(biz.excerpt) + '</p>';
    html += '<div class="cbd-card-footer">' + ratingHtml;
    html += '<div class="cbd-card-actions">';
    html += '<a href="' + biz.url + '" class="cbd-card-visit">' + (cbdData.i18n.visit || 'Visit') + ' ' + chevron + '</a>';
    html += followBtn + favBtn;
    html += '</div></div></div></div>';
    return html;
  }

  function loadDirectory(page, append) {
    var $wrap = $('#cbd-directory-wrap');
    if (!$wrap.length || dirLoading) return;
    dirLoading = true;
    page   = page || 1;
    append = !!append;

    var keyword  = $('#cbd-live-search').val()   || '';
    var category = $('#cbd-filter-cat').val()    || $wrap.data('default-cat') || '';
    var location = $('#cbd-filter-loc').val()    || '';
    var orderby  = $('#cbd-filter-order').val()  || $wrap.data('default-orderby') || 'newest';
    var perPage  = $wrap.data('per-page')        || 9;

    if (append) {
      if (cbdDirView() === 'slides') $('#cbd-dir-loader').addClass('is-active');
    } else {
      $('#cbd-listings').html('<div class="cbd-loading-state" style="text-align:center;padding:60px 20px;color:var(--cbd-ink-muted);">' +
        '<div class="cbd-spinner"></div><p>' + cbdData.i18n.loading + '</p></div>');
      $('#cbd-clear-btn').toggle(!!(keyword || category || location));
    }

    $.post(cbdData.ajaxUrl, {
      action:   'cbd_load_directory',
      page:     page,
      per_page: perPage,
      keyword:  keyword,
      category: category,
      location: location,
      orderby:  orderby
    }, function (res) {
      dirLoading = false;
      $('#cbd-dir-loader').removeClass('is-active');

      if (!res.success) {
        if (!append) $('#cbd-listings').html('<div class="cbd-empty-state"><span class="cbd-empty-icon">⚠️</span><p>' + cbdData.i18n.error + '</p></div>');
        return;
      }
      var data  = res.data;
      var cards = data.cards || [];
      var total = data.total || 0;

      dirPages = data.pages || 1;
      dirPage  = data.page  || page;

      // Update count
      $('#cbd-results-count').text(total === 1 ? total + ' business found' : total + ' businesses found');

      if (!cards.length && !append) {
        $('#cbd-listings').html(
          '<div class="cbd-empty-state">' +
          '<span class="cbd-empty-icon">🏢</span>' +
          '<h3>No businesses found</h3>' +
          '<p>Try adjusting your search.</p></div>'
        );
        $('#cbd-dir-end').removeClass('is-visible');
        $('#cbd-dir-loadmore').hide();
        return;
      }

      var html = '';
      $.each(cards, function (i, biz) { html += cbdBuildCard(biz); });

      if (append) { $('#cbd-listings').append(html); }
      else        { $('#cbd-listings').html(html); }

      cbdUpdateArrows($('#cbd-listings').closest('.cbd-listings-stage'));
      if ($('#cbd-listings').hasClass('cbd-view-masonry')) { cbdMasonryAll(); }

      // Reflect remaining pages on the Load More button / end marker.
      cbdDirUpdateLoadMore();
      cbdDirAutoload(); // keep filling while the foot stays within view
    });
  }

  // Show/hide the directory "Load more" button (grid/list/masonry only; slides
  // auto-loads on horizontal scroll) and reset its state after each load.
  function cbdDirUpdateLoadMore() {
    var more   = dirPage < dirPages;
    var isGrid = cbdDirView() !== 'slides';
    $('#cbd-dir-loadmore').toggle(more && isGrid);
    var $b = $('#cbd-dir-loadmore-btn').prop('disabled', false);
    $b.find('.btn-text').show();
    $b.find('.btn-loading').hide();
    $('#cbd-dir-end').toggleClass('is-visible', !more);
  }

  // Manual "Load more" (grid / list / masonry), 10 at a time. The button is
  // hidden via .cbd-lm-autoload CSS — kept as a fallback for no-IO browsers.
  $(document).on('click', '#cbd-dir-loadmore-btn', function () {
    if (dirLoading || dirPage >= dirPages) return;
    var $b = $(this).prop('disabled', true);
    $b.find('.btn-text').hide();
    $b.find('.btn-loading').show();
    loadDirectory(dirPage + 1, true);
  });

  // Infinite scroll for the directory (grid / list / masonry — slides scroll
  // horizontally and load on their own). Fires the next page when the
  // load-more foot nears the viewport; the foot is display:none in slides view
  // and when no pages remain, so this naturally stays idle then.
  function cbdDirAutoload() {
    if (dirLoading || dirPage >= dirPages || cbdDirView() === 'slides') return;
    var foot = document.getElementById('cbd-dir-loadmore');
    if (!foot || foot.style.display === 'none') return;
    if (foot.getBoundingClientRect().top < (window.innerHeight + 350)) {
      loadDirectory(dirPage + 1, true);
    }
  }
  if ('IntersectionObserver' in window) {
    var dirFoot = document.getElementById('cbd-dir-loadmore');
    if (dirFoot) {
      new IntersectionObserver(function (entries) {
        entries.forEach(function (e) { if (e.isIntersecting) cbdDirAutoload(); });
      }, { rootMargin: '350px 0px' }).observe(dirFoot);
    }
  }

  // Directory events
  $(document).on('click', '#cbd-search-btn', function () { loadDirectory(1); });
  $(document).on('click', '#cbd-clear-btn',  function () {
    $('#cbd-live-search, #cbd-filter-cat, #cbd-filter-loc').val('');
    $('#cbd-filter-order').val('newest');
    loadDirectory(1);
  });
  $(document).on('input', '#cbd-live-search', function () {
    clearTimeout(dirTimer);
    dirTimer = setTimeout(function () { loadDirectory(1); }, 350);
  });
  $(document).on('change', '#cbd-filter-cat, #cbd-filter-loc, #cbd-filter-order', function () { loadDirectory(1); });

  /* ── View toggle: grid / list / slides (scoped per host) ── */
  function cbdHostOf($el)   { return $el.closest('.cbd-listings-host'); }
  function cbdStageOf($el)  { return $el.closest('.cbd-listings-stage'); }

  function cbdSetView($host, view) {
    if (!$host || !$host.length) return;
    var $stage    = $host.find('.cbd-listings-stage').first();
    var $listings = $stage.find('.cbd-listings').first();
    if (!$listings.length) return;
    $listings.removeClass('cbd-view-grid cbd-view-list cbd-view-slides cbd-view-masonry').addClass('cbd-view-' + view);
    $stage.toggleClass('is-slides', view === 'slides');
    $host.find('.cbd-view-toggle .cbd-view-btn').removeClass('active').attr('aria-pressed', 'false');
    $host.find('.cbd-view-btn[data-view="' + view + '"]').addClass('active').attr('aria-pressed', 'true');
    if (view === 'slides') { $listings.scrollLeft(0); }

    if (view === 'masonry') {
      cbdMasonryResize($listings);
      cbdMasonryAll(); // (re)bind image-load recompute for this track
    } else {
      // Grid view is also display:grid — drop the bento placement so it
      // doesn't leak into the uniform grid.
      $listings.children('.cbd-business-card').css({ 'grid-column': '', 'grid-row': '', 'grid-row-end': '' }).removeClass('cbd-bento-lg');
      $listings[0].style.gridAutoRows = '';
    }

    cbdUpdateArrows($stage);
    // Show/hide the Load More button to match the new view (hidden in slides).
    if ($host.is('#cbd-directory-wrap') ) { cbdDirUpdateLoadMore(); }
  }

  function cbdSlideStep($listings) {
    var $card = $listings.find('.cbd-business-card').first();
    var w = $card.length ? $card.outerWidth(true) : 300;
    return Math.max(w, 200);
  }

  function cbdUpdateArrows($stage) {
    if (!$stage || !$stage.length) return;
    var $listings = $stage.find('.cbd-listings').first();
    var el = $listings[0];
    if (!el || !$listings.hasClass('cbd-view-slides')) return;
    var max = el.scrollWidth - el.clientWidth - 2;
    $stage.find('.cbd-slide-prev').prop('disabled', el.scrollLeft <= 2);
    $stage.find('.cbd-slide-next').prop('disabled', el.scrollLeft >= max);
  }

  /* ── Bento: place alternating 2×2 hero cards on a fixed 4-col grid ──
     Pattern repeats every 10 cards / 4 rows: band A has a hero on the LEFT
     (card 10n+1) + four small cards filling the right 2×2; band B has four
     small cards on the left + a hero on the RIGHT (card 10n). Heroes get an
     explicit row+column so the grid's dense auto-placement packs the small
     cards around them exactly as in the reference. */
  var CBD_BENTO_GAP = 22; // matches the grid gap in CSS

  function cbdMasonryResize($listings) {
    var el = $listings[0];
    if (!el || !$listings.hasClass('cbd-view-masonry')) return;
    var $cards = $listings.children('.cbd-business-card');

    // Reset any previous placement before measuring / re-placing.
    $cards.css({ 'grid-column': '', 'grid-row': '' }).removeClass('cbd-bento-lg');
    el.style.gridAutoRows = '';

    var cols = (getComputedStyle(el).gridTemplateColumns.match(/px/g) || []).length || 1;
    if (cols < 2) return; // single column → natural stacking (handled by CSS)

    // Square-ish cell sized from the resolved column width.
    var first = $cards.first()[0];
    var cw = first ? first.getBoundingClientRect().width : (el.clientWidth - (cols - 1) * CBD_BENTO_GAP) / cols;
    el.style.gridAutoRows = Math.round(cw) + 'px';

    $cards.each(function (idx) {
      var i = idx + 1;
      if (cols >= 4) {
        if (i % 10 === 1) {                              // hero — left
          this.style.gridColumn = '1 / span 2';
          this.style.gridRow = ( ( (i - 1) / 10 ) * 4 + 1 ) + ' / span 2';
          this.classList.add('cbd-bento-lg');
        } else if (i % 10 === 0) {                       // hero — right
          this.style.gridColumn = '3 / span 2';
          this.style.gridRow = ( ( i / 10 - 1 ) * 4 + 3 ) + ' / span 2';
          this.classList.add('cbd-bento-lg');
        }
      } else if (i % 5 === 1) {                          // 2-col → full-width hero
        this.style.gridColumn = '1 / span 2';
        this.classList.add('cbd-bento-lg');
      }
    });
  }

  // Apply to every masonry track on the page; recompute as images load (they
  // change card heights), since the row math depends on the final image size.
  function cbdMasonryAll() {
    $('.cbd-listings.cbd-view-masonry').each(function () {
      var $listings = $(this);
      cbdMasonryResize($listings);
      $listings.find('img').each(function () {
        if (this.complete || this.dataset.cbdMb) return;
        this.dataset.cbdMb = '1';
        $(this).on('load error', function () { cbdMasonryResize($listings); });
      });
    });
  }

  $(document).on('click', '.cbd-view-btn', function () {
    cbdSetView(cbdHostOf($(this)), $(this).data('view'));
  });
  $(document).on('click', '.cbd-slide-prev', function () {
    var $listings = cbdStageOf($(this)).find('.cbd-listings').first();
    if ($listings[0]) $listings[0].scrollBy({ left: -cbdSlideStep($listings), behavior: 'smooth' });
  });
  $(document).on('click', '.cbd-slide-next', function () {
    var $listings = cbdStageOf($(this)).find('.cbd-listings').first();
    if ($listings[0]) $listings[0].scrollBy({ left: cbdSlideStep($listings), behavior: 'smooth' });
  });

  // Initial load + scroll wiring
  $(document).ready(function () {
    // Keep slide arrows in sync for every listings track on the page.
    $('.cbd-listings').each(function () {
      var $listings = $(this);
      $listings.on('scroll', function () {
        var $stage = $listings.closest('.cbd-listings-stage');
        cbdUpdateArrows($stage);
        // In slides view the directory loads more as you reach the right edge.
        if ($listings.attr('id') === 'cbd-listings' && $listings.hasClass('cbd-view-slides') &&
            !dirLoading && dirPage < dirPages) {
          var el = $listings[0];
          if (el.scrollWidth - el.clientWidth - el.scrollLeft < 320) {
            loadDirectory(dirPage + 1, true);
          }
        }
      });
    });
    var cbdResizeTimer;
    $(window).on('resize', function () {
      $('.cbd-listings-stage').each(function () { cbdUpdateArrows($(this)); });
      clearTimeout(cbdResizeTimer);
      cbdResizeTimer = setTimeout(cbdMasonryAll, 120); // column count may have changed
    });

    // Initialise arrow state for any section that renders straight into slides view.
    $('.cbd-listings-stage').each(function () { cbdUpdateArrows($(this)); });
    // Lay out any section that renders straight into masonry view (e.g. featured).
    cbdMasonryAll();

    if ($('#cbd-directory-wrap').length) {
      loadDirectory(1);
    }
  });

  /* ═══════════════════════════════════════════════════════════
     FORMS
  ═══════════════════════════════════════════════════════════ */
  $(document).on('submit', '#cbd-register-form',       function (e) { e.preventDefault(); cbdSubmitForm($(this)); });
  // Reload on success so the sidebar (contact, social, address) and the whole
  // owner business page reflect the saved changes immediately.
  $(document).on('submit', '#cbd-edit-profile-form',   function (e) { e.preventDefault(); cbdSubmitForm($(this), function () { window.location.reload(); }); });

  // ── "Page Profile" lockable edit form (read-only until "Edit Page") ──
  function cbdLockForm($f, locked) {
    if (!$f || !$f.length) return;
    $f.toggleClass('is-locked', !!locked);
    $f.find('input, select, textarea').prop('disabled', !!locked);
    // Rich textareas are Quill editors (a contenteditable div, not the textarea).
    // pointer-events:none only blocks the mouse — keyboard focus/caret can still
    // edit it. quill.enable(false) sets contenteditable=false so it's truly
    // read-only; blur it so any existing caret is removed.
    $f.find('textarea').each(function () {
      var q = $(this).data('cbdQuill');
      if (q && typeof q.enable === 'function') {
        q.enable(!locked);
        if (locked && typeof q.blur === 'function') q.blur();
      }
    });
    $f.find('[data-cbd-edit-toggle]').toggle(!!locked);
    $f.find('[data-cbd-edit-save], [data-cbd-edit-cancel]').toggle(!locked);
  }
  $('[data-cbd-lockable]').each(function () { cbdLockForm($(this), true); });
  $(document).on('click', '[data-cbd-edit-toggle]', function () {
    cbdLockForm($(this).closest('form'), false);
  });
  $(document).on('click', '[data-cbd-edit-cancel]', function () {
    var $f = $(this).closest('form');
    cbdLockForm($f, true);
    $f.find('.cbd-form-msg').hide();
  });
  $(document).on('submit', '#cbd-gallery-form',        function (e) { e.preventDefault(); cbdSubmitForm($(this), function () { window.location.reload(); }); });
  $(document).on('submit', '#cbd-hours-form',          function (e) { e.preventDefault(); cbdSubmitForm($(this), function () { window.location.reload(); }); });

  /* ── Social sync page (/socmed) — tabs, save, IG detect ── */
  $(document).on('click', '.cbd-socmed-tab', function () {
    var tab = $(this).data('tab');
    $('.cbd-socmed-tab').removeClass('is-active').attr('aria-selected', 'false');
    $(this).addClass('is-active').attr('aria-selected', 'true');
    $('.cbd-socmed-tabpanel').removeClass('is-active').prop('hidden', true);
    $('#cbd-socmed-' + tab).addClass('is-active').prop('hidden', false);
  });

  // Method sub-tabs (SnapWidget / Native Meta sync) within a platform form.
  $(document).on('click', '.cbd-socmed-subtab', function () {
    var $t = $(this), key = $t.data('subtab'), $form = $t.closest('form');
    $form.find('.cbd-socmed-subtab').removeClass('is-active').attr('aria-selected', 'false');
    $t.addClass('is-active').attr('aria-selected', 'true');
    $form.find('.cbd-socmed-subpanel').removeClass('is-active').prop('hidden', true);
    $form.find('.cbd-socmed-subpanel[data-subpanel="' + key + '"]').addClass('is-active').prop('hidden', false);
  });

  // Save → reload so pasted widget <script>s re-run and the preview is real.
  $(document).on('submit', '.cbd-socmed-form', function (e) {
    e.preventDefault();
    cbdSubmitForm($(this), function () {
      setTimeout(function () { window.location.reload(); }, 700);
    });
  });

  $(document).on('click', '#cbd-socmed-ig-detect', function () {
    var $btn = $(this);
    var $msg = $btn.closest('p').find('.cbd-socmed-detect-msg');
    if ($btn.prop('disabled')) return;
    $btn.prop('disabled', true);
    $msg.text(cbdData.i18n && cbdData.i18n.working ? cbdData.i18n.working : 'Looking…');
    $.post(cbdData.ajaxUrl, { action: 'cbd_ig_discover', nonce: $btn.data('nonce') })
      .done(function (res) {
        if (res && res.success) {
          if (res.data && res.data.id) $('#cbd-socmed-ig-id').val(res.data.id);
          $msg.text((res.data && res.data.message) || 'Found it. Save to apply.');
        } else {
          $msg.text((res && res.data && res.data.message) || 'Could not detect the account.');
        }
      })
      .fail(function () { $msg.text('Could not reach Instagram. Try again.'); })
      .always(function () { $btn.prop('disabled', false); });
  });

  /* ── Generic "Load more" + infinite scroll for list shortcodes ──────── */
  function cbdLmLoad($wrap) {
    if (!$wrap || !$wrap.length) return;
    if ($wrap.data('lmBusy') || $wrap.data('lmDone')) return;
    var $list = $wrap.find('.cbd-lm-list').first();
    if (!$list.length) return;
    var $btn = $wrap.find('.cbd-lm-btn').first();
    var next = (parseInt($wrap.attr('data-page'), 10) || 1) + 1;

    $wrap.data('lmBusy', true);
    if ($btn.length) {
      $btn.prop('disabled', true);
      $btn.find('.btn-text').hide();
      $btn.find('.btn-loading').show();
    }

    $.post(cbdData.ajaxUrl, {
      action:   'cbd_load_more',
      sc:       $wrap.attr('data-cbd-lm'),
      page:     next,
      per_page: $wrap.attr('data-per-page'),
      atts:     $wrap.attr('data-atts')
    }).done(function (res) {
      if (res && res.success) {
        if (res.data.html) {
          var $nodes = $($.parseHTML(res.data.html));
          // De-dupe a group header that repeats across the page boundary
          // (each page restarts its group tracking from the first card).
          var lastGroup = $list.find('.cbd-lm-group').last().attr('data-group');
          var $firstGrp = $nodes.filter('.cbd-lm-group').first();
          if (lastGroup && $firstGrp.length && $firstGrp.attr('data-group') === lastGroup) {
            $firstGrp.remove();
          }
          $list.append($nodes);
        }
        $wrap.attr('data-page', next);
        if (res.data.has_more) {
          if ($btn.length) {
            $btn.prop('disabled', false).find('.btn-text').show();
            $btn.find('.btn-loading').hide();
          }
        } else {
          $wrap.data('lmDone', true);
          $wrap.find('.cbd-lm-foot').remove();
        }
      } else if ($btn.length) {
        $btn.prop('disabled', false).find('.btn-text').show();
        $btn.find('.btn-loading').hide();
      }
    }).fail(function () {
      if ($btn.length) {
        $btn.prop('disabled', false).find('.btn-text').show();
        $btn.find('.btn-loading').hide();
      }
    }).always(function () {
      $wrap.data('lmBusy', false);
      // Keep filling while the foot is still within reach (auto-load wrappers
      // whose first page didn't fill the viewport).
      if ($wrap.hasClass('cbd-lm-autoload') && !$wrap.data('lmDone')) {
        var foot = $wrap.find('.cbd-lm-foot').get(0);
        if (foot && foot.getBoundingClientRect().top < (window.innerHeight + 300)) {
          cbdLmLoad($wrap);
        }
      }
    });
  }

  // Manual button (fallback / non-autoload lists).
  $(document).on('click', '.cbd-lm-btn', function () {
    cbdLmLoad($(this).closest('.cbd-lm'));
  });

  // Infinite scroll: auto-load `.cbd-lm-autoload` wrappers when their foot
  // scrolls near the viewport. The button is hidden via CSS for these.
  if ('IntersectionObserver' in window) {
    var cbdLmIO = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          cbdLmLoad($(entry.target).closest('.cbd-lm'));
        }
      });
    }, { rootMargin: '350px 0px' });
    $('.cbd-lm-autoload .cbd-lm-foot').each(function () {
      cbdLmIO.observe(this);
    });
  }

  /* ── User account profile modal ────────────────────────── */
  $(document).on('click', '[data-cbd-profile-open]', function (e) {
    e.preventDefault();
    $('[data-cbd-account] .cbd-account-menu').attr('hidden', true);
    openModal('#cbd-profile-modal');
  });
  $(document).on('click', '[data-cbd-profile-close]', function (e) {
    e.preventDefault();
    closeModal('#cbd-profile-modal');
  });

  /* ── "Activities" modal — lazy-loads the user's own activity ── */
  $(document).on('click', '[data-cbd-activities-open]', function (e) {
    e.preventDefault();
    $('[data-cbd-account] .cbd-account-menu').attr('hidden', true);
    var $body = $('[data-cbd-activities-body]');
    openModal('#cbd-activities-modal');
    $body.html('<div class="cbd-spinner"></div>');
    $.post(cbdData.ajaxUrl, { action: 'cbd_my_activities', cbd_nonce: cbdData.nonce })
      .done(function (res) {
        $body.html((res && res.success && res.data.html) ? res.data.html
          : '<div class="cbd-empty-state"><p>' + (cbdData.i18n.error || 'Error') + '</p></div>');
      })
      .fail(function () {
        $body.html('<div class="cbd-empty-state"><p>' + (cbdData.i18n.error || 'Error') + '</p></div>');
      });
  });
  $(document).on('click', '[data-cbd-activities-close]', function (e) {
    e.preventDefault();
    closeModal('#cbd-activities-modal');
  });
  // Switch between activity tabs (markup is injected by the AJAX response).
  $(document).on('click', '[data-cbd-act-tab]', function () {
    var key = $(this).attr('data-cbd-act-tab');
    var $scope = $(this).closest('.cbd-modal');
    $scope.find('[data-cbd-act-tab]').removeClass('is-active').attr('aria-selected', 'false');
    $(this).addClass('is-active').attr('aria-selected', 'true');
    $scope.find('[data-cbd-act-panel]').attr('hidden', true).removeClass('is-active');
    $scope.find('[data-cbd-act-panel="' + key + '"]').removeAttr('hidden').addClass('is-active');
  });
  // Live preview the chosen profile photo before upload. Crop-enabled avatar
  // inputs are previewed by the cropper instead (after Apply), so skip them here
  // to avoid flashing the un-cropped original.
  $(document).on('change', '[data-cbd-avatar-input]', function () {
    if (this.hasAttribute('data-crop-aspect')) return;
    var file = this.files && this.files[0];
    if (file) $('[data-cbd-avatar-preview]').attr('src', URL.createObjectURL(file));
  });
  $(document).on('submit', '#cbd-account-form', function (e) {
    e.preventDefault();
    var $form = $(this);
    cbdSubmitForm($form, function (res) {
      if (res.data && res.data.avatar) {
        $('[data-cbd-avatar-preview], [data-cbd-account] .cbd-account-avatar img, .cbd-account-user img').attr('src', res.data.avatar);
      }
      if (res.data && res.data.name) { $('.cbd-account-name').text(res.data.name); }
      // Never leave a typed password sitting in the form.
      $form.find('[name=new_password], [name=confirm_password]').val('');
      setTimeout(function () { closeModal('#cbd-profile-modal'); }, 1200);
    });
  });

  /* ── Auto-resizing textareas (grow with content) ───────── */
  function cbdAutoGrow(ta) {
    if (!ta) return;
    ta.style.height = 'auto';
    ta.style.height = (ta.scrollHeight + 2) + 'px';
  }
  $(document).on('input focus', '.cbd-form textarea', function () { cbdAutoGrow(this); });

  /* ── Emoji picker trigger on every form textarea ───────── */
  function cbdAddEmojiTriggers(scope) {
    if (typeof WSEmojiPicker === 'undefined') return; // picker script not loaded
    $(scope || document).find('.cbd-form textarea').each(function () {
      if (this.dataset.cbdEmoji) return;
      this.dataset.cbdEmoji = '1';
      if (!this.id) this.id = 'cbd-ta-' + Math.random().toString(36).slice(2, 9);
      var $ta = $(this);
      $ta.wrap('<div class="cbd-emoji-field"></div>');
      $ta.parent().append(
        '<button type="button" class="cbd-emoji-trigger" data-emoji-trigger data-emoji-target="#' + this.id +
        '" aria-label="Insert emoji" title="Emoji">😊</button>'
      );
      cbdAutoGrow(this); // re-measure after the extra bottom padding is applied
    });
  }

  $(document).ready(function () {
    $('.cbd-form textarea').each(function () { cbdAutoGrow(this); });
    cbdAddEmojiTriggers(document);
  });

  // Real-time post: prepend to the News Feed if present, otherwise reload (dashboard).
  $(document).on('submit', '#cbd-business-post-form', function (e) {
    e.preventDefault();
    cbdSubmitForm($(this), function (res) {
      var $feed = $('#cbd-news-feed-list');
      if ($feed.length && res.data && res.data.html) {
        $feed.prepend(res.data.html);
        $feed.siblings('.cbd-feed-empty').hide();
      } else {
        window.location.reload();
      }
    });
  });

  // Real-time review: prepend to the reviews list, no reload.
  $(document).on('submit', '#cbd-review-form', function (e) {
    e.preventDefault();
    cbdSubmitForm($(this), function (res) {
      if (res.data && res.data.html) {
        $('.cbd-reviews-list').first().prepend(res.data.html);
        $('.cbd-no-reviews').hide();
      }
    });
  });

  /* ── Event & Promo modals ──────────────────────────────── */
  function openModal(id)  { $(id).fadeIn(200); $('body').addClass('cbd-modal-open'); }
  function closeModal(id) { $(id).fadeOut(200); $('body').removeClass('cbd-modal-open'); }

  $(document).on('click', '#cbd-new-event-btn',    function () { openModal('#cbd-event-form-wrap'); });
  $(document).on('click', '#cbd-event-form-close', function () { closeModal('#cbd-event-form-wrap'); });
  $(document).on('click', '#cbd-new-promo-btn',    function () { openModal('#cbd-promo-form-wrap'); });
  $(document).on('click', '#cbd-promo-form-close', function () { closeModal('#cbd-promo-form-wrap'); });
  $(document).on('click', '.cbd-modal-wrap',       function (e) { if ($(e.target).hasClass('cbd-modal-wrap')) closeModal('#' + $(e.target).attr('id')); });

  $(document).on('submit', '#cbd-event-form', function (e) {
    e.preventDefault();
    cbdSubmitForm($(this), function () { closeModal('#cbd-event-form-wrap'); window.location.reload(); });
  });
  $(document).on('submit', '#cbd-promo-form', function (e) {
    e.preventDefault();
    cbdSubmitForm($(this), function () { closeModal('#cbd-promo-form-wrap'); window.location.reload(); });
  });

  /* ── Follow / Unfollow ─────────────────────────────────── */
  $(document).on('click', '.cbd-follow-btn', function () {
    var $btn = $(this), bizId = $btn.data('business-id'), following = $btn.hasClass('is-following');
    $btn.prop('disabled', true);
    $.post(cbdData.ajaxUrl, { action: 'cbd_follow_business', business_id: bizId, follow_action: following ? 'unfollow' : 'follow', cbd_nonce: cbdData.nonce },
      function (res) {
        if (res.success) {
          $btn.toggleClass('is-following', res.data.following).prop('disabled', false)
            .text(res.data.following ? '✓ Following' : '+ Follow');
        }
      });
  });

  /* ── Favourite toggle ──────────────────────────────────── */
  $(document).on('click', '.cbd-fav-btn', function () {
    var $btn = $(this), bizId = $btn.data('business-id');
    $btn.prop('disabled', true);
    $.post(cbdData.ajaxUrl, { action: 'cbd_toggle_favorite', business_id: bizId, cbd_nonce: cbdData.nonce },
      function (res) {
        if (res.success) {
          $btn.toggleClass('is-favorited', res.data.favorited).prop('disabled', false)
            .text(res.data.favorited ? '♥' : '♡')
            .attr('title', res.data.favorited ? 'Remove from favourites' : 'Save to favourites');
        }
      });
  });

  /* ── Live Search Autocomplete ──────────────────────────── */
  var acTimer;
  $(document).on('input', '#cbd-live-search', function () {
    clearTimeout(acTimer);
    var q = $(this).val().trim(), $ac = $('#cbd-autocomplete');
    if (q.length < 2) { $ac.hide().empty(); return; }
    acTimer = setTimeout(function () {
      $.get(cbdData.ajaxUrl, { action: 'cbd_live_search', q: q, cbd_nonce: cbdData.nonce }, function (res) {
        if (!res.success || !res.data.length) { $ac.hide().empty(); return; }
        $ac.empty();
        $.each(res.data, function (i, item) {
          $ac.append($('<a>').addClass('cbd-ac-item').attr('href', item.url)
            .text((item.type === 'cbd_event' ? '📅 ' : '🏢 ') + item.title));
        });
        $ac.show();
      });
    }, 280);
  });
  $(document).on('click', function (e) {
    if (!$(e.target).closest('.cbd-search-field').length) $('#cbd-autocomplete').hide();
  });

  /* ── Copy Coupon ───────────────────────────────────────── */
  $(document).on('click', '.cbd-copy-code', function () {
    var code = $(this).data('code'), $btn = $(this);
    (navigator.clipboard ? navigator.clipboard.writeText(code) : Promise.resolve()).then(function () {
      if (!navigator.clipboard) { var el = document.createElement('textarea'); el.value = code; document.body.appendChild(el); el.select(); document.execCommand('copy'); document.body.removeChild(el); }
      $btn.text('Copied!'); setTimeout(function () { $btn.text('Copy'); }, 2000);
    });
  });

  /* ── Star Rating Picker ────────────────────────────────── */
  function updateStars($p) {
    var v = parseInt($p.find('input:checked').val() || 0);
    $p.find('label').each(function (i) { $(this).css('color', (5 - i) <= v ? '#ffc700' : '#d4dde2'); });
  }
  $(document).on('change', '.cbd-star-picker input[type=radio]', function () { updateStars($(this).closest('.cbd-star-picker')); });
  $(document).ready(function () { $('.cbd-star-picker').each(function () { updateStars($(this)); }); });

  /* ── Business Post delete ──────────────────────────────── */
  $(document).on('click', '.cbd-delete-post', function () {
    if (!confirm(cbdData.i18n.confirm_delete || 'Delete this post?')) return;
    var $item = $(this).closest('.cbd-feed-item'), postId = $(this).data('post-id');
    $.post(cbdData.ajaxUrl, { action: 'cbd_delete_business_post', post_id: postId, cbd_nonce: cbdData.nonce },
      function (res) { if (res.success) $item.fadeOut(300, function () { $(this).remove(); }); });
  });

  /* ── Gallery delete ────────────────────────────────────── */
  $(document).on('click', '.cbd-gallery-delete', function () {
    if (!confirm('Delete this photo?')) return;
    var $item = $(this).closest('.cbd-gallery-item'), attId = $(this).data('attachment-id');
    $.post(cbdData.ajaxUrl, { action: 'cbd_delete_gallery_image', attachment_id: attId, cbd_nonce: cbdData.nonce },
      function (res) { if (res.success) $item.fadeOut(300, function () { $(this).remove(); }); });
  });

  /* ── Post type pill selector ─────────────────────────────*/
  $(document).on('change', '.cbd-post-type-pills input', function () {
    $('.cbd-post-type-pills label').removeClass('active');
    $(this).closest('label').addClass('active');
  });
  $(document).ready(function () { $('.cbd-post-type-pills input:checked').closest('label').addClass('active'); });

  /* ── Plans billing toggle ──────────────────────────────── */
  $(document).on('change', '#cbd-billing-toggle', function () {
    var annual = $(this).is(':checked');
    $('#cbd-billing-monthly').toggleClass('active', !annual);
    $('#cbd-billing-annual').toggleClass('active', annual);
    $('.cbd-price-monthly').toggle(!annual);
    $('.cbd-price-annual').toggle(annual);
  });

  /* ── Membership plan upgrade / switch ──────────────────── */
  // Current billing cycle from any visible toggle (defaults to monthly).
  function cbdBillingCycle() {
    return $('#cbd-billing-toggle').is(':checked') ? 'annual' : 'monthly';
  }

  // Format a price for the confirm dialog, given the chosen cycle.
  function cbdPlanPriceLabel($btn, cycle) {
    var sym = cbdData.currencySymbol || '£';
    var monthly = parseFloat($btn.attr('data-price-monthly')) || 0;
    var annual  = parseFloat($btn.attr('data-price-annual'))  || 0;
    if (!monthly && !annual) return cbdData.i18n.free;
    if (cycle === 'annual' && annual > 0) {
      return sym + annual.toFixed(2) + ' ' + cbdData.i18n.per_year;
    }
    return sym + monthly.toFixed(2) + ' ' + cbdData.i18n.per_month;
  }

  // Build (once) and return the shared confirmation modal.
  function cbdPlanModal() {
    var $m = $('#cbd-plan-confirm');
    if (!$m.length) {
      $m = $(
        '<div id="cbd-plan-confirm" class="cbd-modal-wrap" style="display:none;">' +
          '<div class="cbd-modal cbd-plan-confirm-modal">' +
            '<button type="button" class="cbd-modal-close" data-plan-cancel>✕</button>' +
            '<h3>' + cbdEsc(cbdData.i18n.plan_confirm) + '</h3>' +
            '<p class="cbd-plan-confirm-body"></p>' +
            '<div class="cbd-form-msg" style="display:none;"></div>' +
            '<div class="cbd-form-actions" style="display:flex;gap:10px;justify-content:flex-end;">' +
              '<button type="button" class="cbd-btn cbd-btn-outline" data-plan-cancel>' + cbdEsc(cbdData.i18n.cancel) + '</button>' +
              '<button type="button" class="cbd-btn cbd-btn-primary" data-plan-confirm>' +
                '<span class="btn-text">' + cbdEsc(cbdData.i18n.plan_confirm_btn) + '</span>' +
                '<span class="btn-loading" style="display:none;">' + cbdEsc(cbdData.i18n.plan_switching) + '</span>' +
              '</button>' +
            '</div>' +
          '</div>' +
        '</div>'
      );
      $('body').append($m);
    }
    return $m;
  }

  // Click an upgrade / switch button → open the confirm modal.
  $(document).on('click', '.cbd-upgrade-btn', function (e) {
    e.preventDefault();
    var $btn = $(this);

    if (!cbdData.loggedIn) {
      window.location.href = cbdData.loginUrl || '/';
      return;
    }

    var plan   = $btn.attr('data-plan');
    var cycle  = cbdBillingCycle();
    var name   = $btn.attr('data-plan-name') ||
                 $btn.closest('.cbd-plan-card').find('.cbd-plan-name').text().trim() || plan;
    var postId = $btn.attr('data-post-id') || '';
    var price  = cbdPlanPriceLabel($btn, cycle);

    var $m = cbdPlanModal();
    $m.find('.cbd-form-msg').hide().removeClass('cbd-notice-success cbd-notice-error').empty();
    $m.find('[data-plan-confirm]')
      .data({ plan: plan, cycle: cycle, postId: postId })
      .find('.btn-text').show().end().find('.btn-loading').hide();
    $m.find('[data-plan-confirm]').prop('disabled', false);
    $m.find('.cbd-plan-confirm-body').html(
      'Switch to <strong>' + cbdEsc(name) + '</strong> — <strong>' + cbdEsc(price) + '</strong>?'
    );
    $m.fadeIn(180);
    $('body').addClass('cbd-modal-open');
  });

  // Cancel / dismiss.
  $(document).on('click', '#cbd-plan-confirm [data-plan-cancel]', function () {
    $('#cbd-plan-confirm').fadeOut(150);
    $('body').removeClass('cbd-modal-open');
  });
  $(document).on('click', '#cbd-plan-confirm', function (e) {
    if (e.target.id === 'cbd-plan-confirm') {
      $('#cbd-plan-confirm').fadeOut(150);
      $('body').removeClass('cbd-modal-open');
    }
  });

  // Confirm → POST the change.
  $(document).on('click', '#cbd-plan-confirm [data-plan-confirm]', function () {
    var $c    = $(this);
    var $m    = $('#cbd-plan-confirm');
    var $msg  = $m.find('.cbd-form-msg');

    $c.prop('disabled', true).find('.btn-text').hide().end().find('.btn-loading').show();
    $msg.hide().removeClass('cbd-notice-success cbd-notice-error');

    $.post(cbdData.ajaxUrl, {
      action:    'cbd_change_plan',
      cbd_nonce: cbdData.nonce,
      plan:      $c.data('plan'),
      cycle:     $c.data('cycle'),
      post_id:   $c.data('postId')
    }, function (res) {
      if (res.success) {
        $msg.addClass('cbd-notice-success').text(res.data.message).show();
        setTimeout(function () { window.location.reload(); }, 1200);
      } else {
        var err = (res.data && res.data.message) ? res.data.message : cbdData.i18n.error;
        $msg.addClass('cbd-notice-error').text(err).show();
        if (res.data && res.data.redirect) {
          setTimeout(function () { window.location.href = res.data.redirect; }, 1500);
        }
        $c.prop('disabled', false).find('.btn-text').show().end().find('.btn-loading').hide();
      }
    }).fail(function () {
      $msg.addClass('cbd-notice-error').text(cbdData.i18n.error).show();
      $c.prop('disabled', false).find('.btn-text').show().end().find('.btn-loading').hide();
    });
  });

  /* ── Free event toggle ─────────────────────────────────── */
  $(document).on('change', 'input[name=event_is_free]', function () {
    var free = $(this).is(':checked');
    $('input[name=event_ticket_price]').prop('disabled', free).closest('.cbd-form-row').css('opacity', free ? 0.4 : 1);
  });

  /* ═══════════════════════════════════════════════════════════
     REACTIONS
  ═══════════════════════════════════════════════════════════ */
  var CBD_REACT = {
    like:  { e: '👍', l: 'Like' },
    love:  { e: '❤️', l: 'Love' },
    haha:  { e: '😂', l: 'Haha' },
    wow:   { e: '😮', l: 'Wow' },
    sad:   { e: '😢', l: 'Sad' },
    angry: { e: '😡', l: 'Angry' }
  };

  // Default (un-reacted) Like button icon — a thumbs-up SVG instead of the 👍 glyph.
  var CBD_REACT_DEFAULT_ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="M5 9v12H1V9zm4 12a2 2 0 0 1-2-2V9c0-.55.22-1.05.59-1.41L14.17 1l1.06 1.06c.27.27.44.64.44 1.05l-.03.32L14.69 8H21a2 2 0 0 1 2 2v2c0 .26-.05.5-.14.73l-3.02 7.05C19.54 20.5 18.83 21 18 21zm0-2h9.03L21 12v-2h-8.79l1.13-5.32L9 9.03z"/></svg>';

  function cbdRenderSummary($wrap, counts, total) {
    var $sum = $wrap.find('.cbd-react-summary');
    if (!total) { $sum.hide(); return; }
    // Top 3 reaction types by count.
    var types = Object.keys(counts).sort(function (a, b) { return counts[b] - counts[a]; }).slice(0, 3);
    var emojis = types.map(function (t) {
      return '<span class="cbd-react-emoji">' + (CBD_REACT[t] ? CBD_REACT[t].e : '👍') + '</span>';
    }).join('');
    $sum.find('.cbd-react-summary-emojis').html(emojis);
    $sum.find('.cbd-react-summary-count').text(total);
    $sum.css('display', '');
  }

  function cbdSetButton($wrap, reaction) {
    var $btn = $wrap.find('.cbd-react-btn');
    var info = reaction && CBD_REACT[reaction];
    $btn.attr('data-current', reaction || '')
      .removeClass('has-reaction is-like is-love is-haha is-wow is-sad is-angry');
    if (info) { $btn.addClass('has-reaction is-' + reaction); }
    var $emoji = $btn.find('.cbd-react-btn-emoji');
    if (info) { $emoji.text(info.e); } else { $emoji.html(CBD_REACT_DEFAULT_ICON); }
    $btn.find('.cbd-react-btn-label').text(info ? info.l : 'Like');
  }

  // Open / close the picker (works for touch + click).
  $(document).on('click', '.cbd-react-btn', function (e) {
    e.preventDefault();
    var $wrap = $(this).closest('.cbd-reactions');
    if ($wrap.data('logged-in') !== 1 && $wrap.data('logged-in') !== '1') {
      window.location.href = $wrap.data('login-url');
      return;
    }
    $('.cbd-react-btn-wrap').not($(this).closest('.cbd-react-btn-wrap')).removeClass('cbd-picker-open');
    $(this).closest('.cbd-react-btn-wrap').toggleClass('cbd-picker-open');
  });

  // Pick a reaction.
  $(document).on('click', '.cbd-react-opt', function (e) {
    e.preventDefault();
    var $opt = $(this), $wrap = $opt.closest('.cbd-reactions');
    var postId = $wrap.data('post-id'), reaction = $opt.data('reaction');
    $opt.closest('.cbd-react-btn-wrap').removeClass('cbd-picker-open');
    $.post(cbdData.ajaxUrl, { action: 'cbd_react', post_id: postId, reaction: reaction, cbd_nonce: cbdData.nonce },
      function (res) {
        if (!res.success) {
          if (res.data && res.data.message) { alert(res.data.message); }
          return;
        }
        // A post can have more than one reaction bar on screen at once — the feed
        // card and the Read More modal's cloned bar share the same post id. Sync
        // them all so the summary emojis + button never drift out of step.
        $('.cbd-reactions[data-post-id="' + postId + '"]').each(function () {
          var $bar = $(this);
          cbdSetButton($bar, res.data.reaction);
          cbdRenderSummary($bar, res.data.counts || {}, res.data.total || 0);
        });
      });
  });

  // Close any open picker when clicking elsewhere.
  $(document).on('click', function (e) {
    if (!$(e.target).closest('.cbd-react-btn-wrap').length) {
      $('.cbd-react-btn-wrap.cbd-picker-open').removeClass('cbd-picker-open');
    }
  });

  // Open the "who reacted" modal.
  $(document).on('click', '.cbd-react-summary', function () {
    var postId = $(this).closest('.cbd-reactions').data('post-id');
    var $modal = $('#cbd-reactors-modal');
    if (!$modal.length) {
      $modal = $('<div id="cbd-reactors-modal" class="cbd-modal-wrap" style="display:none;">' +
        '<div class="cbd-modal cbd-reactors-modal-inner">' +
        '<button class="cbd-modal-close" id="cbd-reactors-close">✕</button>' +
        '<h3>' + (cbdData.i18n.reactions || 'Reactions') + '</h3>' +
        '<div class="cbd-reactors-list"></div></div></div>');
      $('body').append($modal);
    }
    var $list = $modal.find('.cbd-reactors-list').html('<p class="cbd-react-loading">' + cbdData.i18n.loading + '</p>');
    $modal.fadeIn(150); $('body').addClass('cbd-modal-open');
    $.get(cbdData.ajaxUrl, { action: 'cbd_get_reactions', post_id: postId }, function (res) {
      if (!res.success || !res.data.reactors.length) {
        $list.html('<p class="cbd-react-loading">' + (cbdData.i18n.no_results || 'No reactions yet.') + '</p>');
        return;
      }
      var html = '';
      $.each(res.data.reactors, function (i, r) {
        var emoji = CBD_REACT[r.reaction] ? CBD_REACT[r.reaction].e : '👍';
        html += '<div class="cbd-reactor-row">' +
          '<img class="cbd-reactor-avatar" src="' + r.avatar + '" alt="" loading="lazy">' +
          '<div class="cbd-reactor-id">' +
            '<span class="cbd-reactor-name">' + $('<span>').text(r.name).html() + '</span>' +
            (r.badge || '') +
          '</div>' +
          '<span class="cbd-reactor-emoji">' + emoji + '</span></div>';
      });
      $list.html(html);
    });
  });
  $(document).on('click', '#cbd-reactors-close', function () { $('#cbd-reactors-modal').fadeOut(150); $('body').removeClass('cbd-modal-open'); });
  $(document).on('click', '#cbd-reactors-modal', function (e) { if (e.target.id === 'cbd-reactors-modal') { $(this).fadeOut(150); $('body').removeClass('cbd-modal-open'); } });

  /* ═══════════════════════════════════════════════════════════
     GALLERY LIGHTBOX (profile albums)
  ═══════════════════════════════════════════════════════════ */
  var cbdLbItems = [], cbdLbIndex = 0;

  function cbdLightbox() {
    var $lb = $('#cbd-lightbox');
    if ($lb.length) return $lb;
    $lb = $(
      '<div id="cbd-lightbox" class="cbd-lightbox" aria-hidden="true">' +
        '<button class="cbd-lb-close" aria-label="Close">✕</button>' +
        '<button class="cbd-lb-nav cbd-lb-prev" aria-label="Previous">' +
          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg></button>' +
        '<figure class="cbd-lb-stage"><img class="cbd-lb-img" alt=""><figcaption class="cbd-lb-caption"></figcaption></figure>' +
        '<button class="cbd-lb-nav cbd-lb-next" aria-label="Next">' +
          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg></button>' +
      '</div>'
    );
    $('body').append($lb);
    return $lb;
  }

  function cbdLbShow(i) {
    if (!cbdLbItems.length) return;
    cbdLbIndex = (i + cbdLbItems.length) % cbdLbItems.length; // wrap around
    var item = cbdLbItems[cbdLbIndex];
    var $lb = cbdLightbox();
    $lb.find('.cbd-lb-img').attr('src', item.src).attr('alt', item.caption);
    $lb.find('.cbd-lb-caption').text(item.caption).toggle(!!item.caption);
    var multi = cbdLbItems.length > 1;
    $lb.find('.cbd-lb-nav').toggle(multi);
  }

  function cbdLbOpen($trigger) {
    // Build the set from the album / collage the clicked photo lives in.
    var $grid = $trigger.closest('.cbd-gallery-grid, .cbd-gallery-collage');
    var $triggers = $grid.find('.cbd-lightbox-trigger');
    cbdLbItems = $triggers.map(function () {
      return { src: $(this).attr('href'), caption: $(this).data('caption') || '' };
    }).get();
    cbdLbShow($triggers.index($trigger));
    cbdLightbox().attr('aria-hidden', 'false').addClass('is-open');
    $('body').addClass('cbd-modal-open');
  }

  function cbdLbClose() {
    $('#cbd-lightbox').removeClass('is-open').attr('aria-hidden', 'true');
    $('body').removeClass('cbd-modal-open');
  }

  $(document).on('click', '.cbd-lightbox-trigger', function (e) {
    e.preventDefault();
    cbdLbOpen($(this));
  });
  $(document).on('click', '#cbd-lightbox .cbd-lb-next', function (e) { e.stopPropagation(); cbdLbShow(cbdLbIndex + 1); });
  $(document).on('click', '#cbd-lightbox .cbd-lb-prev', function (e) { e.stopPropagation(); cbdLbShow(cbdLbIndex - 1); });
  $(document).on('click', '#cbd-lightbox .cbd-lb-close', function () { cbdLbClose(); });
  // Click the backdrop (but not the image/controls) to close.
  $(document).on('click', '#cbd-lightbox', function (e) { if (e.target === this || $(e.target).hasClass('cbd-lb-stage')) cbdLbClose(); });
  $(document).on('keydown', function (e) {
    if (!$('#cbd-lightbox').hasClass('is-open')) return;
    if (e.key === 'Escape')     cbdLbClose();
    if (e.key === 'ArrowRight') cbdLbShow(cbdLbIndex + 1);
    if (e.key === 'ArrowLeft')  cbdLbShow(cbdLbIndex - 1);
  });

  /* ── [cbd_gallery]: size the full-height collage to fit under the header ── */
  function cbdGalleryFill() {
    document.querySelectorAll('.cbd-gallery-collage').forEach(function (el) {
      var h;
      if (el.classList.contains('is-fill')) {
        // Distance from the top of the viewport to the collage = header (+ anything
        // above). Fill the rest of the screen so it sits between header & footer.
        var top = el.getBoundingClientRect().top;
        h = Math.max(360, Math.round(window.innerHeight - top));
        // Only write when it actually changes — keeps the ResizeObserver below from
        // feeding back on itself, and avoids needless reflow.
        if (String(h) !== el.dataset.cbdFillH) {
          el.style.height = h + 'px';
          el.dataset.cbdFillH = String(h);
        }
      } else {
        h = el.clientHeight;
      }
      // Unit width = height × (cols/rows) so tiles stay square. The ratio comes
      // from the active tiling preset (bento 4/3, mosaic 9/3); default to 4/3.
      if (h) {
        var ratio = parseFloat(el.getAttribute('data-ratio')) || (4 / 3);
        var w = Math.round(h * ratio);
        if (String(w) !== el.dataset.cbdUnitw) {
          el.style.setProperty('--cbd-gal-unitw', w + 'px');
          el.dataset.cbdUnitw = String(w);
        }
      }
    });
  }
  cbdGalleryFill();
  // The header height (Elementor) and web fonts often aren't final at DOM-ready,
  // so the first measurement is too tall and the bento cells come out too wide
  // (only correct after a manual refresh, when those are cached). Re-measure across
  // a few frames, after window load, and whenever layout changes — so the wall
  // settles to the correct size on the first visit without any refresh.
  if (window.requestAnimationFrame) {
    requestAnimationFrame(function () { requestAnimationFrame(cbdGalleryFill); });
  }
  [80, 250, 600, 1200].forEach(function (d) { setTimeout(cbdGalleryFill, d); });
  $(window).on('resize', cbdGalleryFill);
  $(window).on('load', cbdGalleryFill); // re-measure once fonts/header settle
  if (window.ResizeObserver) {
    var cbdGalRO = new ResizeObserver(function () {
      if (window.requestAnimationFrame) { requestAnimationFrame(cbdGalleryFill); }
      else { cbdGalleryFill(); }
    });
    cbdGalRO.observe(document.documentElement);
  }

  /* ── [cbd_gallery]: reveal each photo once it loads (skeleton shimmer until) ── */
  function cbdMarkPhotoLoaded(img) {
    var photo = img.closest ? img.closest('.cbd-gallery-photo') : null;
    if (photo) { photo.classList.add('is-loaded'); }
  }
  document.querySelectorAll('.cbd-gallery-photo img').forEach(function (img) {
    // Cached images are already complete on first paint — reveal immediately.
    if (img.complete && img.naturalWidth > 0) {
      cbdMarkPhotoLoaded(img);
    } else {
      img.addEventListener('load',  function () { cbdMarkPhotoLoaded(img); });
      img.addEventListener('error', function () { cbdMarkPhotoLoaded(img); }); // stop the shimmer even if broken
    }
  });

  /* ═══════════════════════════════════════════════════════════
     [cbd_calendar] — AJAX month nav, search, Month/List views
  ═══════════════════════════════════════════════════════════ */
  function cbdShiftYM(ym, delta) {
    var p = ('' + ym).split('-'), y = +p[0], m = (+p[1]) - 1; // m → 0-indexed
    var d = new Date(y, m + delta, 1);
    return d.getFullYear() + '-' + (d.getMonth() + 1);
  }

  function cbdSetupCalendar(cal) {
    var monthBox = cal.querySelector('.cbd-cal-month');
    var agenda   = cal.querySelector('.cbd-cal-agenda');
    var dayBox   = cal.querySelector('.cbd-cal-day');
    var titleBtn = cal.querySelector('.cbd-cal-title');
    var titleTxt = cal.querySelector('.cbd-cal-title-text');
    var pickerBox= cal.querySelector('.cbd-cal-picker');
    var loader   = cal.querySelector('.cbd-cal-loader');
    var qInput   = cal.querySelector('.cbd-cal-q');
    var reqId    = 0;

    function view() { return cal.getAttribute('data-view') || 'month'; }
    function currentQ() { return qInput ? qInput.value.trim() : ''; }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function todayYMD() { return ymd(new Date()); }
    function parseYMD(s) { var p = ('' + s).split('-'); return new Date(+p[0], (+p[1] || 1) - 1, (+p[2] || 1)); }

    function updateTitle() {
      if (!titleTxt) { return; }
      titleTxt.textContent = (view() === 'day')
        ? (cal.getAttribute('data-day-title') || '')
        : (cal.getAttribute('data-month-title') || '');
    }

    function load(date, q) {
      var mine = ++reqId;
      cal.classList.add('is-loading');
      if (loader) { loader.hidden = false; }
      $.get((window.cbdData || {}).ajaxUrl, {
        action: 'cbd_calendar',
        date: date,
        view: view(),
        q: q || '',
        cat: cal.getAttribute('data-cat') || ''
      }).done(function (res) {
        if (mine !== reqId) { return; }            // a newer request superseded this
        if (res && res.success && res.data) {
          var d = res.data;
          cal.setAttribute('data-date', d.date);
          cal.setAttribute('data-ym', d.ym);
          cal.setAttribute('data-month-title', d.monthTitle);
          cal.setAttribute('data-day-title', d.dayTitle);
          monthBox.innerHTML = d.grid;
          agenda.innerHTML   = d.agenda;
          if (dayBox) { dayBox.innerHTML = d.day; }
          updateTitle();
          if (pickerOpen) { renderPicker(); }
        }
      }).always(function () {
        if (mine !== reqId) { return; }
        cal.classList.remove('is-loading');
        if (loader) { loader.hidden = true; }
      });
    }

    // Prev / Next — steps a day in Day view, a month otherwise.
    function step(delta) {
      var cur = parseYMD(cal.getAttribute('data-date') || todayYMD());
      if (view() === 'day') {
        cur.setDate(cur.getDate() + delta);
      } else {
        var dom = cur.getDate();
        cur.setDate(1);
        cur.setMonth(cur.getMonth() + delta);
        cur.setDate(Math.min(dom, new Date(cur.getFullYear(), cur.getMonth() + 1, 0).getDate()));
      }
      load(ymd(cur), currentQ());
    }
    cal.querySelector('.cbd-cal-prev').addEventListener('click', function () { step(-1); });
    cal.querySelector('.cbd-cal-next').addEventListener('click', function () { step(1); });
    cal.querySelector('.cbd-cal-today-btn').addEventListener('click', function () { load(todayYMD(), currentQ()); });

    // View switch (List / Month / Day) — all three bodies live in the DOM.
    cal.querySelectorAll('.cbd-cal-view').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var v = btn.getAttribute('data-view');
        cal.setAttribute('data-view', v);
        cal.querySelectorAll('.cbd-cal-view').forEach(function (b) {
          var on = b === btn;
          b.classList.toggle('is-active', on);
          b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        monthBox.hidden = (v !== 'month');
        agenda.hidden   = (v !== 'list');
        if (dayBox) { dayBox.hidden = (v !== 'day'); }
        updateTitle();
        closePicker();
      });
    });

    // Search — submit (Find Events / Enter) + debounced live typing.
    var searchForm = cal.querySelector('.cbd-cal-search');
    if (searchForm) {
      searchForm.addEventListener('submit', function (e) {
        e.preventDefault();
        load(cal.getAttribute('data-date'), currentQ());
      });
    }
    if (qInput) {
      var t = null;
      qInput.addEventListener('input', function () {
        clearTimeout(t);
        var v = currentQ();
        if (v.length !== 0 && v.length < 2) { return; }   // wait for a real term
        t = setTimeout(function () { load(cal.getAttribute('data-date'), v); }, 400);
      });
    }

    /* ── Date picker (mini month calendar under the title) ── */
    var pickerOpen = false, pickerView = null;            // {y, m} month shown
    var PK_DOW = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];
    var PK_MON = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var PK_PREV = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>';
    var PK_NEXT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>';

    function renderPicker() {
      if (!pickerBox || !pickerView) { return; }
      var y = pickerView.y, m = pickerView.m;             // m: 0-indexed
      var first = new Date(y, m, 1);
      var lead = (first.getDay() + 6) % 7;                 // Monday-first offset
      var cur = new Date(y, m, 1 - lead);
      var sel = cal.getAttribute('data-date');
      var today = todayYMD();
      var html = '<div class="cbd-cal-pk-head">'
        + '<button type="button" class="cbd-cal-pk-nav cbd-cal-pk-prev" aria-label="Previous month">' + PK_PREV + '</button>'
        + '<span class="cbd-cal-pk-title">' + PK_MON[m] + ' ' + y + '</span>'
        + '<button type="button" class="cbd-cal-pk-nav cbd-cal-pk-next" aria-label="Next month">' + PK_NEXT + '</button>'
        + '</div><div class="cbd-cal-pk-dows">';
      PK_DOW.forEach(function (d) { html += '<span>' + d + '</span>'; });
      html += '</div><div class="cbd-cal-pk-grid">';
      for (var i = 0; i < 42; i++) {
        var ds = ymd(cur);
        var cls = 'cbd-cal-pk-day';
        if (cur.getMonth() !== m) { cls += ' is-other'; }
        if (ds === today) { cls += ' is-today'; }
        if (ds === sel) { cls += ' is-sel'; }
        html += '<button type="button" class="' + cls + '" data-date="' + ds + '">' + cur.getDate() + '</button>';
        cur.setDate(cur.getDate() + 1);
      }
      pickerBox.innerHTML = html + '</div>';
    }

    function openPicker() {
      if (!pickerBox) { return; }
      var d = parseYMD(cal.getAttribute('data-date') || todayYMD());
      pickerView = { y: d.getFullYear(), m: d.getMonth() };
      renderPicker();
      pickerBox.classList.add('is-open');
      pickerOpen = true;
      if (titleBtn) { titleBtn.setAttribute('aria-expanded', 'true'); }
    }
    function closePicker() {
      if (!pickerBox || !pickerOpen) { return; }
      pickerBox.classList.remove('is-open');
      pickerOpen = false;
      if (titleBtn) { titleBtn.setAttribute('aria-expanded', 'false'); }
    }
    if (titleBtn) {
      titleBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        if (pickerOpen) { closePicker(); } else { openPicker(); }
      });
    }
    if (pickerBox) {
      pickerBox.addEventListener('click', function (e) {
        e.stopPropagation();
        if (e.target.closest('.cbd-cal-pk-prev')) {
          if (--pickerView.m < 0) { pickerView.m = 11; pickerView.y--; }
          renderPicker();
          return;
        }
        if (e.target.closest('.cbd-cal-pk-next')) {
          if (++pickerView.m > 11) { pickerView.m = 0; pickerView.y++; }
          renderPicker();
          return;
        }
        var day = e.target.closest('.cbd-cal-pk-day');
        if (day) { load(day.getAttribute('data-date'), currentQ()); closePicker(); }
      });
      document.addEventListener('click', closePicker);
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closePicker(); } });
    }

    // "+N more" / "Show less" expander (delegated — survives AJAX re-render).
    cal.addEventListener('click', function (e) {
      var more = e.target.closest ? e.target.closest('.cbd-cal-more') : null;
      if (!more || !cal.contains(more)) { return; }
      var cell = more.closest('.cbd-cal-cell');
      var open = cell.classList.toggle('is-expanded');
      cell.querySelectorAll('.cbd-cal-ev.is-extra').forEach(function (ev) { ev.hidden = !open; });
      var show = more.querySelector('.cbd-cal-more-show');
      var hide = more.querySelector('.cbd-cal-more-hide');
      if (show && hide) { show.hidden = open; hide.hidden = !open; }
      more.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    /* ── Rich hover preview popover ──────────────────────────────
       One node shared across the page, appended to <body> so it's
       never clipped by the grid's overflow. Delegated listeners on
       `cal` survive AJAX month re-renders. */
    var pop = document.querySelector('.cbd-cal-pop');
    if (!pop) {
      pop = document.createElement('div');
      pop.className = 'cbd-cal-pop';
      pop.hidden = true;
      document.body.appendChild(pop);
      // The card is interactive (its actions are clickable). Hovering it cancels
      // the pending hide so you can reach the buttons; leaving it hides it.
      pop.addEventListener('mouseenter', function () { clearTimeout(pop._hideT); clearTimeout(pop._fadeT); });
      pop.addEventListener('mouseleave', cbdHidePop);
      // Toggle the "Add to calendar" provider menu (delegated — innerHTML is
      // rebuilt on every hover, so we can't bind per-instance).
      pop.addEventListener('click', function (e) {
        var toggle = e.target.closest && e.target.closest('.cbd-cal-addcal-toggle');
        if (!toggle) { return; }
        e.preventDefault();
        var menu = toggle.parentNode.querySelector('.cbd-cal-addcal-menu');
        var open = !!menu && !menu.classList.contains('is-open');
        if (menu) { menu.classList.toggle('is-open', open); }
        toggle.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    }

    function cbdEsc(s) {
      return ('' + (s || '')).replace(/[&<>"]/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
      });
    }

    function cbdHidePop() {
      clearTimeout(pop._hideT); clearTimeout(pop._fadeT);
      pop._hideT = setTimeout(function () {
        pop.classList.remove('is-shown');
        pop._fadeT = setTimeout(function () { pop.hidden = true; }, 180);
      }, 120);
    }

    function cbdShowPop(ev) {
      clearTimeout(pop._hideT); clearTimeout(pop._fadeT);
      var titleEl = ev.querySelector('.cbd-cal-ev-title, .cbd-cal-agenda-title, .cbd-cal-day-title');
      var title = titleEl ? titleEl.textContent : (ev.getAttribute('aria-label') || '');
      var time  = ev.getAttribute('data-time') || '';
      var venue = ev.getAttribute('data-venue') || '';
      var ex    = ev.getAttribute('data-excerpt') || '';
      var thumb = ev.getAttribute('data-thumb') || '';
      var url   = ev.getAttribute('href') || '';
      var cal   = {};
      try { cal = JSON.parse(ev.getAttribute('data-cal') || '{}'); } catch (err) { cal = {}; }
      var label = (window.cbdData && cbdData.i18n && cbdData.i18n.viewEvent) || 'View event';
      var addLabel = (window.cbdData && cbdData.i18n && cbdData.i18n.addToCal) || 'Add to calendar';

      var html = '';
      if (thumb) { html += '<div class="cbd-cal-pop-img" style="background-image:url(\'' + thumb.replace(/'/g, '%27') + '\')"></div>'; }
      html += '<div class="cbd-cal-pop-body">';
      if (time)  { html += '<div class="cbd-cal-pop-time">' + cbdEsc(time) + '</div>'; }
      html += '<div class="cbd-cal-pop-title">' + cbdEsc(title) + '</div>';
      if (venue) { html += '<div class="cbd-cal-pop-venue">' + cbdEsc(venue) + '</div>'; }
      if (ex)    { html += '<p class="cbd-cal-pop-ex">' + cbdEsc(ex) + '</p>'; }
      html += '<div class="cbd-cal-pop-actions">';
      html += '<a class="cbd-cal-pop-btn cbd-cal-pop-view" href="' + cbdEsc(url) + '">' + cbdEsc(label) + ' →</a>';
      var providers = [
        ['google', 'Google Calendar', false],
        ['ics',    'iCalendar',       true],   // .ics download
        ['o365',   'Outlook 365',     false],
        ['olive',  'Outlook Live',    false]
      ];
      var menu = '';
      providers.forEach(function (p) {
        var href = cal[p[0]];
        if (!href) { return; }
        var attrs = p[2] ? ' download="event.ics"' : ' target="_blank" rel="noopener"';
        menu += '<a class="cbd-cal-addcal-item" href="' + cbdEsc(href) + '"' + attrs + '>' + cbdEsc(p[1]) + '</a>';
      });
      if (menu) {
        html += '<div class="cbd-cal-addcal">'
             + '<button type="button" class="cbd-cal-pop-btn cbd-cal-addcal-toggle" aria-expanded="false">'
             + '<svg class="cbd-cal-addcal-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="12" y1="13.5" x2="12" y2="18"/><line x1="9.75" y1="15.75" x2="14.25" y2="15.75"/></svg>'
             + '<span>' + cbdEsc(addLabel) + '</span>'
             + '<svg class="cbd-cal-addcal-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>'
             + '</button>'
             + '<div class="cbd-cal-addcal-menu">' + menu + '</div>'
             + '</div>';
      }
      html += '</div></div>';
      pop.innerHTML = html;
      pop.hidden = false;

      // Position once dimensions are known: below the event, flipping above
      // when there isn't room, clamped to the viewport horizontally.
      var r = ev.getBoundingClientRect();
      var pw = pop.offsetWidth, ph = pop.offsetHeight;
      var vw = document.documentElement.clientWidth, vh = window.innerHeight;
      var left = Math.max(12, Math.min(r.left + r.width / 2 - pw / 2, vw - pw - 12));
      var top  = r.bottom + 8;
      if (top + ph > vh - 12) { top = r.top - ph - 8; }
      if (top < 12) { top = 12; }
      pop.style.left = (left + window.pageXOffset) + 'px';
      pop.style.top  = (top + window.pageYOffset) + 'px';
      requestAnimationFrame(function () { pop.classList.add('is-shown'); });
    }

    cal.addEventListener('mouseover', function (e) {
      var ev = e.target.closest ? e.target.closest('.cbd-cal-ev, .cbd-cal-agenda-ev, .cbd-cal-day-card') : null;
      if (!ev || !cal.contains(ev) || ev.hidden) { return; }
      if (pop._cur === ev && pop.classList.contains('is-shown')) { return; }
      pop._cur = ev;
      cbdShowPop(ev);
    });
    cal.addEventListener('mouseout', function (e) {
      var ev = e.target.closest ? e.target.closest('.cbd-cal-ev, .cbd-cal-agenda-ev, .cbd-cal-day-card') : null;
      if (!ev) { return; }
      var to = e.relatedTarget;
      if (to && (ev.contains(to) || pop.contains(to))) { return; }
      pop._cur = null;
      cbdHidePop();
    });
    cal.addEventListener('focusin', function (e) {
      var ev = e.target.closest ? e.target.closest('.cbd-cal-ev, .cbd-cal-agenda-ev, .cbd-cal-day-card') : null;
      if (ev && !ev.hidden) { pop._cur = ev; cbdShowPop(ev); }
    });
    cal.addEventListener('focusout', function () { pop._cur = null; cbdHidePop(); });
    window.addEventListener('scroll', function () {
      if (pop && !pop.hidden) { pop._cur = null; cbdHidePop(); }
    }, true);
  }

  document.querySelectorAll('.cbd-cal').forEach(cbdSetupCalendar);

  /* ═══════════════════════════════════════════════════════════
     PROFILE SETTINGS MENU · GENERIC MODALS · DELEGATES · READ-MORE
  ═══════════════════════════════════════════════════════════ */
  // Settings dropdown (owner/delegate only).
  $(document).on('click', '#cbd-settings-toggle', function (e) {
    e.stopPropagation();
    var open = $('#cbd-profile-settings').toggleClass('is-open').hasClass('is-open');
    $(this).attr('aria-expanded', open ? 'true' : 'false');
  });
  $(document).on('click', '.cbd-settings-menu', function (e) { e.stopPropagation(); });
  $(document).on('click', function () { $('#cbd-profile-settings').removeClass('is-open'); });

  // Generic modal open (settings items + "add" buttons) / close.
  $(document).on('click', '[data-cbd-open]', function (e) {
    e.preventDefault();
    $('#cbd-profile-settings').removeClass('is-open');
    var target = $(this).data('cbd-open');
    openModal(target);
    // Textareas measure 0 while hidden — recalc once the modal is visible.
    setTimeout(function () { $(target).find('textarea').each(function () { cbdAutoGrow(this); }); }, 230);
  });
  $(document).on('click', '.cbd-modal-close', function () {
    var $w = $(this).closest('.cbd-modal-wrap');
    if ($w.length) closeModal('#' + $w.attr('id'));
  });

  // Legacy deep-link: older account-menu links used ?cbd_panel=analytics|usage to
  // open a modal. Analytics & Plan Usage are now inline profile-body panels reached
  // via ?biz_tab=…, so bounce any stale ?cbd_panel link to the matching tab.
  $(function () {
    var panel = (window.location.search.match(/[?&]cbd_panel=([a-z]+)/) || [])[1];
    if (panel !== 'analytics' && panel !== 'usage') return;
    var u = new URL(window.location.href);
    u.searchParams.delete('cbd_panel');
    u.searchParams.set('biz_tab', panel);
    window.location.replace(u.toString());
  });

  // Delegate access — add / remove.
  $(document).on('submit', '#cbd-delegate-form', function (e) {
    e.preventDefault();
    var $form = $(this), $msg = $form.find('.cbd-form-msg');
    var user = $.trim($form.find('[name=user]').val() || '');
    if (!user) return;
    $msg.hide().removeClass('cbd-notice-success cbd-notice-error');
    $.post(cbdData.ajaxUrl, { action: 'cbd_save_delegates', op: 'add', business_id: $form.data('business-id'), user: user, cbd_nonce: cbdData.nonce },
      function (res) {
        if (res.success) {
          $('#cbd-delegate-list-wrap').html(res.data.html);
          $form.find('[name=user]').val('');
          $msg.addClass('cbd-notice-success').text(res.data.message).show();
        } else {
          $msg.addClass('cbd-notice-error').text((res.data && res.data.message) || cbdData.i18n.error).show();
        }
      });
  });
  $(document).on('click', '.cbd-delegate-remove', function () {
    var uid = $(this).data('user-id'), bizId = $('#cbd-delegate-form').data('business-id');
    $.post(cbdData.ajaxUrl, { action: 'cbd_save_delegates', op: 'remove', business_id: bizId, user_id: uid, cbd_nonce: cbdData.nonce },
      function (res) { if (res.success) $('#cbd-delegate-list-wrap').html(res.data.html); });
  });
  // Per-delegate permission toggle — saves immediately. We don't re-render the
  // list (the checkboxes already reflect the new state) to avoid losing the
  // owner's place while toggling several.
  $(document).on('change', '.cbd-delegate-perm-cb', function () {
    var $row = $(this).closest('.cbd-delegate-row');
    var uid = $row.data('user-id');
    var bizId = $('#cbd-delegate-form').data('business-id');
    var perms = $row.find('.cbd-delegate-perm-cb:checked').map(function () { return this.value; }).get();
    var $cb = $(this).prop('disabled', true);
    $.post(cbdData.ajaxUrl, { action: 'cbd_save_delegates', op: 'perms', business_id: bizId, user_id: uid, perms: perms, cbd_nonce: cbdData.nonce })
      .always(function () { $cb.prop('disabled', false); });
  });
  // Pending invitation — resend / cancel.
  $(document).on('click', '.cbd-invite-resend', function () {
    var email = $(this).data('email'), bizId = $('#cbd-delegate-form').data('business-id');
    var $msg = $('#cbd-delegate-form .cbd-form-msg');
    $.post(cbdData.ajaxUrl, { action: 'cbd_save_delegates', op: 'add', business_id: bizId, user: email, cbd_nonce: cbdData.nonce },
      function (res) {
        if (res.success) {
          $('#cbd-delegate-list-wrap').html(res.data.html);
          $msg.removeClass('cbd-notice-error').addClass('cbd-notice-success').text(res.data.message).show();
        }
      });
  });
  $(document).on('click', '.cbd-invite-cancel', function () {
    var email = $(this).data('email'), bizId = $('#cbd-delegate-form').data('business-id');
    $.post(cbdData.ajaxUrl, { action: 'cbd_save_delegates', op: 'cancel_invite', business_id: bizId, email: email, cbd_nonce: cbdData.nonce },
      function (res) { if (res.success) $('#cbd-delegate-list-wrap').html(res.data.html); });
  });

  // News-feed "Read More" (in the reactions bar) — open the full feed item
  // content in a modal. Content is read from the hidden .cbd-feed-full holder.
  function cbdFeedModal() {
    var $m = $('#cbd-feed-modal');
    if ($m.length) return $m;
    $m = $(
      '<div id="cbd-feed-modal" class="cbd-modal-wrap" style="display:none;">' +
        '<div class="cbd-modal cbd-feed-modal-inner">' +
          '<button type="button" class="cbd-modal-close" aria-label="Close">✕</button>' +
          '<div class="cbd-feed-modal-byline"></div>' +
          '<div class="cbd-feed-modal-body">' +
            '<div class="cbd-feed-modal-media"></div>' +
            '<h3 class="cbd-feed-modal-title"></h3>' +
            '<div class="cbd-feed-modal-content"></div>' +
          '</div>' +
          '<div class="cbd-feed-modal-footer">' +
            '<div class="cbd-feed-modal-react"></div>' +
            '<a class="cbd-btn cbd-btn-primary cbd-feed-modal-visit" href="#"></a>' +
          '</div>' +
        '</div>' +
      '</div>'
    );
    $('body').append($m);
    return $m;
  }

  $(document).on('click', '.cbd-feed-readmore-btn', function () {
    var $item = $(this).closest('.cbd-feed-item');
    if (!$item.length) return;
    var $m = cbdFeedModal();

    // Byline — rebuilt from the card's parts as a compact identity block:
    // avatar on the left, business name on top, "verb + type" beneath.
    var $bl = $m.find('.cbd-feed-modal-byline').empty();
    var $logo = $item.find('.cbd-feed-biz-logo').first();
    var name  = $item.find('.cbd-feed-biz-name').first().text();
    var verb  = $item.find('.cbd-feed-verb').first().text();
    var typeHtml = $item.find('.cbd-feed-type').first().html() || '';
    if ($logo.length || name) {
      var $id = $('<div class="cbd-feed-modal-id"></div>');
      if ($logo.length) { $id.append($logo.clone().removeAttr('width height loading')); }
      var $txt = $('<div class="cbd-feed-modal-id-text"></div>');
      if (name) { $txt.append($('<strong class="cbd-feed-modal-id-name"></strong>').text(name)); }
      var $sub = $('<span class="cbd-feed-modal-id-sub"></span>');
      if (verb) { $sub.append(document.createTextNode(verb)); }
      if (typeHtml) { $sub.append($('<span class="cbd-feed-type"></span>').html(typeHtml)); }
      $txt.append($sub);
      $id.append($txt);
      $bl.append($id);
    }
    $m.find('.cbd-feed-modal-title').text($item.find('.cbd-feed-title').text().replace(/\s+/g, ' ').trim());

    var img = $item.find('.cbd-feed-img').attr('src');
    var $media = $m.find('.cbd-feed-modal-media').empty();
    if (img) { $media.append($('<img>', { src: img, alt: '' })); }

    var full = $item.find('.cbd-feed-full').html();
    if (!full) { full = '<p>' + cbdEsc($item.find('.cbd-feed-excerpt').text()) + '</p>'; }
    $m.find('.cbd-feed-modal-content').html(full);

    // Reaction bar — clone the card's own (minus the Read More button) so the
    // delegated reaction handlers operate on it, carrying the right post id.
    var $react = $m.find('.cbd-feed-modal-react').empty();
    var $srcReact = $item.find('.cbd-reactions').first();
    if ($srcReact.length) {
      var $clone = $srcReact.clone();
      $clone.find('.cbd-feed-readmore-btn').remove();
      $clone.find('.cbd-react-btn-wrap').removeClass('cbd-picker-open');
      $react.append($clone);
    }

    // Visit button → the item's own public page when provided (events/promotions
    // deep-link to their single page via data-cbd-visit), else the title link.
    var visitHref = $item.attr('data-cbd-visit') || $item.find('.cbd-feed-title a').attr('href') || '';
    $m.find('.cbd-feed-modal-visit')
      .attr('href', visitHref || '#')
      .toggle(!!visitHref)
      .text(cbdData.i18n.visit || 'Visit');

    openModal('#cbd-feed-modal');
  });

  // Deep-link scroll: feed links land on a business profile tab and target a
  // specific item (#cbd-item-<id>). Smooth-scroll past the header and flash it.
  function cbdScrollToHashItem() {
    var hash = window.location.hash;
    if (!hash || hash.indexOf('#cbd-item-') !== 0) return;
    var el = document.getElementById(hash.slice(1));
    if (!el) return;
    // Defer so layout (images, fonts) has settled before measuring.
    setTimeout(function () {
      var top = el.getBoundingClientRect().top + window.pageYOffset - 90;
      window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
      el.classList.add('cbd-item-flash');
      setTimeout(function () { el.classList.remove('cbd-item-flash'); }, 2200);
    }, 320);
  }
  $(cbdScrollToHashItem);

  /* ═══════════════════════════════════════════════════════════
     IMAGE CROPPER — aspect-locked, pan + zoom, no dependencies.
     File inputs opt in with data-crop-aspect / data-crop-w / data-crop-h.
  ═══════════════════════════════════════════════════════════ */
  var cbdCrop = null; // active crop session

  function cbdCropModal() {
    var $m = $('#cbd-cropper');
    if ($m.length) return $m;
    $m = $(
      '<div id="cbd-cropper" class="cbd-cropper" aria-hidden="true">' +
        '<div class="cbd-cropper-box" role="dialog" aria-modal="true">' +
          '<div class="cbd-cropper-head"><h3 class="cbd-cropper-title"></h3>' +
            '<button type="button" class="cbd-cropper-x" aria-label="Close">✕</button></div>' +
          '<div class="cbd-cropper-stage"><div class="cbd-cropper-viewport">' +
            '<img class="cbd-cropper-img" alt="" draggable="false"></div></div>' +
          '<div class="cbd-cropper-controls">' +
            '<button type="button" class="cbd-cropper-zoom-out" aria-label="Zoom out">−</button>' +
            '<input type="range" class="cbd-cropper-zoom" min="1" max="4" step="0.01" value="1" aria-label="Zoom">' +
            '<button type="button" class="cbd-cropper-zoom-in" aria-label="Zoom in">+</button>' +
          '</div>' +
          '<p class="cbd-cropper-hint"></p>' +
          '<div class="cbd-cropper-actions">' +
            '<button type="button" class="cbd-btn cbd-btn-ghost cbd-cropper-cancel">' + (cbdData.i18n.cancel || 'Cancel') + '</button>' +
            '<button type="button" class="cbd-btn cbd-btn-primary cbd-cropper-apply">' + (cbdData.i18n.apply_crop || 'Apply crop') + '</button>' +
          '</div>' +
        '</div>' +
      '</div>'
    );
    $('body').append($m);
    return $m;
  }

  function cbdCropClamp() {
    var c = cbdCrop; if (!c) return;
    var dispW = c.natW * c.scale, dispH = c.natH * c.scale;
    var minX = c.vw - dispW, minY = c.vh - dispH;
    if (c.x > 0) c.x = 0; if (c.x < minX) c.x = minX;
    if (c.y > 0) c.y = 0; if (c.y < minY) c.y = minY;
    if (dispW <= c.vw) c.x = (c.vw - dispW) / 2; // never smaller than the frame
    if (dispH <= c.vh) c.y = (c.vh - dispH) / 2;
  }

  function cbdCropRender() {
    var c = cbdCrop; if (!c) return;
    cbdCropClamp();
    var img = c.$img[0];
    img.style.width  = (c.natW * c.scale) + 'px';
    img.style.height = (c.natH * c.scale) + 'px';
    img.style.left   = c.x + 'px';
    img.style.top    = c.y + 'px';
  }

  function cbdCropOpen(input, file) {
    var aspect = parseFloat(input.getAttribute('data-crop-aspect')) || 1;
    var outW   = parseInt(input.getAttribute('data-crop-w'), 10) || 0;
    var outH   = parseInt(input.getAttribute('data-crop-h'), 10) || 0;
    if (!outW || !outH) { outW = 800; outH = Math.round(800 / aspect); }

    var url = URL.createObjectURL(file);
    var im  = new Image();
    im.onload = function () {
      var $m = cbdCropModal();
      var vw = aspect >= 2 ? 440 : 360;          // viewport locked to the ratio
      var vh = Math.round(vw / aspect);
      if (vh > 380) { vh = 380; vw = Math.round(vh * aspect); }

      var $vp  = $m.find('.cbd-cropper-viewport').css({ width: vw + 'px', height: vh + 'px' });
      var $img = $m.find('.cbd-cropper-img').attr('src', url);
      var base = Math.max(vw / im.naturalWidth, vh / im.naturalHeight); // cover-fit

      cbdCrop = {
        input: input, file: file, url: url, aspect: aspect, outW: outW, outH: outH,
        $img: $img, img: im, natW: im.naturalWidth, natH: im.naturalHeight,
        vw: vw, vh: vh, base: base, scale: base, x: 0, y: 0, dragging: false
      };
      cbdCrop.x = (vw - im.naturalWidth  * base) / 2;
      cbdCrop.y = (vh - im.naturalHeight * base) / 2;

      var label = (input.getAttribute('data-crop-aspect') === '1') ? 'Crop logo' : 'Crop image';
      $m.find('.cbd-cropper-title').text(cbdData.i18n.crop_image || label);
      $m.find('.cbd-cropper-hint').text('Drag to reposition · zoom with the slider · exports ' + outW + '×' + outH + 'px');
      $m.find('.cbd-cropper-zoom').val(1);
      cbdCropRender();
      $m.attr('aria-hidden', 'false').addClass('is-open');
      $('body').addClass('cbd-modal-open');
    };
    im.onerror = function () { URL.revokeObjectURL(url); };
    im.src = url;
  }

  function cbdCropClose(clearInput) {
    var c = cbdCrop;
    $('#cbd-cropper').removeClass('is-open').attr('aria-hidden', 'true');
    $('body').removeClass('cbd-modal-open');
    if (c) {
      if (c.url) URL.revokeObjectURL(c.url);
      if (clearInput) { try { c.input.value = ''; } catch (e) {} }
      cbdCrop = null;
    }
  }

  function cbdCropApply() {
    var c = cbdCrop; if (!c) return;
    var sx = -c.x / c.scale, sy = -c.y / c.scale;
    var sw = c.vw / c.scale,  sh = c.vh / c.scale;

    var canvas = document.createElement('canvas');
    canvas.width = c.outW; canvas.height = c.outH;
    var ctx = canvas.getContext('2d');
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(c.img, sx, sy, sw, sh, 0, 0, c.outW, c.outH);

    var isPng = /png$/i.test(c.file.type);
    var type  = isPng ? 'image/png' : 'image/jpeg';
    var ext   = isPng ? 'png' : 'jpg';
    var nameBase = (c.file.name || 'image').replace(/\.[^.]+$/, '');
    var input = c.input, aspect = c.aspect;

    canvas.toBlob(function (blob) {
      if (!blob) { cbdCropClose(false); return; }
      try {
        var dt = new DataTransfer();
        dt.items.add(new File([blob], nameBase + '-cropped.' + ext, { type: type }));
        input.files = dt.files;
      } catch (e) { /* DataTransfer unsupported — original file is kept */ }
      cbdSetCropPreview(input, URL.createObjectURL(blob), aspect);
      cbdCropClose(false);
    }, type, 0.9);
  }

  function cbdSetCropPreview(input, url, aspect) {
    // Profile-photo input drives the account avatar preview directly.
    if (input.hasAttribute('data-cbd-avatar-input')) {
      $('[data-cbd-avatar-preview]').attr('src', url);
      return;
    }
    var $row = $(input).closest('.cbd-form-row');
    var $thumb = $row.find('.cbd-media-thumb'); // dashboard already has one
    if ($thumb.length) {
      $thumb.css({ 'background-image': 'url(' + url + ')', 'background-size': 'cover', 'background-position': 'center' }).empty();
      return;
    }
    var $pv = $row.find('.cbd-crop-preview');
    if (!$pv.length) { $pv = $('<div class="cbd-crop-preview" aria-hidden="true"></div>').insertAfter(input); }
    $pv.css('background-image', 'url(' + url + ')').toggleClass('is-wide', aspect > 1.6);
  }

  // Open the cropper whenever a crop-enabled file input receives an image.
  $(document).on('change', 'input[type=file][data-crop-aspect]', function () {
    var file = this.files && this.files[0];
    if (file && /^image\//.test(file.type)) cbdCropOpen(this, file);
  });

  // Drag to pan (pointer events handle mouse + touch).
  $(document).on('pointerdown', '#cbd-cropper .cbd-cropper-viewport', function (e) {
    if (!cbdCrop) return;
    cbdCrop.dragging = true; cbdCrop.px = e.clientX; cbdCrop.py = e.clientY;
    if (this.setPointerCapture) { try { this.setPointerCapture(e.pointerId); } catch (err) {} }
    e.preventDefault();
  });
  $(document).on('pointermove', '#cbd-cropper .cbd-cropper-viewport', function (e) {
    if (!cbdCrop || !cbdCrop.dragging) return;
    cbdCrop.x += e.clientX - cbdCrop.px; cbdCrop.y += e.clientY - cbdCrop.py;
    cbdCrop.px = e.clientX; cbdCrop.py = e.clientY;
    cbdCropRender();
  });
  $(document).on('pointerup pointercancel pointerleave', '#cbd-cropper .cbd-cropper-viewport', function () {
    if (cbdCrop) cbdCrop.dragging = false;
  });

  // Zoom (slider + buttons), anchored on the viewport centre.
  $(document).on('input', '#cbd-cropper .cbd-cropper-zoom', function () {
    if (!cbdCrop) return;
    var prev = cbdCrop.scale;
    cbdCrop.scale = cbdCrop.base * (parseFloat(this.value) || 1);
    var cx = cbdCrop.vw / 2, cy = cbdCrop.vh / 2, r = cbdCrop.scale / prev;
    cbdCrop.x = cx - (cx - cbdCrop.x) * r;
    cbdCrop.y = cy - (cy - cbdCrop.y) * r;
    cbdCropRender();
  });
  $(document).on('click', '#cbd-cropper .cbd-cropper-zoom-in', function () {
    var $z = $('#cbd-cropper .cbd-cropper-zoom'); $z.val(Math.min(4, (parseFloat($z.val()) || 1) + 0.2)).trigger('input');
  });
  $(document).on('click', '#cbd-cropper .cbd-cropper-zoom-out', function () {
    var $z = $('#cbd-cropper .cbd-cropper-zoom'); $z.val(Math.max(1, (parseFloat($z.val()) || 1) - 0.2)).trigger('input');
  });

  $(document).on('click', '#cbd-cropper .cbd-cropper-apply', cbdCropApply);
  $(document).on('click', '#cbd-cropper .cbd-cropper-x, #cbd-cropper .cbd-cropper-cancel', function () { cbdCropClose(true); });
  $(document).on('click', '#cbd-cropper', function (e) { if (e.target === this) cbdCropClose(true); });
  $(document).on('keydown', function (e) {
    if (e.key === 'Escape' && $('#cbd-cropper').hasClass('is-open')) cbdCropClose(true);
  });

  // ── Account dropdown (header avatar menu) ──────────────────────
  function cbdCloseAccountMenus($keep) {
    $('.cbd-account').each(function () {
      var $a = $(this);
      if ($keep && $a.is($keep)) return;
      $a.find('.cbd-account-menu').attr('hidden', true);
      $a.find('.cbd-account-avatar').attr('aria-expanded', 'false');
      $a.find('.cbd-menu-parent.is-open').removeClass('is-open')
        .find('[data-cbd-submenu-toggle]').attr('aria-expanded', 'false');
    });
  }
  $(document).on('click', '.cbd-account-avatar', function (e) {
    e.stopPropagation();
    var $a = $(this).closest('.cbd-account'),
        $m = $a.find('.cbd-account-menu'),
        open = !$m.attr('hidden');
    cbdCloseAccountMenus(open ? null : $a);
    if (open) {
      $m.attr('hidden', true);
      $(this).attr('aria-expanded', 'false');
    } else {
      $m.removeAttr('hidden');
      $(this).attr('aria-expanded', 'true');
    }
  });
  // "My Page" → businesses flyout: tap/click toggle (hover is handled in CSS).
  $(document).on('click', '[data-cbd-submenu-toggle]', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $parent = $(this).closest('.cbd-menu-parent');
    var open = $parent.toggleClass('is-open').hasClass('is-open');
    $(this).attr('aria-expanded', open ? 'true' : 'false');
  });
  $(document).on('click', function (e) {
    if (!$(e.target).closest('.cbd-account').length) cbdCloseAccountMenus();
  });
  $(document).on('keydown', function (e) {
    if (e.key === 'Escape') cbdCloseAccountMenus();
  });

  // Dark mode toggle (persists in localStorage; theme can hook on [data-cbd-theme="dark"]).
  function cbdApplyDarkMode(on) {
    document.documentElement.setAttribute('data-cbd-theme', on ? 'dark' : 'light');
    $('[data-cbd-dark-switch]').toggleClass('is-on', !!on);
    $('[data-cbd-dark-toggle]').attr('aria-pressed', on ? 'true' : 'false');
  }
  try { cbdApplyDarkMode(localStorage.getItem('cbdDarkMode') === '1'); } catch (e) {}
  $(document).on('click keydown', '[data-cbd-dark-toggle]', function (e) {
    if (e.type === 'keydown' && e.key !== ' ' && e.key !== 'Enter') return;
    e.preventDefault();
    var next = document.documentElement.getAttribute('data-cbd-theme') !== 'dark';
    try { localStorage.setItem('cbdDarkMode', next ? '1' : '0'); } catch (e2) {}
    cbdApplyDarkMode(next);
  });

  // ── Login form + social sign-in ────────────────────────────────
  function cbdLoginMsg(text, kind) {
    var $m = $('#cbd-login-msg');
    if (!$m.length) return;
    $m.removeClass('is-error is-success').text(text || '').addClass('is-' + (kind || 'error'));
  }
  function cbdLoginRedirect(url) {
    window.location.assign(url || (cbdData && cbdData.loginUrl) || '/');
  }

  $(document).on('submit', '#cbd-login-form', function (e) {
    e.preventDefault();
    var $form = $(this), $btn = $form.find('.cbd-login-submit');
    cbdLoginMsg('', 'error');
    $btn.prop('disabled', true).data('orig', $btn.text()).text(cbdData.i18n.loading || 'Loading…');
    $.post(cbdData.ajaxUrl, $form.serialize())
      .done(function (r) {
        if (r && r.success) {
          cbdLoginMsg('Signed in — redirecting…', 'success');
          cbdLoginRedirect(r.data && r.data.redirect);
        } else {
          cbdLoginMsg((r && r.data && r.data.message) || cbdData.i18n.error);
          $btn.prop('disabled', false).text($btn.data('orig'));
        }
      })
      .fail(function (xhr) {
        var data = (xhr.responseJSON && xhr.responseJSON.data) || {};
        var msg = data.message || cbdData.i18n.error;
        cbdLoginMsg(msg);
        // Unverified account → offer a one-click "resend verification" link.
        if (data.code === 'unverified' && data.email) {
          $('#cbd-login-msg').append(cbdResendLink(data.email));
        }
        $btn.prop('disabled', false).text($btn.data('orig'));
      });
  });

  // ── Forgot password (email a reset link) ──────────────────────
  $(document).on('submit', '#cbd-forgot-form', function (e) {
    e.preventDefault();
    cbdSubmitForm($(this));
  });

  // ── Set / reset password page ([cbd_set_password]) ─────────────
  function cbdGeneratePassword(len) {
    var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%^&*';
    var out = '';
    var rnd = (window.crypto && window.crypto.getRandomValues) ? window.crypto.getRandomValues(new Uint32Array(len)) : null;
    for (var i = 0; i < len; i++) {
      var n = rnd ? rnd[i] : Math.floor(Math.random() * 0xffffffff);
      out += chars.charAt(n % chars.length);
    }
    return out;
  }
  $(document).on('click', '[data-cbd-genpw]', function () {
    var pw = cbdGeneratePassword(16);
    var $f = $(this).closest('form');
    $f.find('[name=password], [name=password_confirm]').val(pw).attr('type', 'text');
  });
  $(document).on('click', '[data-cbd-togglepw]', function () {
    var $i = $('#cbd-setpw-pass');
    $i.attr('type', $i.attr('type') === 'password' ? 'text' : 'password');
  });
  $(document).on('submit', '#cbd-setpw-form', function (e) {
    e.preventDefault();
    var $form = $(this);
    var p = $form.find('[name=password]').val(), c = $form.find('[name=password_confirm]').val();
    if (p !== c) {
      $form.find('.cbd-form-msg').removeClass('cbd-notice-success').addClass('cbd-notice-error').text('The two passwords do not match.').show();
      return;
    }
    cbdSubmitForm($form);
  });

  // ── Sign up (create account → email verification) ──────────────
  function cbdSignupMsg(text, kind) {
    var $m = $('#cbd-signup-msg');
    if (!$m.length) return;
    $m.removeClass('is-error is-success').text(text || '').addClass('is-' + (kind || 'error'));
  }

  // A "Resend verification email" button bound to a specific address. Shared by
  // the login (unverified) path and the post-signup confirmation panel.
  function cbdResendLink(email) {
    return ' <button type="button" class="cbd-signup-resend" data-email="' +
      $('<div>').text(email).html() + '">' +
      'Resend verification email</button>';
  }

  $(document).on('submit', '#cbd-signup-form', function (e) {
    e.preventDefault();
    var $form = $(this), $btn = $form.find('.cbd-login-submit');
    var pass = $form.find('[name=password]').val(), pass2 = $form.find('[name=password_confirm]').val();
    cbdSignupMsg('', 'error');
    if (pass !== pass2) {
      cbdSignupMsg('The two passwords do not match.');
      return;
    }
    $btn.prop('disabled', true).data('orig', $btn.text()).text(cbdData.i18n.loading || 'Loading…');
    $.post(cbdData.ajaxUrl, $form.serialize())
      .done(function (r) {
        if (r && r.success) {
          var email = $form.find('[name=email]').val();
          $('#cbd-signup').html(
            '<div class="cbd-signup-done">' +
              '<div class="cbd-signup-icon">✓</div>' +
              '<h3>Check your inbox</h3>' +
              '<p>' + ((r.data && r.data.message) || 'Account created! Check your email for a link to activate it.') + '</p>' +
              '<p>Didn’t get it?' + cbdResendLink(email) + '</p>' +
            '</div>'
          );
        } else {
          cbdSignupMsg((r && r.data && r.data.message) || cbdData.i18n.error);
          $btn.prop('disabled', false).text($btn.data('orig'));
        }
      })
      .fail(function (xhr) {
        var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || cbdData.i18n.error;
        cbdSignupMsg(msg);
        $btn.prop('disabled', false).text($btn.data('orig'));
      });
  });

  $(document).on('click', '.cbd-signup-resend', function () {
    var $btn = $(this), email = $btn.data('email');
    if (!email) return;
    $btn.prop('disabled', true).text('Sending…');
    $.post(cbdData.ajaxUrl, {
      action: 'cbd_resend_verification',
      cbd_nonce: cbdData.nonce,
      email: email
    }).always(function (r) {
      var msg = (r && r.data && r.data.message) || 'If that account needs verifying, a fresh link is on its way.';
      $btn.replaceWith($('<span>').css({ fontSize: '13px', color: '#059669' }).text(msg));
    });
  });

  // Social sign-in (Google / Facebook / Twitter-X / Instagram) is handled
  // entirely server-side via the OAuth redirect flow in PHP (SocialAuth) — the
  // buttons are plain links, so there is no client-side SDK code here.

}(jQuery));
