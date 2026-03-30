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

}(jQuery));
