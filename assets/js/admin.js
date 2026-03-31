/* Content Engine Pro — Admin JS */
(function ($) {
  'use strict';

  // Toggle password field visibility
  $(document).on('click', '.cep-toggle-pw', function () {
    var targetId = $(this).data('target');
    var $input = $('#' + targetId);
    if ($input.attr('type') === 'password') {
      $input.attr('type', 'text');
      $(this).text('Hide');
    } else {
      $input.attr('type', 'password');
      $(this).text('Show');
    }
  });

  // AI provider toggle: show/hide relevant sections
  function toggleAiSections() {
    var provider = $('#cep_ai_provider').val();
    if (provider === 'azure') {
      $('#cep-azure-section').show();
      $('#cep-openai-section').hide();
    } else {
      $('#cep-openai-section').show();
      $('#cep-azure-section').hide();
    }
  }

  $('#cep_ai_provider').on('change', toggleAiSections);
  toggleAiSections();

  // Confirm delete actions
  $(document).on('click', '.button-link-delete', function (e) {
    if (!confirm('Are you sure? This action cannot be undone.')) {
      e.preventDefault();
    }
  });

  // ── Autopilot AJAX trigger buttons ──────────────────────────────────────
  $(document).on('click', '.cep-trigger-btn', function () {
    var $btn     = $(this);
    var $card    = $btn.closest('.cep-autopilot-card, .cep-pipeline-banner');
    var trigger  = $btn.data('trigger');

    if ($btn.hasClass('cep-running') || $btn.prop('disabled')) return;

    var originalHtml = $btn.html();
    $btn.addClass('cep-running').prop('disabled', true)
        .html('<span class="cep-btn-spinner"></span> Running&hellip;');
    $card.addClass('cep-autopilot-card--running');

    $.ajax({
      url:    cepAdmin.ajaxUrl,
      method: 'POST',
      data: {
        action:  'cep_run_trigger',
        trigger: trigger,
        nonce:   cepAdmin.nonce
      },
      success: function (res) {
        $card.removeClass('cep-autopilot-card--running');
        if (res.success) {
          $card.addClass('cep-autopilot-card--success');
          $btn.html('✓ Done').removeClass('cep-running');
          cepShowNotice(res.data.message, 'success');
          setTimeout(function () {
            $card.removeClass('cep-autopilot-card--success');
            $btn.html(originalHtml).prop('disabled', false);
          }, 2500);
        } else {
          cepShowNotice(res.data.message || 'Something went wrong.', 'error');
          $card.addClass('cep-autopilot-card--error');
          $btn.html('✗ Failed').removeClass('cep-running');
          setTimeout(function () {
            $card.removeClass('cep-autopilot-card--error');
            $btn.html(originalHtml).prop('disabled', false);
          }, 2500);
        }
      },
      error: function () {
        $card.removeClass('cep-autopilot-card--running').addClass('cep-autopilot-card--error');
        $btn.html('✗ Error').removeClass('cep-running').prop('disabled', false);
        cepShowNotice('Request failed. Check your connection and try again.', 'error');
        setTimeout(function () {
          $card.removeClass('cep-autopilot-card--error');
          $btn.html(originalHtml);
        }, 2500);
      }
    });
  });

  function cepShowNotice(message, type) {
    var $n = $('<div class="cep-ajax-notice cep-ajax-notice--' + type + '">' + message + '</div>');
    $('body').append($n.hide().fadeIn(220));
    setTimeout(function () {
      $n.fadeOut(300, function () { $n.remove(); });
    }, 5000);
  }

  // ── SEO Agent AJAX actions ──────────────────────────────────────────────

  function cepSeoToast(message, type) {
    var icon = type === 'success' ? '✅' : '❌';
    var $t = $('<div class="cep-seo-toast cep-seo-toast--' + type + '"><span class="cep-seo-toast__icon">' + icon + '</span><span>' + message + '</span></div>');
    $('body').append($t.hide().fadeIn(200));
    setTimeout(function () { $t.fadeOut(300, function () { $t.remove(); }); }, 6000);
  }

  // Run Analysis Now — supports both the banner button and the empty-state button
  $(document).on('click', '.cep-seo-run-btn', function () {
    var $btn = $(this);
    if ($btn.prop('disabled')) return;

    // Animate the banner button specifically
    var $bannerBtn = $('#cep-seo-run-btn');
    $bannerBtn.prop('disabled', true).addClass('cep-seo-run-btn--loading');
    $bannerBtn.find('.cep-seo-run-btn__text').text('Scanning\u2026');

    // Show progress bar
    $('#cep-seo-progress').slideDown(200);

    $.ajax({
      url:     cepAdmin.ajaxUrl,
      method:  'POST',
      timeout: 280000,  // 4m 40s — just under PHP's 300s limit
      data:    { action: 'cep_seo_run_analysis', nonce: cepAdmin.nonce },
      success: function (res) {
        $('#cep-seo-progress').slideUp(200);
        $bannerBtn.prop('disabled', false).removeClass('cep-seo-run-btn--loading');
        $bannerBtn.find('.cep-seo-run-btn__text').text('Run Analysis Now');

        if (res.success) {
          cepSeoToast(res.data.message, 'success');
          // Update stat counters without full reload
          if (res.data.critical_open  !== undefined) $('#cep-stat-critical').text(res.data.critical_open);
          if (res.data.warning_open   !== undefined) $('#cep-stat-warning').text(res.data.warning_open);
          if (res.data.total_fixed    !== undefined) $('#cep-stat-fixed').text(res.data.total_fixed);
          if (res.data.auto_fixable   !== undefined) $('#cep-stat-autofixable').text(res.data.auto_fixable);
          if (res.data.last_run_label !== undefined) $('#cep-seo-last-run').text(res.data.last_run_label);
          // Reload to show fresh issue rows
          if (res.data.reload) {
            setTimeout(function () { location.reload(); }, 1500);
          }
        } else {
          cepSeoToast(res.data && res.data.message ? res.data.message : 'Analysis failed. Check the Logs page for details.', 'error');
        }
      },
      error: function (xhr) {
        $('#cep-seo-progress').slideUp(200);
        $bannerBtn.prop('disabled', false).removeClass('cep-seo-run-btn--loading');
        $bannerBtn.find('.cep-seo-run-btn__text').text('Run Analysis Now');
        var msg = 'Request failed (HTTP ' + xhr.status + '). The scan may have timed out on large sites.';
        cepSeoToast(msg, 'error');
      }
    });
  });

  // Apply rule-based fix
  $(document).on('click', '.cep-seo-fix-btn', function () {
    var $btn = $(this);
    var issueId = $btn.data('issue-id');
    var origHtml = $btn.html();
    $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="animation:cep-spin .8s linear infinite;font-size:12px;vertical-align:middle;"></span>');
    $.post(cepAdmin.ajaxUrl, { action: 'cep_seo_apply_fix', issue_id: issueId, nonce: cepAdmin.nonce }, function (res) {
      if (res.success) {
        var $row = $btn.closest('tr');
        $row.find('.cep-seo-status').text('Fixed').removeClass().addClass('cep-seo-status cep-seo-status--fixed');
        $row.find('.cep-seo-fix-btn, .cep-seo-ignore-btn').fadeOut(200, function () { $(this).remove(); });
        if (!$row.find('.cep-seo-fix-note').length) {
          $row.find('.cep-seo-desc-text').after('<div class="cep-seo-fix-note"><span class="dashicons dashicons-yes" style="font-size:12px;vertical-align:middle;color:#10b981;"></span> ' + $('<div>').text(res.data.message).html() + '</div>');
        }
        cepSeoToast(res.data.message, 'success');
      } else {
        cepSeoToast(res.data && res.data.message ? res.data.message : 'Fix failed.', 'error');
        $btn.prop('disabled', false).html(origHtml);
      }
    }).fail(function () {
      cepSeoToast('Request failed.', 'error');
      $btn.prop('disabled', false).html(origHtml);
    });
  });

  // Apply AI fix
  $(document).on('click', '.cep-seo-ai-fix-btn', function () {
    var $btn    = $(this);
    var postId  = $btn.data('post-id');
    var fixType = $btn.data('fix-type');
    var origHtml = $btn.html();
    $btn.prop('disabled', true).html('✨ Thinking\u2026');
    $.ajax({
      url:     cepAdmin.ajaxUrl,
      method:  'POST',
      timeout: 120000,
      data:    { action: 'cep_seo_apply_ai_fix', post_id: postId, fix_type: fixType, nonce: cepAdmin.nonce },
      success: function (res) {
        if (res.success) {
          var $row = $btn.closest('tr');
          $row.find('.cep-seo-status').text('Fixed').removeClass().addClass('cep-seo-status cep-seo-status--fixed');
          $row.find('.cep-seo-action-group').html('<span style="color:#6366f1;font-size:12px;font-weight:600;">✨ AI Fixed</span>');
          cepSeoToast(res.data.message, 'success');
        } else {
          cepSeoToast(res.data && res.data.message ? res.data.message : 'AI fix failed.', 'error');
          $btn.prop('disabled', false).html(origHtml);
        }
      },
      error: function () {
        cepSeoToast('AI request timed out or failed.', 'error');
        $btn.prop('disabled', false).html(origHtml);
      }
    });
  });

  // Ignore issue
  $(document).on('click', '.cep-seo-ignore-btn', function () {
    var $btn    = $(this);
    var issueId = $btn.data('issue-id');
    $btn.prop('disabled', true).text('\u2026');
    $.post(cepAdmin.ajaxUrl, { action: 'cep_seo_ignore_issue', issue_id: issueId, nonce: cepAdmin.nonce }, function (res) {
      if (res.success) {
        var $row = $btn.closest('tr');
        $row.find('.cep-seo-status').text('Ignored').removeClass().addClass('cep-seo-status cep-seo-status--ignored');
        $row.find('.cep-seo-action-group').html('<span style="color:#94a3b8;font-size:12px;">Ignored</span>');
        $row.css('opacity', '0.55');
      } else {
        cepSeoToast(res.data && res.data.message ? res.data.message : 'Could not ignore.', 'error');
        $btn.prop('disabled', false).text('Ignore');
      }
    });
  });

  // Bulk fix
  $(document).on('click', '.cep-seo-bulk-fix-btn', function () {
    if (!confirm('Apply all available auto-fixes now? This cannot be undone.')) return;
    var $btn = $(this);
    var origHtml = $btn.html();
    $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="animation:cep-spin .8s linear infinite;font-size:14px;vertical-align:middle;margin-right:4px;"></span> Fixing all\u2026');
    $.ajax({
      url:     cepAdmin.ajaxUrl,
      method:  'POST',
      timeout: 280000,
      data:    { action: 'cep_seo_bulk_fix', nonce: cepAdmin.nonce },
      success: function (res) {
        if (res.success) {
          cepSeoToast(res.data.message, 'success');
          if (res.data.reload) { setTimeout(function () { location.reload(); }, 1500); }
        } else {
          cepSeoToast(res.data && res.data.message ? res.data.message : 'Bulk fix failed.', 'error');
          $btn.prop('disabled', false).html(origHtml);
        }
      },
      error: function () {
        cepSeoToast('Request failed.', 'error');
        $btn.prop('disabled', false).html(origHtml);
      }
    });
  });

}(jQuery));
