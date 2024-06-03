$(document).ready(function() {
	const lang = $('html').attr('lang');
	$("#most-viewed-ajaxload").load("/?getMostViewedContent&lang="+lang);
})
