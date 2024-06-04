<?php namespace ProcessWire;

/**
 *  Site Tracking Statistics: Most viewed pages
 *
 * Copyright 2024 by update AG
 * https://update.ch
 */
class MostViewed extends WireData implements Module, ConfigurableModule {
	/** @var string Name of the database table used by this module */
	const TABLE_NAME = 'most_viewed_views';

	/** @var string Name of the key in ProcessWire-Logs used for debugging */
	const DEBUG_NAME = 'most-viewed-debug';

	public static function getModuleInfo(): array {
		return [
			'title' => 'Most Viewed',
			'version' => 201,
			'summary' => __('Tracking Page Views and Listing «Most Viewed» Pages'),
			'author' => 'update AG',
			'href' => '',
			'singular' => true,
			'autoload' => true,
			'icon' => 'flag-checkered',
			'requires' => [
				'PHP>=8.0',
				'ProcessWire>=3.0.184',
			],
			'installs' => 'ProcessMostViewed',
		];
	}

	private bool $debug;
	private bool $autoCounting;
	private bool $excludeCrawler;
	private int $viewRange1;
	private int $viewRange2;
	private int $viewRange3;
	private int $feLimit;
	private int $beLimit;
	private array $templatesToCount;
	private array $rolesToCount;
	private string $excludedBranches;
	private string $excludedPages;
	private string $excludedIPs;
	private string $titleFields;
	private string $getVarAjaxLoad;
	private string $ajaxLoadListCode;

	/**
	 * @throws WireException
	 */
	public function init(): void {
		$this->debug = $this->get('debug');
		$this->autoCounting = $this->get('autoCounting');
		$this->excludeCrawler = $this->get('excludeCrawler');
		$this->viewRange1 = $this->get('viewRange1');
		$this->viewRange2 = $this->get('viewRange2');
		$this->viewRange3 = $this->get('viewRange3');
		$this->feLimit = $this->get('feLimit');
		$this->beLimit = $this->get('beLimit');
		$this->templatesToCount = $this->get('templatesToCount');
		$this->rolesToCount = $this->get('rolesToCount');
		$this->excludedBranches = $this->get('excludedBranches');
		$this->excludedPages = $this->get('excludedPages');
		$this->excludedIPs = $this->get('excludedIPs');
		$this->titleFields = $this->get('titleFields');
		$this->getVarAjaxLoad = $this->get('getVarAjaxLoad');
		$this->ajaxLoadListCode = $this->get('ajaxLoadListCode');

		$this->addHookAfter('Pages::deleted', $this, 'mostViewedHookPageDeleted');
		$this->addHookAfter('Pages::trashed', $this, 'mostViewedHookPageDeleted');

		$input = $this->wire->input;
		$user = $this->wire->user;
		$languages = $this->wire->languages;

		if (isset($input->get->{$this->getVarAjaxLoad})) {
			$lang = $this->input->lang;
			$useLang = false;
			if (!empty($lang) && $lang !== 'default') {
				$useLang = true;
				$user->language = $languages->get($lang);
			}

			$options = [];
			if (isset($input->get->templates)) {
				$options['templates'] = $input->get->templates;
			}

			if (isset($input->get->limit) && is_numeric($input->get->limit) && $input->get->limit > 0) {
				$options['limit'] = $input->get->limit;
			}

			$mostViewed = $this->getMostViewedPages($options);
			if ($mostViewed->count > 0) {
				$mostViewedList = '';

				foreach ($mostViewed as $key => $most) {
					if ($most->is('unpublished')) continue;
					if (!$most->viewable($user->language)) continue;

					if ($useLang) {
						$parents = $most->parents;
						$parents->add($most);
						$urlArray = [];
						foreach ($parents as $p) {
							if ($p->id === 1) continue;
							if ($p->getLanguageValue($user->language, 'name') != '') {
								$urlArray[] = $p->getLanguageValue($user->language, 'name');
							} else {
								$urlArray[] = $p->name;
							}
						}
						$url = '/' . $user->language->name . '/' . implode('/', $urlArray) . '/';
					} else {
						$url = $most->url;
					}

					$title = '';
					foreach (preg_split('/\s*,\s*/', $this->titleFields) as $f) {
						$title .= $most->get($f) . ' ';
					}
					$mostViewedList .= sprintf($this->ajaxLoadListCode, $url, $title);
				}
				echo $mostViewedList;
			}
			die();
		}
	}

	public function ready(): void {
		$page = $this->wire->page;
		if (!$this->autoCounting || $page->rootParent->id === 2) return;
		if ($page?->id < 1) return;

		$this->log(sprintf('autoCounting active (ID: %s)', $page->id));
		$this->writePageView($page);
	}

	public function mostViewedHookPageDeleted(HookEvent $event): void {
		$page = $event->arguments('page');

		$sql = sprintf("DELETE FROM %s WHERE `page_id`=:pageId", self::TABLE_NAME);
		$query = $this->database->prepare($sql);
		$query->bindValue(':pageId', $page->id, \PDO::PARAM_INT);

		if ($query->execute()) {
			if (!$query->rowCount()) return;
			$count = $query->rowCount();
			$message = sprintf(__('MostViewed deleted %s entries from %s of page %s'), $count, self::TABLE_NAME, $page->id);
			$this->message($message);
		} else {
			$error = sprintf(__('Error executing query to delete from %s. Trying page: %s'), self::TABLE_NAME, $page->id);
			$this->error($error);
		}
	}

	public function deletePageViews(int $timeRange): bool|int {
		$timeRange = $timeRange * 24 * 60 * 60; // convert timeRange to seconds
		$date = date('Y-m-d H:i:s', time() - $timeRange);

		$sql = sprintf("DELETE FROM %s WHERE `created` < '%s'", self::TABLE_NAME, $date);
		$query = $this->database->prepare($sql);
		$result = $query->execute();
		return $result ? $query->rowCount() : false;
	}


	private function isInExcludedPages(int $pageId): bool {
		if (trim($this->excludedPages) === '') {
			return false;
		}

		$excludedPages = preg_split('/\s*,\s*/', $this->excludedPages);
		return in_array($pageId, $excludedPages);
	}

	private function isInExcludedBranches(int $pageId): bool {
		if (trim($this->excludedBranches) === '') {
			return false;
		}

		$excludedBranches = preg_split('/\s*,\s*/', $this->excludedBranches);
		$currentPage = $this->pages->get($pageId);

		foreach ($excludedBranches as $excludedBranch) {
			if ($pageId == $excludedBranch || $currentPage->parents->has("id=$excludedBranch")) {
				return true;
			}
		}

		return false;
	}

	private function isInExcludedIP(string $ip): bool {
		if (trim($this->excludedIPs) === '') {
			return false;
		}

		$excludedIPs = preg_split('/\s*,\s*/', $this->excludedIPs);
		return in_array($ip, $excludedIPs);
	}

	private function allowTrackViewUser(): bool {
		$user = $this->wire->user;

		if ($user->isGuest()) {
			return true;
		}

		foreach ($this->rolesToCount as $role) {
			if ($user->hasRole($role)) {
				return true;
			}
		}

		return false;
	}

	private function checkIfCrawler(): bool {
		$userAgent = strtolower($_SERVER['HTTP_USER_AGENT']);
		$botIdentifiers = [
			'bot',
			'slurp',
			'crawler',
			'spider',
			'curl',
			'facebook',
			'fetch',
		];
		foreach ($botIdentifiers as $identifier) {
			if (str_contains($userAgent, $identifier)) {
				return true;
			}
		}
		return false;
	}

	private function isCountableTemplate(int $pageId): bool {
		if (!count($this->templatesToCount)) {
			return true;
		}

		$templateIds = $this->wire->templates->find('name|id=' . implode('|', $this->templatesToCount))?->explode('id');
		if (count($templateIds) === 0) {
			return true;
		}

		$pageTemplateId = $this->wire->pages->get('id=' . $pageId)?->template?->id;
		if (!$pageTemplateId) {
			return false;
		}

		return in_array($pageTemplateId, $templateIds);
	}


	private function writePageView(Page $page): void {
		$this->log(sprintf('Check counting ID: %s', $page->id));

		$pageId = $page->id;
		$templateId = $page->template->id;

		if ($pageId === $this->wire->config->http404PageID) return;
		if ($this->excludeCrawler && $this->checkIfCrawler()) return;
		if (!$this->allowTrackViewUser()) return;
		if ($this->isInExcludedIP($_SERVER['REMOTE_ADDR'])) return;
		if ($this->isInExcludedPages($pageId)) return;
		if ($this->isInExcludedBranches($pageId)) return;
		if ($this->autoCounting && !$this->isCountableTemplate($pageId)) return;

		$sql = sprintf("INSERT INTO %s (page_id, template_id) VALUES(:pageId, :templateId)", self::TABLE_NAME);
		$query = $this->database->prepare($sql);
		$query->bindValue(':pageId', $pageId, \PDO::PARAM_INT);
		$query->bindValue(':templateId', $templateId, \PDO::PARAM_INT);

		$logMessage = sprintf('Count view of %s from %s (%s)', $pageId, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
		$this->log($logMessage);
		$query->execute();
	}

	/**
	 * @throws WireException
	 */
	public function getMostViewedPages(array $options = [], bool $backend = false): array|PageArray {
		$defaultLimit = $backend ? $this->beLimit : $this->feLimit;

		$templates = $options['templates'] ?? '';
		$limit = $options['limit'] ?? $defaultLimit;
		$viewRange = $options['viewRange'] ?? $this->viewRange1;

		$minutes = is_numeric($viewRange) ? $viewRange : $this->viewRange1;

		$viewRanges = [
			$minutes,
			$this->viewRange2,
			$this->viewRange3
		];

		foreach ($viewRanges as $minutes) {
			$rows = $this->searchMostViewedPages($minutes, $limit, $templates);
			if (count($rows) >= $limit || $backend) {
				break;
			}
		}

		if ($backend) {
			return $rows;
		}

		$ids = array_column($rows, 'pageId');
		return $this->wire->pages->getById(implode('|', $ids));
	}

	public function searchMostViewedPages(int $minutes, int $limit, string $templates = ''): array {
		$templateCondition = '';

		if (!empty($templates)) {
			$templateIDs = [];

			$templateNames = explode(',', $templates);
			foreach ($templateNames as $name) {
				$template = $this->wire->templates->get(trim($name));

				if ($template) {
					$templateIDs[] = $template->id;
				}
			}

			if (!empty($templateIDs)) {
				$templateCondition = sprintf(' AND template_id IN (%s)', implode(',', $templateIDs));
			}
		}

		$date = new \DateTime();
		$date->modify("-$minutes minutes");
		$dateStr = $date->format('Y-m-d H:i:s');

		$sql = sprintf("
			SELECT page_id, COUNT(*) AS count FROM %s
			WHERE page_id != %d %s
			AND created > :since
			GROUP BY page_id
			ORDER BY count DESC, page_id DESC
			LIMIT :limit", self::TABLE_NAME, $this->wire->config->http404PageID, $templateCondition);

		$query = $this->database->prepare($sql);

		$query->bindValue(':since', $dateStr);
		$query->bindValue(':limit', $limit, \PDO::PARAM_INT);

		$query->execute();
		$result = $query->fetchAll(\PDO::FETCH_ASSOC);

		return $result ?: [];
	}

	private function log(string $message = ''): void {
		if (!$this->debug || !$message) return;

		$this->wire->log->save(self::DEBUG_NAME, $message);
	}

	public function ___install(): void {
		$sql = sprintf("
			CREATE TABLE IF NOT EXISTS %s (
				page_id INT UNSIGNED NOT NULL,
				template_id INT UNSIGNED NOT NULL,
				created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				INDEX page_id (page_id),
				INDEX created (created)
			) ENGINE=%s DEFAULT CHARSET=%s", self::TABLE_NAME, $this->config->dbEngine, $this->config->dbCharset);

		$this->wire->database->exec($sql);
	}

	public function ___uninstall(): void {
		$this->wire->database->query('DROP TABLE IF EXISTS ' . self::TABLE_NAME);
	}
}
