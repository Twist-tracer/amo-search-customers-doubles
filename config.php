<?php
return [
	'db' => [
		'host' => 'localhost',
		'db' => 'my_db',
		'user' => 'root',
		'password' => '',
		'port' => 3306,
	],
	'api' => [
		'host' => 'amocrm.ru',
		'subdomain' => '',
		'user_login' => '',
		'user_hash' => '',
		'cookie_path' => __DIR__.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'cookies',

		'limit_rows' => 300
	],
	'main' => [
		'double_tag' => 'double'
	]
];
