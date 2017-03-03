#!/usr/bin/env php
<?php
set_time_limit(0);
$starttime = time();
$config = require 'config.php';

define('API_LIMIT_ROWS', $config['api']['limit_rows']);
define('DOUBLE_TAG', $config['main']['double_tag']);

function __autoload($classname) {
	$filename = __DIR__.DIRECTORY_SEPARATOR."Classes".DIRECTORY_SEPARATOR. $classname .".php";
	require_once $filename;
}

$cli = new Cli();
$db = DB::getInstance(
	$config['db']['host'],
	$config['db']['db'],
	$config['db']['user'],
	$config['db']['password'],
	$config['db']['charset']
);
$db->create_tables();

$api = new AmoCRM_API(new Curl(), $config['api']);
$api->auth();

if($db->data_isset()) { // Если в таблицах есть данные
	if($cli->answer('Continue?')) { // Спросим продолжить или начать проверку заново
		$last = $db->get_last_action();
		$progress_info = json_decode($last->step_info, TRUE);

		$step = $last->step_id; // TODO заменить обратно
		// $step = empty($last->date_end) ? $last->step_id : $last->step_id+1;
	} else {
		$db->clear_data();
	}
}

$app = new App($db, $api, $cli);

if(!isset($progress_info)) {
	$progress_info = NULL;
}

if(!isset($step)) {
	$step = 1;
}

switch($step) {
	case 1:
		$app->download(AmoCRM_API::ELEMENT_CUSTOMERS, $step, $progress_info);
		$progress_info = NULL;
		$step++;
	case 2:
		$app->merge_fields(AmoCRM_API::ELEMENT_CUSTOMERS, $step, $progress_info);
		$progress_info = NULL;
		$step++;
	case 3:
		$app->download(AmoCRM_API::ELEMENT_LEADS, $step, $progress_info);
		$progress_info = NULL;
		$step++;
	case 4:
		$app->merge_fields(AmoCRM_API::ELEMENT_LEADS, $step, $progress_info);
		$progress_info = NULL;
		$step++;
	case 5:
		$app->merge_tasks(AmoCRM_API::ELEMENT_LEADS, $step, $progress_info);
		$progress_info = NULL;
		$step++;
	case 6:
		$app->merge_chats(AmoCRM_API::ELEMENT_LEADS, $step, $progress_info);
		$progress_info = NULL;
		$step++;
	case 7:
		$app->merge_notes(AmoCRM_API::ELEMENT_LEADS, $step, $progress_info);
		$progress_info = NULL;
		$step++;
	case 8:
		$app->merge_contacts(AmoCRM_API::ELEMENT_LEADS, AmoCRM_API::ELEMENT_CONTACTS, $step, $progress_info);
		$progress_info = NULL;
		$step++;
	case 9:
		$app->merge_contacts(AmoCRM_API::ELEMENT_LEADS, AmoCRM_API::ELEMENT_COMPANIES, $step, $progress_info);
		$progress_info = NULL;
		$step++;
	case 10:
		$app->remove_doubles(AmoCRM_API::ELEMENT_LEADS, $step, $progress_info);
		$progress_info = NULL;
		$step++;
}

$cli->show_message("That's all. While the script ".(time() - $starttime).'c.');