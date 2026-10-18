/* Lipovenesc Evenimente – admin helpers: media picker for categories, price inheritance toggle */
(function ($) {
	'use strict';

	$(document).on('click', '.lat-media-pick', function (e) {
		e.preventDefault();
		var wrap = $(this).closest('.lat-media');
		var frame = wp.media({ title: 'Imaginea categoriei', multiple: false, library: { type: 'image' } });
		frame.on('select', function () {
			var a = frame.state().get('selection').first().toJSON();
			var url = a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url;
			wrap.find('input[type=hidden]').val(a.id);
			wrap.find('img').attr('src', url).prop('hidden', false);
			wrap.find('.lat-media-clear').prop('hidden', false);
		});
		frame.open();
	});

	$(document).on('click', '.lat-media-clear', function (e) {
		e.preventDefault();
		var wrap = $(this).closest('.lat-media');
		wrap.find('input[type=hidden]').val('');
		wrap.find('img').attr('src', '').prop('hidden', true);
		$(this).prop('hidden', true);
	});

	$(document).on('change', '.lat-inherit-price', function () {
		$(this).closest('td').find('.lat-price-own').prop('hidden', this.checked);
	});

	// After a category is added via AJAX, reset our custom fields too.
	$(document).ajaxComplete(function (e, xhr, settings) {
		if (settings.data && String(settings.data).indexOf('action=add-tag') !== -1 && xhr.responseText && xhr.responseText.indexOf('wp_error') === -1) {
			$('#addtag .lat-cat-field').find('input[type=text], textarea').val('');
			$('#addtag .lat-media input[type=hidden]').val('');
			$('#addtag .lat-media img').prop('hidden', true);
		}
	});
})(jQuery);
