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

}(jQuery));
