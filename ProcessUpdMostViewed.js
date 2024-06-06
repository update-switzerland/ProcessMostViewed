jQuery(document).ready(function($) {
	if (!$('#MostViewedTabs').length) return;

	$('#MostViewedTabs').WireTabs({
		items: $('.Inputfields li.WireTab')
	});
});