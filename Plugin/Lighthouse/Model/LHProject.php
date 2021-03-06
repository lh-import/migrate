<?php

App::uses('Folder', 'Utility');
App::uses('LighthouseAppModel', 'Lighthouse.Model');

class LHProject extends LighthouseAppModel {

/**
 * list all projects by id
 *
 * @param string $account
 * @return array
 */
	public function all($account = '*') {
		if ($account === '*') {
			$return = [];

			foreach ($this->accounts() as $account) {
				$projects = $this->all($account);
				$return = array_merge($return, $projects);
			}
			return $return;
		}

		$Folder = new Folder($this->source() . $account . '/projects');

		list($return) = $Folder->read();

		foreach ($return as &$ret) {
			$ret = $account . '/' . $ret;
		}
		return $return;
	}

/**
 * accounts
 *
 * List all accounts - doesn't really belong in the project model.. but source
 * is defined here, and it's only really used by the "all projects" function
 *
 * @return array
 */
	public function accounts() {
		$Folder = new Folder($this->source());
		list($accounts) = $Folder->read();
		return $accounts;
	}

	public function config($project = null) {
		list($account, $project) = $this->project($project);

		$config = $this->data($project);
		if (isset($config['project'])) {
			$config = current($config);
		}

		$config['open_states_list'] = explode(',', $config['open_states_list']);
		$config['closed_states_list'] = explode(',', $config['closed_states_list']);

		$keep = [
			'id',
			'name',
			'default_ticket_text',
			'closed_states_list',
			'open_states_list',
			'open_tickets_count',
			'created_at',
			'updated_at'
		];
		return array_intersect_key($config, array_flip($keep));
	}

/**
 * Determine the project id from (user) input, for the project user/12345-project-name,
 * Will accept any of:
 *
 *  - path/to/type/account-name/projects/12345-project-name
 *  - user/12345-project-name
 *  - user/12345
 *  - user/project-name
 *  - 12345
 *  - project-name
 *
 * Returning:
 *
 * ['user', '12345-project-name']
 *
 * @param string $input
 * @param string $account
 * @param bool $warn
 * @return array
 */
	public function id($input, $account = null) {
		if (preg_match('@([^/]*)/projects/([^/]*)@', $input, $match)) {
			$account = $match[1];
			$project = $match[2];
			return [$account, $project];
		}

		if (strpos($input, '/')) {
			list($account, $project) = explode('/', $input);
			return [$account, $project];
		}

		if (!$account) {
			$Folder = new Folder($this->source() . $account);
			list($folders) = $Folder->read();
			foreach ($folders as $account) {
				$return = $this->id($input, $account, false);
				if (array_filter($return)) {
					return $return;
				}
			}
		} else {
			$Folder = new Folder($this->source() . $account . '/projects');
			list($folders) = $Folder->read();

			$len = strlen($input);
			foreach ($folders as $project) {
				if (
					$project === $input ||
					substr($project, 0, $len) === $input ||
					substr($project, -$len) === $input
				) {
					return [$account, $project];
				}
			}
		}

		return false;
	}

/**
 * setId
 *
 * Set the active project
 *
 * @param string $id
 */
	public function setId($id) {
		list($account, $project) = $this->id($id);
		Configure::write('LH.account', $account);
		Configure::write('LH.project', $project);
	}

/**
 * load a lighthouse export file
 *
 * A lighthouse export file is just a gzipped tar ball - expand it into the export folder
 * If it's a project export, instead of an account export - move  the extracted files to
 * where the rest of the code expects it to be.
 *
 * @param string $sourceGz
 */
	public function load($sourceGz) {
		$root = $this->source();

		$file = basename($sourceGz);
		$targetGz = $root . $file;

		if (!is_dir($root)) {
			mkdir($root, 0777, true);
		}
		copy($sourceGz, $targetGz);
		passthru(sprintf("cd %s; tar xvzf %s", escapeshellarg($root), escapeShellarg($file)));
		unlink($targetGz);

		if (file_exists($root . 'project.json')) {
			$newName = $root . 'main/projects/' . preg_replace('@_\d{4}.*@', '', $file);

			$tmpName = str_replace('export', 'temporary', $root);
			if (is_dir($tmpName)) {
				unlink($tmpName);
			}
			rename($root, $tmpName);

			mkdir(dirname($newName), 0777, true);
			rename($tmpName, $newName);
		}
	}

/**
 * Overridden to define the path to a project config file
 *
 * @param string $id
 * @return string
 */
	public function path($id, $full = false) {
		list($account, $project) = $this->project();
		$return = "$account/projects/$project/project.json";

		if ($full) {
			return $this->source() . $return;
		}
	}

/**
 * renumber (tickets) for a project
 *
 * Also links unmodified files so that the renumbered data is a complete copy of the export data
 */
	public function renumber($project = null) {
		list($account, $project) = $this->project($project);

		$this->log(sprintf('Processing %s/%s', $account, $project), LOG_INFO);

		$path = $account . '/projects/' . $project;

		$source = $this->source('export') . $path;
		$fromDir = $source . '/tickets';

		$target = $this->source('renumbered') . $path;
		$toDir = $target . '/tickets';

		if (!is_dir($toDir)) {
			mkdir($toDir, 0777, true);
		}
		$this->linkCommon($project, 'export');

		$Folder = new Folder($fromDir);
		list($tickets) = $Folder->read();

		foreach ($tickets as $id) {
			$this->_renumberTicket($id, $fromDir, $toDir);
		}
	}

	public function linkCommon($project = null, $from = 'export') {
		list($account, $project) = $this->project($project);
		$path = $account . '/projects/' . $project;

		$target = $this->source();
		$currentSource = basename($target);
		$source = $this->source($from);

		$target .= $path . '/';
		$source .= $path . '/';

		$Folder = new Folder($source);
		list($folders, $files) = $Folder->read();
		$all = array_merge($folders, $files);

		foreach ($all as $node) {
			if ($node === 'tickets') {
				continue;
			}
			$this->_link(preg_replace('@[^/]+@', '..', $target) . $source . $node, $target . $node);
		}

		$this->source($currentSource);
	}

/**
 * create a symlink from one file/folder to another location
 *
 * @param string $from
 * @param string $to
 * @return bool
 */
	protected function _link($from, $to) {
		if (file_exists($to)) {
			$this->log(sprintf('skipping %s, already exists', $this->_shortPath($to)), LOG_DEBUG);
			return false;
		}

		$this->log(sprintf('linking %s', $this->_shortPath($to)), LOG_INFO);
		if (!is_dir(dirname($to))) {
			mkdir(dirname($to), 0777, true);
		}
		return symlink($from, $to);
	}

/**
 * Link the common files that are in a lighthouse export
 *
 * @param string $source
 * @param string $target
 * @return bool
 */
	protected function _linkCommon($source, $target) {
	}

/**
 * _renumberTicket
 *
 * Reformat the ticket to be a six digit, 0-padded number with the original slug
 * Then create a symlink to the original ticket
 *
 * @param string $ticketId
 * @param string $fromDir
 * @param string $toDir
 * @return bool
 */
	protected function _renumberTicket($ticketId, $fromDir, $toDir) {
		list($id, $slug) = sscanf($ticketId, '%d-%s');
		$to = str_pad($id, 6, '0', STR_PAD_LEFT) . '-' . $slug;

		$target = $toDir . '/' . $to;
		$project = basename(dirname(dirname($target)));

		return $this->_link(preg_replace('@[^/]+@', '..', $toDir) . '/' . $fromDir . '/' . $ticketId, $target);
	}

/**
 * Use a shorter path in log messages
 *
 * @param string $path
 * @return string
 */
	protected function _shortPath($path) {
		return preg_replace('@.*/([^/]*)/projects/(.*)@', '\2', $path);
	}
}
