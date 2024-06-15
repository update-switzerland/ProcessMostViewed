<?php namespace ProcessWire;

class ProcessUpdMostViewed extends Process {
	/** @var string Name used for the Module-Permission and ProcessWire Page */
	const PAGE_NAME = 'most-viewed';

	public static function getModuleInfo(): array {
		return [
			'title' => 'Most Viewed',
			'version' => 204,
			'summary' => __('Tracking Page Views and Listing «Most Viewed» Pages (Backend Process)'),
			'author' => 'update AG',
			'href' => 'https://github.com/update-switzerland/UpdMostViewed',
			'singular' => false,
			'autoload' => false,
			'icon' => 'list-ol',
			'requires' => 'UpdMostViewed',
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
        $out = sprintf('<h2>%s</h2><p>%s</p>', __('No Page Views found.'), __('Enable auto counting in the module config or add the counting script to a template.'));

		$input = $this->wire->input;
		$modules = $this->wire->modules;

		/** @var UpdMostViewed $mostViewed */
		$mostViewed = $modules->get('UpdMostViewed');
		$modules->get('JqueryWireTabs');

		$delete = $input->post->delete ?? null;
		$timeRange = $input->post->timerange ?? null;

		if ($delete && $timeRange) {
			$result = $mostViewed->deletePageViews((int) $timeRange);
			$this->handlePageViewDeletionResult($result, $timeRange);
		}

        if ($mostViewed->hasEntriesInDB()) {
            $out = $this->renderMostViewedForm($mostViewed);
        }

		return $out;
	}

	/**
	 * @throws WireException
	 */
    public function renderMostViewedForm(UpdMostViewed $mostViewed): string {
        $modules = $this->wire->modules;

		/** @var InputfieldForm $form */
        $form = $modules->get('InputfieldForm');
        $form->attr('name+id', 'MostViewedTabs');
        $form->attr('method', 'post');
        $form->attr('action', './');

        for ($i = 1; $i <= 3; $i++) {
            $viewRange = $mostViewed->get('viewRange'.$i);
            $mPages = $mostViewed->getMostViewedPages([
                'viewRange' => $viewRange,
                'templates' => ''
            ], true);

            if (!empty($mPages)) {
                $tab = $this->buildViewRangeTab($mPages, $viewRange, 'viewRange'.$i);
                $form->add($tab);
            }
        }
        $tab = $this->buildDeleteEntriesTab();
        $form->add($tab);

        return $form->render();
    }

	private function handlePageViewDeletionResult(int|false $result, string $timeRange): void {
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
	private function buildViewRangeTab(array|PageArray $mPages, string $viewRange, string $viewRangeKey): ?InputfieldWrapper {
		$modules = $this->wire->modules;

		$tab = new InputfieldWrapper();
		$tab->attr('id', 'MostViewedPages'.$viewRangeKey);
		$tab->attr('title', sprintf(__('Last %s hours'), $viewRange / 60));
		$tab->attr('class', 'WireTab');

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
					sprintf(__('Page not found: id %s'), $mP['page_id']),
					'', '', '', ''
				]);
			}
		}

		$tabContent = $this->modules->get('InputfieldMarkup');
		$tabContent->value = $table->render();
		$tab->add($tabContent);

		return $tab;
	}

	/**
	 * @throws WireException
	 */
	private function buildDeleteEntriesTab(): InputfieldWrapper {
		$modules = $this->wire->modules;

		$tab = new InputfieldWrapper();
		$tab->attr('id+name', 'DeleteEntries');
		$tab->attr('title', __('Delete entries'));
		$tab->attr('class', 'WireTab');

		/** @var InputfieldSelect $field */
		$field = $modules->get('InputfieldSelect');
		$field->label = __('Select time range');
		$field->attr('id+name', 'timerange');
		$field->attr('value', '7');
		$field->attr('required', true);
		$timeRanges = [
			'7' => __('older than 7 days'),
			'14' => __('older than 14 days'),
			'30' => __('older than 30 days'),
			'60' => __('older than 60 days'),
			'90' => __('older than 90 days'),
			'180' => __('older than 180 days')
		];
		foreach ($timeRanges as $k => $v) {
			$field->addOption($k, $v);
		}
		$tab->add($field);

		/** @var InputfieldSubmit $field */
		$field = $this->modules->get('InputfieldSubmit');
		$field->value = __('Delete page views');
		$field->attr('id+name', 'delete');
		$tab->add($field);

		return $tab;
	}
}