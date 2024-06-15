<?php namespace ProcessWire;

class UpdMostViewed extends WireData implements Module, ConfigurableModule {
	/** @var string Name of the database table used by this module */
	const TABLE_NAME = 'most_viewed_views';

	/** @var string Name of the key in ProcessWire-Logs used for debugging */
	const DEBUG_NAME = 'most-viewed-debug';

	public static function getModuleInfo(): array {
		return [
			'title' => 'Most Viewed',
			'version' => 203,
			'summary' => __('Tracking Page Views and Listing «Most Viewed» Pages'),
			'author' => 'update AG',
			'href' => 'https://github.com/update-switzerland/UpdMostViewed',
			'singular' => true,
			'autoload' => true,
			'icon' => 'list-ol',
			'requires' => [
				'PHP>=8.0', 'ProcessWire>=3.0.184',
			],
			'installs' => 'ProcessUpdMostViewed',
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
	private array $excludedBranches;
	private array $excludedPages;
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

		if ($this->wire('config')->ajax) {
			$this->wire->addHook('/' . $this->getVarAjaxLoad, function ($e) {
				$list = $this->renderMostViewedPages();
				if (!empty($list)) {
					return $list;
				}
				return $e;
			});
		}
	}

	public function ready(): void {
		$page = $this->wire->page;
		if (!$this->autoCounting || $page->rootParent->id === 2) return;
		if ($page?->id < 1) return;

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
		$excludedPages = array_values($this->excludedPages);

		if (empty($excludedPages)) {
			return false;
		}

		if (!in_array($pageId, $excludedPages)) {
			return false;
		}

		$reason = sprintf('Excluded page ID %s', $pageId);
		$this->logPageViewSkip($pageId, $reason);
		return true;
	}

	private function isInExcludedBranches(int $pageId): bool {
		$excludedBranches = array_values($this->excludedBranches);

		if (empty($excludedBranches)) {
			return false;
		}

		$currentPage = $this->pages->get($pageId);

		if (in_array($pageId, $excludedBranches)) {
			$reason = sprintf('Excluded branch page ID %s', $pageId);
			$this->logPageViewSkip($pageId, $reason);
			return true;
		}

		foreach ($currentPage->parents as $parent) {
			if (in_array($parent->id, $excludedBranches)) {
				$reason = sprintf('Page %s is a child of excluded Branch «%s»', $pageId, $parent->title);
				$this->logPageViewSkip($pageId, $reason);
				return true;
			}
		}

		return false;
	}

	private function isInExcludedIP(int $pageId, string $ip): bool {
		if (trim($this->excludedIPs) === '') {
			return false;
		}

		$excludedIPs = preg_split('/\s*,\s*/', $this->excludedIPs);
		if (!in_array($ip, $excludedIPs)) {
			return false;
		}

		$reason = sprintf('Excluded IP %s', $ip);
		$this->logPageViewSkip($pageId, $reason);
		return true;
	}

	private function allowTrackViewUser(int $pageId): bool {
		$user = $this->wire->user;

		if ($user->isGuest()) {
			return true;
		}

		foreach ($this->rolesToCount as $role) {
			if ($user->hasRole($role)) {
				return true;
			}
		}

		$roleNames = $user->roles->explode('name');
		$reason = sprintf('User roles not among roles to count: %s', implode(', ', $roleNames));
		$this->logPageViewSkip($pageId, $reason);
		return false;
	}

	/**
	 * @see https://github.com/mitchellkrogza/nginx-ultimate-bad-bot-blocker/blob/master/_generator_lists/bad-user-agents.list
	 */
	private function checkIfCrawler(int $pageId): bool {
		if (isset($_SERVER['HTTP_USER_AGENT'])) {
			$useragent = $this->wire->sanitizer->text($_SERVER['HTTP_USER_AGENT']);
			$crawlerList = preg_replace('/[\r\n]/', '', file_get_contents(__DIR__ . '/crawler.txt'));

			if (preg_match("/($crawlerList)/i", $useragent, $matches)) {
				$reason = sprintf('Crawler «%s» detected', $matches[0]);
				$this->logPageViewSkip($pageId, $reason);
				return true;
			}
		}

		return false;
	}

	private function isCountableTemplate(int $pageId, int $templateId): bool {
		if (!count($this->templatesToCount)) {
			return true;
		}

		$templateIds = $this->wire->templates->find('name|id=' . implode('|', $this->templatesToCount))?->explode('id');
		if (count($templateIds) === 0) {
			return true;
		}

		if (in_array($templateId, $templateIds)) {
			return true;
		}

		$ignoredTemplateName = $this->wire->templates->get($templateId)->name;
		$this->logPageViewSkip($pageId, sprintf('Ignored template %s', $ignoredTemplateName));
		return false;
	}

	protected function writePageView(Page $page): void {
		$pageId = $page->id;
		$templateId = $page->template->id;

		if ($pageId === $this->wire->config->http404PageID) {
			$this->logPageViewSkip($pageId, '404 Page');
			return;
		}
		if ($this->excludeCrawler && $this->checkIfCrawler($pageId)) return;
		if (!$this->allowTrackViewUser($pageId)) return;
		if ($this->isInExcludedIP($pageId, $_SERVER['REMOTE_ADDR'])) return;
		if ($this->isInExcludedPages($pageId)) return;
		if ($this->isInExcludedBranches($pageId)) return;
		if ($this->autoCounting && !$this->isCountableTemplate($pageId, $templateId)) return;

		$sql = sprintf("INSERT INTO %s (page_id, template_id) VALUES(:pageId, :templateId)", self::TABLE_NAME);
		$query = $this->database->prepare($sql);
		$query->bindValue(':pageId', $pageId, \PDO::PARAM_INT);
		$query->bindValue(':templateId', $templateId, \PDO::PARAM_INT);

		$logMessage = sprintf('Count view of %s from %s (%s)', $pageId, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
		if ($this->autoCounting) $logMessage .= ' - autoCounting active';
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
			$minutes, $this->viewRange2, $this->viewRange3
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

		$ids = array_column($rows, 'page_id');
		return $this->wire->pages->getById(implode('|', $ids));
	}

    public function hasEntriesInDB(): bool {
        $sql = sprintf("SELECT COUNT(*) AS count FROM %s", self::TABLE_NAME);
        $query = $this->database->prepare($sql);
        $query->execute();
        $result = $query->fetch(\PDO::FETCH_ASSOC);
        return $result['count'] > 0;
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

	/**
	 * @throws WireException
	 */
	public function renderMostViewedPages(): string {
		$mostViewedList = '';
		$input = $this->wire->input;
		$lang = $this->input->lang;
		$userLanguage = null;
		$useLang = false;

		if (!empty($lang) && $lang !== 'default') {
			$useLang = true;
			$userLanguage = $this->wire->languages->get($lang);
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
			foreach ($mostViewed as $key => $most) {
				if ($most->is('unpublished')) continue;
				if (!$most->viewable($userLanguage)) continue;

				$url = $most->url;
				if ($useLang) {
					$url = $most->localUrl($userLanguage);
				}

				$title = '';
				foreach (preg_split('/\s*,\s*/', $this->titleFields) as $f) {
					$title .= $most->get($f) . ' ';
				}

				$mostViewedList .= sprintf($this->ajaxLoadListCode, $url, $title);
			}
		}

		return $mostViewedList;
	}

	private function logPageViewSkip(int $pageId, string $reason): void {
		$message = sprintf('Not logging page-view for ID: %s (Reason: %s)', $pageId, $reason);
		$this->log($message);
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