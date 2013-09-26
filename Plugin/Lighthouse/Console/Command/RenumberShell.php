<?php

class RenumberShell extends AppShell {

	public $tasks = ['Lighthouse.LH'];

	public function getOptionParser() {
		$parser = parent::getOptionParser();
		$parser
			->description('Rename export files so they are in numerical order')
			->epilog('Export files are of the format, e.g. 1-slug, 10-slug. This means that tickets are not processed in numerical order. This shell creates a symlink for each ticket from the export file in the format 999999-slug. In this way tickets are then processed in numerical order.');

		return $parser;
	}

	public function main() {
		$projects = $this->args;
		if (!$projects) {
			$projects = $this->LH->projects();
		}

		foreach ($projects as $project) {
			$this->LH->LHProject->project($project);
			$this->LH->LHProject->renumber();
		}
	}
}
