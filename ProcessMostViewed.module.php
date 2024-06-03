<?php namespace ProcessWire;

class ProcessMostViewed extends Process {
	/**
	 * Name used for the Module-Permission and ProcessWire Page
	 * @var string
	 */
	const PAGE_NAME = 'most-viewed';

	public static function getModuleInfo(): array {
		return [
			'title' => 'Most Viewed',
			'version' => 201,
			'summary' => __('Tracking Page Views and Listing «Most Viewed» Pages (Backend Process)'),
			'author' => 'update AG',
			'href' => '',
			'singular' => false,
			'autoload' => false,
			'icon' => 'flag-checkered',
			'requires' => 'MostViewed',
			'permissions' => [
				self::PAGE_NAME => 'List «Most viewed» counter',
			],
			'page' => [
				'name' => self::PAGE_NAME,
				'parent' => 'setup',
				'title' => 'Most Viewed',
			],
		];
	}

	/**
	 * @throws WireException
	 */
	public function ___execute(): string {
		$input = $this->wire->input;

		/** @var MostViewed $mostViewed */
		$mostViewed = $this->wire->modules->get('MostViewed');

		$out = '';

		$delete = $input->get->delete ?? null;
		$timeRange = $input->get->timerange ?? null;
		if ($delete && $timeRange) {
			$result = $mostViewed->deletePageViews($timeRange);
			$this->handlePageViewDeletionResult($result, $timeRange);
		}

		$out .= $this->deleteForm();

		if ($mostViewed->excludeCrawler) {
			$out .= sprintf('<p>%s</p>', __('Page views from search engine crawlers are not included in the statistics (disabled by module configuration)'));
		}

		$viewRanges = $this->renderViewRangeEntry($mostViewed, 'viewRange1');
		$viewRanges .= $this->renderViewRangeEntry($mostViewed,'viewRange2');
		$viewRanges .= $this->renderViewRangeEntry($mostViewed,'viewRange3');

		$out .= $viewRanges ?: sprintf('<h2>%s</h2>', __('No Page Views found.'));
		$out .= $viewRanges ? '' : sprintf('<p>%s</p>', __('Enable auto counting in the module config or add the counting script to a template.'));
		return $out;
	}

	private function handlePageViewDeletionResult($result, $timeRange): void {
		if ($result > 0) {
			$this->message(sprintf(__('%s page views older than %s days found and deleted.'), $result, $timeRange));
			return;
		}

		if ($result === 0) {
			$this->message(sprintf(__('No page views older than %s days found to deleted.'), $timeRange));
			return;
		}

		$this->error(__('Could not delete page views.'));
	}

	/**
	 * @throws WireException
	 */
	private function renderViewRangeEntry(MostViewed $mostViewed, string $viewRangeKey): string {
		$modules = $this->wire->modules;
		$viewRange = $mostViewed->$viewRangeKey;

		/** @var MarkupAdminDataTable $table */
		$table = $modules->get('MarkupAdminDataTable');
		$table->setEncodeEntities(false);
		$table->headerRow([
			'',
			__('Page'),
			__('Date'),
			__('Path'),
			__('Template'),
			__('Views')
		]);

		$mPages = $mostViewed->getMostViewedPages([
			'viewRange' => $viewRange,
			'templates' => ''
		], true);

		if (empty($mPages)) return '';

		foreach ($mPages as $key => $mP) {
			$p = $this->wire->pages->get($mP['page_id']);
			$counter = $key + 1;

			if ($p->id) {
				$table->row([
					sprintf('%d.', $counter),
					sprintf("<a href='%s' target='_blank'>%s</a>", $p->url, $p->title),
					date('d.m.Y H:i:s', $p->datetime ?: $p->modified),
					$p->path,
					$p->template,
					$mP['count'],
				]);
			} else {
				$table->row([
					sprintf('%d.', $counter),
					sprintf(__('Page not found: id %s'), $mP['pages_id']),
					'', '', '', ''
				]);
			}
		}

		return sprintf('<h2>%s</h2>', sprintf(__('Most viewed pages of the last %s hours:'), $viewRange / 60)) . $table->render();
	}

	/**
	 * @throws WireException
	 */
	public function deleteForm(): string {
		$modules = $this->wire->modules;

		/** @var InputfieldForm $form */
		$form = $modules->get('InputfieldForm');
		$form->attr('action', '');
		$form->attr('method', 'get');

		/** @var InputfieldFieldset $fieldset */
		$fieldset = $modules->get('InputfieldFieldset');
		$fieldset->label = __('Delete page views');
		$fieldset->collapsed = Inputfield::collapsedYes;

		/** @var InputfieldSelect $field */
		$field = $modules->get('InputfieldSelect');
		$field->label = __('Select time range');
		$field->columnWidth = 100;
		$field->attr('name', 'timerange');
		$timeRanges = [
			'7' => __('older then 7 days'),
			'14' => __('older then 14 days'),
			'30' => __('older then 30 days'),
			'60' => __('older then 60 days'),
			'90' => __('older then 90 days'),
			'180' => __('older then 180 days')
		];
		foreach ($timeRanges as $k => $v) {
			$field->addOption($k, $v);
		}
		$fieldset->add($field);

		/** @var InputfieldSubmit $field */
		$field = $this->modules->get('InputfieldSubmit');
		$field->value = __('Delete page views');
		$field->attr('name', 'delete');
		$field->columnWidth = 100;
		$fieldset->add($field);

		$form->add($fieldset);

		return $form->render();
	}
}