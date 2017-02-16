#!/usr/bin/env php
<?php
set_time_limit(0);
$cli = new Cli();
$config = require 'config.php';

define('API_LIMIT_ROWS', 5);
$api_offset = 0;

function __autoload($classname) {
	$filename = __DIR__.DIRECTORY_SEPARATOR."Classes".DIRECTORY_SEPARATOR. $classname .".php";
	require_once $filename;
}

function answer($message) {
	global $cli;
	$return = FALSE;

	echo $cli->getColoredString($message . " 'Y/N':", 'yellow').PHP_EOL;
	$response = fgets(STDIN);
	if(strtoupper(trim($response)) === 'Y') {
		$return = TRUE;
	} elseif(strtoupper(trim($response)) === 'N') {
		$return = FALSE;
	} else {
		echo $cli->getColoredString("Wrong response! Choose 'Y' OR 'N'", 'red').PHP_EOL;
		answer($message);
	}

	return $return;
}

$db = DB::getInstance(
	$config['db']['host'],
	$config['db']['db'],
	$config['db']['user'],
	$config['db']['password']
);
$db->create_tables();

$api = new AmoCRM_API(new Curl(), $config['api']);
$api->auth();

if($db->data_isset()) { // Если в таблицах есть данные
	if(answer('Continue?')) { // Спросим продолжить или начать проверку заново
		$last_action = $db->get_last_action();
		goto step_2;
	} else {
		$db->clear_data();
	}
}

step_1:
echo $cli->getColoredString('Step 1. Getting leads by API...', 'green').PHP_EOL;
$search = [
	'limit_rows' => API_LIMIT_ROWS,
	'limit_offset' => $api_offset
];
// Качаем все сделки
while($leads = $api->find('leads', $search)) {
	var_dump($search);
	$data = [];
	foreach($leads as $lead) {
		$data[] = [
			'id' => $lead['id'],
			'name' => $lead['name'],
			'pipeline_id' => $lead['pipeline_id'],
			'data' => json_encode($lead)
		];
	}

	$db->add('leads', $data);
	$search['limit_offset'] += API_LIMIT_ROWS;
}

step_2:
echo 'step_2';
