/**
 * Content Engine Pro — Jobs archive live filter enhancement.
 *
 * The server already filters via ?company=/?location=/?type=/?salary=/?s query
 * vars (reloading the page). This layer adds instant client-side filtering of
 * the jobs already on the current page so the dropdowns feel responsive, and
 * injects a "Clear" link when any filter is active.
 *
 * CSP-safe: no inline handlers, only addEventListener.
 */
(function ($) {
	'use strict';

	$(function () {
		var $form = $('.cep-job-filter');
		if (!$form.length) {
			return;
		}

		var $list = $('.cep-jobs-archive__list');
		var $cards = $list.length ? $list.find('li.wp-block-post') : $();

		if (!$cards.length) {
			return;
		}

		// Build a searchable index from each card (title + company name).
		var index = $cards.map(function () {
			var $card = $(this);
			var title = $.trim($card.find('.wp-block-post-title').text());
			var company = $.trim($card.find('.as-co-name').text());
			return {
				card: $card,
				haystack: (title + ' ' + company).toLowerCase()
			};
		}).get();

		var $search = $form.find('input[name="s"]');

		// Instant search-on-type (does not fight the server selects).
		$search.on('input', function () {
			var q = $.trim($(this).val()).toLowerCase();
			$.each(index, function (i, entry) {
				var match = !q || entry.haystack.indexOf(q) !== -1;
				entry.card.toggle(match);
			});
			updateEmptyState();
		});

		// Clear link next to the submit button when any control is non-default.
		function maybeShowClear() {
			if ($('.cep-job-filter__clear').length) {
				return;
			}
			var active = $search.val() ||
				$form.find('select').filter(function () { return this.value !== ''; }).length;
			if (active) {
				var $clear = $('<a class="cep-job-filter__clear" href="' +
					$form.attr('action') + '">' + cepJobsL10n.clear + '</a>');
				$form.find('.cep-job-filter__submit').after($clear);
			}
		}

		function updateEmptyState() {
			var visible = $cards.filter(':visible').length;
			var $empty = $('.cep-jobs-archive__empty');
			if (!visible && !$empty.length) {
				$list.after('<p class="cep-jobs-archive__empty">' + cepJobsL10n.none + '</p>');
			} else if (visible && $empty.length) {
				$empty.remove();
			}
		}

		$form.on('change', 'select', maybeShowClear);
		$search.on('input', maybeShowClear);
		maybeShowClear();
	});

	// Localisation defaults (overridden by wp_localize_script if present).
	var cepJobsL10n = window.cepJobsL10n || {
		clear: 'Clear',
		none: 'No jobs match your filters.'
	};

})(jQuery);
