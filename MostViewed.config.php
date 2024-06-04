<?php namespace ProcessWire;

class MostViewedConfig extends ModuleConfig {
	public function getDefaults(): array {
		return [
			'debug' => false,
			'autoCounting' => false,
			'excludeCrawler' => false,
			'viewRange1' => 1440,
			'viewRange2' => 2880,
			'viewRange3' => 4320,
			'feLimit' => 6,
			'beLimit' => 25,
			'templatesToCount' => [],
			'rolesToCount' => [],
			'excludedBranches' => '',
			'excludedPages' => '',
			'excludedIPs' => '',
			'titleFields' => 'title',
			'getVarAjaxLoad' => 'getMostViewedContent',
			'ajaxLoadListCode' => '<li><a href="%s">%s</a></li>'
		];
	}

	/**
	 * @throws WireException
	 */
	public function getInputFields(): InputfieldWrapper {
		$inputFields = parent::getInputfields();

		$inputFields->add($this->buildField('InputfieldToggle', [
			'id+name' => 'debug',
			'label' => __('Use debugging?'),
			'description' => __('Log information to ProcessWire Logs'),
			'icon' => 'bug'
		]));

		/** @var InputfieldFieldset $fieldset */
		$fieldset = $this->buildField('InputfieldFieldset', [
			'id+name' => 'countFieldset',
			'label' => __('Settings for counting page requests')
		]);

		$fieldset->add($this->buildField('InputfieldToggle', [
			'id+name' => 'autoCounting',
			'label' => __('Automated page view counting'),
			'description' => __('Initiate automated page view counting by module hook (no coding in templates required and it works also for cached pages)')
		]));

		$fieldset->add($this->buildField('InputfieldText', [
			'id+name' => 'excludedBranches',
			'label' => __('Branches excluded from tracking'),
			'description' => __('Pages and their subpages that are not to be tracked. Input: Page IDs (comma separated).')
		]));

		$fieldset->add($this->buildField('InputfieldText', [
			'id+name' => 'excludedPages',
			'label' => __('Pages excluded from tracking'),
			'description' => __('Pages that should not be tracked. Input: Page IDs (comma separated).')
		]));

		$fieldset->add($this->buildField('InputfieldText', [
			'id+name' => 'excludedIPs',
			'label' => __('Requesting IP# excluded from tracking'),
			'description' => __('Requesting IP# that should not be tracked. Input: IP# (comma separated).')
		]));

		$options = [];
		/** @var Template $template */
		foreach ($this->wire->templates as $template) {
			if ($template->flags & Template::flagSystem || !$template->filenameExists()) {
				continue;
			}
			$options[$template->id] = $template->label ?: $template->name;
		}
		$fieldset->add($this->buildField('InputfieldAsmSelect', [
			'id+name' => 'templatesToCount',
			'label' => __('Select templates whose pages are to be counted'),
			'description' => __('No selection = all templates will be counted.'),
			'options' => $options,
			'columnWidth' => 50
		]));

		$options = [];
		/** @var Role $role */
		foreach ($this->wire->roles as $role) {
			if ($role->name === 'guest') {
				continue;
			}
			$options[$role->id] = $role->label ?: $role->name;
		}
		$fieldset->add($this->buildField('InputfieldAsmSelect', [
			'id+name' => 'rolesToCount',
			'label' => __('Select the user roles to be counted in addition to «guest»'),
			'description' => __('No selection = only requests with role «guest» will be counted.'),
			'options' => $options,
			'columnWidth' => 50
		]));

		$fieldset->add($this->buildField('InputfieldToggle', [
			'id+name' => 'excludeCrawler',
			'label' => __('Ignore search engine crawlers'),
			'description' => __('Page views from search engine crawlers do not count.'),
			'columnWidth' => 50
		]));

		$inputFields->add($fieldset);

		/** @var InputfieldFieldset $fieldset */
		$fieldset = $this->buildField('InputfieldFieldset', [
			'id+name' => 'ajaxFieldset',
			'label' => __('Settings for AJAX requests')
		]);

		$fieldset->add($this->buildField('InputfieldText', [
			'id+name' => 'getVarAjaxLoad',
			'label' => __('AJAX loading GET variable'),
			'description' => __('GET variable used when requesting a AJAX load of a «Most Viewed» list'),
			'required' => true
		]));

		$fieldset->add($this->buildField('InputfieldText', [
			'id+name' => 'titleFields',
			'label' => __('Title fields'),
			'description' => __('Fields that are used for the title. Input: Field names (comma separated) – eg. "title" or "firstname, headline|title"'),
			'required' => true
		]));

		$fieldset->add($this->buildField('InputfieldText', [
			'id+name' => 'ajaxLoadListCode',
			'label' => __('AJAX loading list item HTML code'),
			'description' => __('HTML code used for list items in a AJAX load')
		]));

		$inputFields->add($fieldset);

		/** @var InputfieldFieldset $fieldset */
		$fieldset = $this->buildField('InputfieldFieldset', [
			'id+name' => 'listFieldset',
			'label' => __('Settings for «Most Viewed listing»')
		]);

		$fieldset->add($this->buildField('InputfieldInteger', [
			'id+name' => 'viewRange1',
			'label' => __('1st time range'),
			'description' => __('Default value for «Most Viewed» list (1st calculation). Number of minutes since page requests that are considered.'),
			'inputType' => 'number',
			'columnWidth' => 33
		]));

		$fieldset->add($this->buildField('InputfieldInteger', [
			'id+name' => 'viewRange2',
			'label' => __('2nd time range'),
			'description' => __('Used when the first calculation did not yield enough results (= 2nd calculation).'),
			'inputType' => 'number',
			'columnWidth' => 33
		]));

		$fieldset->add($this->buildField('InputfieldInteger', [
			'id+name' => 'viewRange3',
			'label' => __('3rd time range'),
			'description' => __('Used when the second calculation did not yield enough results (= 3rd calculation).'),
			'inputType' => 'number',
			'columnWidth' => 33
		]));

		$fieldset->add($this->buildField('InputfieldInteger', [
			'id+name' => 'feLimit',
			'label' => __('Number of listed pages in the frontend'),
			'inputType' => 'number',
			'columnWidth' => 50
		]));

		$fieldset->add($this->buildField('InputfieldInteger', [
			'id+name' => 'beLimit',
			'label' => __('Number of listed pages in the backend'),
			'inputType' => 'number',
			'columnWidth' => 50
		]));

		$inputFields->add($fieldset);

		$value = '<p>';
		$value .= '<b>' . __('Do the settings') . ':</b><br />';
		$value .= __('Define (by comma separated page ids) which site branches and/or pages should NOT be counted in the module database when requested by a site visitor and select the templates whose pages are to be counted.') . ' ';
		$value .= '</p>';
		$value .= '<p>';
		$value .= '<b>' . __('Initiating Page Tracking') . ':</b><br />';
		$value .= __('Activate the «Automated page view counting» checkbox in the module configuration (recommended).') . ' ';
		$value .= __('Or use this PHP code in the page templates you wish to count:');
		$value .= '</p>';
		$value .= '<pre>$modules->get(\'MostViewed\')->writePageView($page);</pre>';
		$value .= '<p>';
		$value .= __('where $page is a ProcessWire Page Object. Make sure that the page is not counted twice during a page call! And be aware of problems in cached pages or templates.');
		$value .= '</p>';
		$value .= '<p>';
		$value .= '<b>' . __('Output «Most Viewed» list') . ':</b><br />';
		$value .= __('You get an array with the recent «Most Viewed» pages with the code:');
		$value .= '</p>';
		$value .= '<pre>$mostViewed = $modules->get(\'MostViewed\')->getMostViewedPages();</pre>';
		$value .= '<p>';
		$value .= __('You can also pass an array of options to the function getMostViewedPages() to control the search for the most visited pages.') . '<br />';
		$value .= '</p>';
		$value .= '<pre>$options = [
	"templates" => "basic-page,news-entry,document-page",	// restrict search to specific templates (comma separated)
	"limit" => 5,	// overwrite default number (as int) of list items defined above
];
$mostViewed = $modules->get(\'MostViewed\')->getMostViewedPages($options);</pre>';
		$value .= '<p>';
		$value .= __('Feel free to output the found pages in $mostViewed with a foreach() loop like this:') . ' ';
		$value .= '</p>
		<pre>
if ($mostViewed) {
	echo "&lt;ol class=\'most-viewed\'&gt;";
	foreach ($mostViewed as $key => $most) {
		echo "&lt;li&gt;&lt;a href=\'{$most->url}\'&gt;{$most->title}&lt;/a&gt;&lt;/li&gt;";
	}
	echo "&lt;/ol&gt;";
}</pre>';

		$value .= '<p>';
		$value .= '<b>' . __('Output «Most Viewed» list with AJAX') . ':</b><br />';
		$value .= __('In cached pages, the list of «Most Viewed» pages should be included in real time using AJAX. Otherwise, cached lists are output instead of current lists.') . '<br />';
		$value .= __('You can obtain a real-time list in HTML format by calling up the URL');
		$value .= ' «/?' . self::getDefaults()['getVarAjaxLoad'] . '» (followed by optional arguments). ';
		$value .= __('Below is the sample code for an Ajax integration using jQuery and passing arguments for a restricted search on specific templates (see: &templates=basic-page,news-page) and a given amount of pages (see: &limit=4):');
		$value .= '</p>';
		$value .= '<pre>&lt;div id="most-viewed-container"&gt;
	&lt;h3&gt;Most viewed (Ajax load)&lt;/h3&gt;
	&lt;ol class="most-viewed-list"&gt;Loading....&lt;ol&gt;
&lt;/div&gt;
&lt;script&gt;
// load most viewed pages into page
$(document).ready(function() {
	$.ajax(
		"/&lt;?php echo $modules->get(\'MostViewed\')->getVarAjaxLoad; ?&gt;?lang=&lt;?php echo $user->lang->name; ?&gt;&templates=basic-page,news-page&limit=4",
		{
			success: function(data) {
				$(\'#most-viewed-container .most-viewed-list\').html(data);
			},
			error: function() {
				$(\'#most-viewed-container .most-viewed-list\').html(\'Sorry, currently no data available\');
			}
		}
	);
});
&lt;/script&gt;
</pre>';

		$inputFields->add($this->buildField('InputfieldMarkup', [
			'id+name' => 'modulePrefixInfo',
			'label' => __('How to use this module'),
			'description' => __('Follow these instructions to use the module «Most Viewed» in your website'),
			'icon' => 'bullhorn',
			'value' => $value
		]));

		return $inputFields;
	}

	/**
	 * @throws WireException
	 */
	protected function buildField($fieldNameId, $meta): Inputfield {
		$field = $this->wire->modules->get($fieldNameId);

		foreach ($meta as $metaNames => $metaInfo) {
			switch ($metaNames) {
				case 'options':
					/** @var InputfieldAsmSelect $field */
					foreach ($metaInfo as $value => $label) {
						$field->addOption($value, $label);
					}
					break;

				default:
					$metaNames = explode('+', $metaNames);
					foreach ($metaNames as $metaName) {
						$field->$metaName = $metaInfo;
					}
			}
		}

		return $field;
	}
}