(function ($) {
	'use strict';

	$(function () {
		var $btn     = $('#plchk-run');
		var $spinner = $('#plchk-spinner');
		var $report  = $('#plchk-report');

		$btn.on('click', function (e) {
			e.preventDefault();
			$btn.prop('disabled', true);
			$spinner.addClass('is-active');
			$report.html('<p>' + PLCHK.i18n.scanning + '</p>');

			$.post(PLCHK.ajax, {
				action: 'plchk_scan',
				nonce: PLCHK.nonce,
				deep: $('#plchk-deep').is(':checked') ? 1 : 0
			}).done(function (res) {
				if (res && res.success && res.data && res.data.html) {
					$report.html(res.data.html);
				} else {
					$report.html('<div class="notice notice-error"><p>' + PLCHK.i18n.failed + '</p></div>');
				}
			}).fail(function () {
				$report.html('<div class="notice notice-error"><p>' + PLCHK.i18n.failed + '</p></div>');
			}).always(function () {
				$btn.prop('disabled', false);
				$spinner.removeClass('is-active');
			});
		});
	});
})(jQuery);
