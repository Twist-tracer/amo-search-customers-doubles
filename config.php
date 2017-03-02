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
		'subdomain' => 'amouser',
		'user_login' => 'amouser@mail.ru',
		'user_hash' => '860b925be8cccfd9a52c6f3773a67083',
		'cookie_path' => __DIR__.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'cookies',

		'limit_rows' => 300
	],
	'main' => [
		'double_tag' => 'double'
	]
];
