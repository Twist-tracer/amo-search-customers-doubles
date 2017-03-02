<?php

class Logger {

	private $cli;
	private $db;

	public function __construct(Cli $cli, DB $db) {
		$this->cli = $cli;
		$this->db = $db;
	}

	public function log_exist($step_id) {
		$log_isset = $this->db->find('runtime_log', 'COUNT(*) as count', ['step_id' => $step_id]);
		return reset($log_isset)->count > 0;
	}

	public function add($description) {
		return $this->db->add('runtime_log', [
			[
				'description' => $description,
				'date_start' => time()
			]
		]);
	}

	public function update($step_id, $data) {
		$this->db->update('runtime_log', [
			'step_info' => json_encode($data)
		], ['step_id' => $step_id]);
	}
}