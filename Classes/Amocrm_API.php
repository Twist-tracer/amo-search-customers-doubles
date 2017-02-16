<?php

class AmoCRM_API {

	const ELEMENT_LEADS = 'leads';
	const ELEMENT_CONTACTS = 'contacts';
	const ELEMENT_COMPANIES = 'companies';
	const ELEMENT_TASKS = 'tasks';
	const ELEMENT_NOTES = 'notes';
	const ELEMENT_PIPELINES = 'pipelines';
	const ELEMENT_NOTIFICATIONS = 'notifications';
	const ELEMENT_CUSTOMERS = 'customers';
	const ELEMENT_CUSTOMERS_PERIODS = 'customers_periods';
	const ELEMENT_TRANSACTIONS = 'transactions';
	const ELEMENT_LINKS = 'links';
	const ELEMENT_INBOX = 'inbox';

	const CONTACTS_TYPE =  1;
	const LEADS_TYPE =  2;
	const COMPANIES_TYPE =  3;
	const TASKS_TYPE =  4;
	const NOTES_TYPE =  5;
	const CUSTOMERS_TYPE =  12;
	const TRANSACTIONS_TYPE =  13;

	protected
		/** @var Curl */
		$_curl,
		/** @var array */
		$_account,
		/** @var string */
		$_protocol,
		/** @var string */
		$_subdomain,
		/** @var string */
		$_host,
		/** @var string */
		$_login,
		/** @var string */
		$_api_hash,
		/** @var string */
		$_user_agent = 'amoCRM-API-client/2.0',
		/** @var bool */
		$_authorized = FALSE,
		/** @var string */
		$_cookie_file,
		/** @var string */
		$_cookie_path,
		/** @var bool Использовать ли куки */
		$_use_cookies = TRUE,
		/** @var bool Авторизация по GET-параметрам: USER_LOGIN & USER_HASH */
		$_query_authorization = FALSE,
		/** @var null|array */
		$_response_info,
		/** @var null|mixed */
		$_raw_response = NULL,
		/** @var array */
		$_last_request = [],
		/** @var null|mixed */
		$_response = NULL,
		$_timeout = 20,
		$_methods = [
			'auth'                  => '/private/api/auth.php?type=json',
			'secret_auth'           => '/private/api/secret_auth.php',
			'account'               => '/private/api/v2/json/accounts/current',
			'company'               => '/private/api/v2/json/company/list',
			'company_set'           => '/private/api/v2/json/company/set',
			'contacts'              => '/private/api/v2/json/contacts/list',
			'contacts_set'          => '/private/api/v2/json/contacts/set',
			'contacts_links'        => '/private/api/v2/json/contacts/links',
			'leads'                 => '/private/api/v2/json/leads/list',
			'leads_set'             => '/private/api/v2/json/leads/set',
			'notes'                 => '/private/api/v2/json/notes/list',
			'notes_set'             => '/private/api/v2/json/notes/set',
			'tasks_set'             => '/private/api/v2/json/tasks/set',
			'pipelines'             => '/private/api/v2/json/pipelines/list',
			'notifications'         => '/private/api/v2/json/notifications/list',
			'notifications_set'     => '/private/api/v2/json/notifications/set',
			'customers'             => '/private/api/v2/json/customers/list',
			'customers_set'         => '/private/api/v2/json/customers/set',
			'customers_periods'     => '/private/api/v2/json/customers_periods/list',
			'customers_periods_set' => '/private/api/v2/json/customers_periods/set',
			'transactions'          => '/private/api/v2/json/transactions/list',
			'transactions_set'      => '/private/api/v2/json/transactions/set',
			'links'                 => '/private/api/v2/json/links/list',
			'links_set'             => '/private/api/v2/json/links/set',
			'inbox_delete'          => '/private/api/v2/json/inbox/delete',
		],

		$_promo_methods = [
			'accounts/id'      => '/api/accounts/id',
			'accounts/domains' => '/api/accounts/domains',
		],

		$_element_types =
		[
			self::LEADS_TYPE        => self::ELEMENT_LEADS,
			self::CONTACTS_TYPE     => self::ELEMENT_CONTACTS,
			self::COMPANIES_TYPE    => self::ELEMENT_COMPANIES,
			self::TASKS_TYPE        => self::ELEMENT_TASKS,
			self::NOTES_TYPE        => self::ELEMENT_NOTES,
			self::CUSTOMERS_TYPE    => self::ELEMENT_CUSTOMERS,
			self::TRANSACTIONS_TYPE => self::ELEMENT_TRANSACTIONS,
		],

		$_headers = [];

	/**
	 * Устанавливает необходимые параметры для подключения
	 * @param Curl $curl
	 */
	public function __construct(Curl $curl, array $options)
	{
		$this->_curl = $curl;

		$options = array_merge([
			'user_login' => null,
			'user_hash' => null,
			'protocol' => 'https://',
			'subdomain' => 'customers',
			'host' => 'amocrm.ru',
			'cookie_path' => '/tmp',
			'timeout' => 15,
		],$options);

		$this->_timeout = $options['timeout'];

		$this->_login = $options['user_login'];
		$this->_api_hash = $options['user_hash'];

		$this->_protocol = $options['protocol'];
		$this->_subdomain = $options['subdomain'];
		$this->_host = $options['host'];
		$this->_cookie_path = $options['cookie_path'];

		if(!file_exists($this->_cookie_path) || !is_writable($this->_cookie_path)){
			throw new RuntimeException('Cookie path is not writable: '.$this->_cookie_path);
		}

	}

	/**
	 * @param string $subdomain
	 * @param string $login
	 * @param string $api_key
	 * @return $this
	 */
	public function set_auth_data($subdomain, $login, $api_key) {
		$this->_subdomain = $subdomain;
		$this->_login = $login;
		$this->_api_hash = $api_key;

		return $this;
	}

	/**
	 * Использовать ли авторизацию через GET-параметры: USER_LOGIN & USER_HASH
	 * @param bool $bool
	 * @return $this
	 */
	public function use_query_authorization($bool) {
		$this->_query_authorization = (bool)$bool;

		return $this;
	}

	/**
	 * Использовать ли куки
	 * @param bool $bool
	 * @return $this
	 */
	public function use_cookies($bool) {
		$this->_use_cookies = (bool)$bool;

		return $this;
	}

	/**
	 * Добавление HTTP-заголовка для всех запросов
	 * @param string $header
	 * @param string $value
	 * @return $this
	 */
	public function add_header($header, $value) {
		$this->_headers[$header] = $header . ': ' . $value;

		return $this;
	}

	/**
	 * Отправка запроса
	 * @param string $url         URL
	 * @param mixed  $data        Данные для передачи
	 * @param bool   $json_encode Сделать json_encode($data) и передать заголовок Content-Type: application/json
	 * @return array|null
	 */
	protected function send_request($url, $data = NULL, $json_encode = FALSE)
	{
		$this->clear_request_info();

		if ($this->_query_authorization) {
			$auth_params = ['USER_LOGIN' => $this->_login, 'USER_HASH' => $this->_api_hash];
			$url .= ((strpos($url, '?') === FALSE) ? '?' : '&') . http_build_query($auth_params);
		}

		$this->_last_request = [
			'url'         => $url,
			'json_encode' => $json_encode,
		];

		$this->_curl->init($url);
		$this->_curl
			->option(CURLOPT_CONNECTTIMEOUT, $this->_timeout)
			->option(CURLOPT_TIMEOUT, $this->_timeout)
			->option(CURLOPT_USERAGENT, $this->_user_agent);

		if ($this->_use_cookies) {
			$this->_curl
				->option(CURLOPT_COOKIEFILE, $this->_cookie_file)
				->option(CURLOPT_COOKIEJAR, $this->_cookie_file);
		}

		if ($data) {
			$this->_curl
				->option(CURLOPT_CUSTOMREQUEST, 'POST')
				->option(CURLOPT_POST, TRUE)
				->option(CURLOPT_POSTFIELDS, $json_encode ? json_encode($data, JSON_UNESCAPED_UNICODE) : http_build_query($data));

			$this->_last_request['data'] = $data;
		}

		$headers = $this->_headers ?: [];

		if ($json_encode) {
			$headers['Content-Type'] = 'Content-Type: application/json; charset=utf-8';
		}

		if ($headers) {
			$this->_curl->option(CURLOPT_HTTPHEADER, $headers);
		}

		$this->_last_request['headers'] = $headers;

		$res = $this->_curl->exec();
		$this->_response_info = $res->info();
		$this->_raw_response = $res->result();
		$this->_response = json_decode($this->_raw_response, TRUE);

		return $this->_response;
	}

	/**
	 * @param bool $protocol
	 * @return string
	 */
	public function get_host($protocol = TRUE)
	{
		return ($protocol ? $this->_protocol : '') . $this->_subdomain . '.' . $this->_host;
	}

	/**
	 * @return array
	 */
	public function get_last_request() {
		return $this->_last_request;
	}

	/**
	 * @return null|mixed
	 */
	public function get_response() {
		return $this->_response;
	}

	/**
	 * @return null|mixed
	 */
	public function get_raw_response() {
		return $this->_raw_response;
	}

	/**
	 * @return array|null
	 */
	public function get_response_info() {
		return $this->_response_info;
	}

	/**
	 * @return int|null
	 */
	public function get_response_code() {
		return isset($this->_response_info['http_code']) ? (int)$this->_response_info['http_code'] : NULL;
	}

	/**
	 * @return array
	 */
	public function get_full_request_info()
	{
		return [
			'last_request'       => $this->get_last_request(),
			'last_response'      => $this->get_response(),
			'last_raw_response'  => $this->get_raw_response(),
			'last_response_info' => $this->get_response_info(),
		];
	}

	/**
	 * @return $this
	 */
	public function clear_request_info()
	{
		$this->_response = NULL;
		$this->_raw_response = NULL;
		$this->_response_info = NULL;
		$this->_last_request = [];

		return $this;
	}

	/**
	 * @param string $element
	 * @return array|false
	 */
	protected function get_action_element_and_method($element)
	{
		$result = [];

		switch ($element) {
			case 'company':
			case 'companies':
				$result['element'] = self::ELEMENT_CONTACTS;
				$result['method'] = 'company_set';
				break;
			case 'contact':
			case 'contacts':
				$result['element'] = self::ELEMENT_CONTACTS;
				$result['method'] = 'contacts_set';
				break;
			case 'lead':
			case 'leads':
				$result['element'] = self::ELEMENT_LEADS;
				$result['method'] = 'leads_set';
				break;
			case 'note':
			case 'notes':
				$result['element'] = self::ELEMENT_NOTES;
				$result['method'] = 'notes_set';
				break;
			case 'task':
			case 'tasks':
				$result['element'] = self::ELEMENT_TASKS;
				$result['method'] = 'tasks_set';
				break;
			case 'notification':
			case 'notifications':
				$result['element'] = self::ELEMENT_NOTIFICATIONS;
				$result['method'] = 'notifications_set';
				break;
			case 'customers':
				$result['element'] = self::ELEMENT_CUSTOMERS;
				$result['method'] = 'customers_set';
				break;
			case 'customers_periods':
				$result['element'] = self::ELEMENT_CUSTOMERS_PERIODS;
				$result['method'] = 'customers_periods_set';
				break;
			case 'transactions':
				$result['element'] = self::ELEMENT_TRANSACTIONS;
				$result['method'] = 'transactions_set';
				break;
			case 'links':
				$result['element'] = self::ELEMENT_LINKS;
				$result['method'] = 'links_set';
				break;
			case 'inbox_delete':
				$result['element'] = self::ELEMENT_INBOX;
				$result['method'] = 'inbox_delete';
				break;
		}

		return $result ?: FALSE;
	}

	/**
	 * Запрос на авторизацию
	 * @return bool удалось ли автозироваться
	 */
	public function auth()
	{
		if ($this->_authorized) {
			return TRUE;
		}

		$this->_cookie_file = $this->_cookie_path.'/cookie_api_client_'.uniqid($this->_subdomain . '_').'.txt';

		$url = $this->_protocol . $this->_subdomain . '.' . $this->_host . $this->_methods['auth'];
		$data = [
			'USER_LOGIN' => $this->_login,
			'USER_HASH'  => $this->_api_hash,
		];
		$response = $this->send_request($url, $data, TRUE);
		if (!$response || empty($response['response']['auth'])) {
			return FALSE;
		}
		return $this->_authorized = TRUE;
	}

	/**
	 * @return bool
	 */
	public function is_authorized()
	{
		return (bool)$this->_authorized;
	}

	/**
	 * Запрос информации об аккаунте
	 * @return array|bool массив с данными или FALSE в случае ошибки
	 */
	public function get_account()
	{
		$url = $this->_protocol . $this->_subdomain . '.' . $this->_host . $this->_methods['account'];
		$response = $this->send_request($url);
		if (!$response || !empty($response['response']['error'])) {
			return FALSE;
		} else {
			return $response['response'];
		}
	}

	/**
	 * Получение списка сделок, связанных указанными контактами.
	 * @param array $contacts_ids - id контактов
	 * @param array $params - additional query params
	 * @return array|bool
	 */
	public function get_contacts_links($contacts_ids, $params = [])
	{
		return $this->get_entities_links('contacts_link', $contacts_ids, $params);
	}

	/**
	 * Получение списка контактов, связанных указанными сделками.
	 * @param array $leads_ids - id сделок
	 * @param array $params - дополнительные GET-параметры
	 * @return array|bool
	 */
	public function get_leads_links($leads_ids, $params = [])
	{
		return $this->get_entities_links('deals_link', $leads_ids, $params);
	}

	/**
	 * Получение связей между контактами и сделками.
	 * @param string $links_type - contacts_link / deals_link
	 * @param array $ids - id сущностей
	 * @param array $params - дополнительные GET-параметры
	 * @return array|bool
	 */
	protected function get_entities_links($links_type, $ids, $params = [])
	{
		if (!$ids || !is_array($ids) || !($ids = array_unique(array_map('intval', $ids)))) {
			return FALSE;
		}
		if (!in_array($links_type, ['contacts_link', 'deals_link'], TRUE)) {
			return FALSE;
		}

		$params = is_array($params) ? $params : [];
		$query = array_merge($params, [$links_type => $ids]);
		$url = $this->_protocol . $this->_subdomain . '.' . $this->_host . $this->_methods['contacts_links'] . '?' . http_build_query($query);
		$response = $this->send_request($url);

		$result = [];
		if (isset($response['response']['links']) && is_array($response['response']['links'])) {
			$result = $response['response']['links'];
		}

		return $result;
	}

	public function get_contact_to_all_links($contacts_ids) {
		return $this->get_links($contacts_ids, 'contacts', ['leads', 'customers']);
	}

	public function get_company_to_all_links($contacts_ids) {
		return $this->get_links($contacts_ids, 'companies', ['leads', 'customers']);
	}

	/**
	 * @param $ids
	 * @param $from
	 * @param $to
	 * @return array | bool
	 */
	protected function get_links($ids, $from, $to) {

		$links = [];

		if (!is_array($to)) {
			$to = [$to];
		}

		foreach ($to as $entity) {
			if (!array_search($entity, $this->_element_types)) {
				return FALSE;
			}
		}

		if (!array_search($from, $this->_element_types)) {
			return FALSE;
		}

		foreach ($ids as $id) {
			foreach ($to as $entity) {
				$links['links'][] = [
					'from' => $from,
					'from_id' => $id,
					'to' => $entity,
				];
			}
		}

		$url = $this->_protocol . $this->_subdomain . '.' . $this->_host . $this->_methods['links'] . '?' . http_build_query($links);
		$response = $this->send_request($url);

		$result = [];
		if (isset($response['response']['links']) && is_array($response['response']['links'])) {
			$result = $response['response']['links'];
		}

		return $result;
	}

	/**
	 * Поиск по сущности
	 * @param string       $element - contacts | companies (company) | leads | pipelines | notifications | customers
	 * @param string|array $search  - параметры запроса.
	 * Поиск происходит по таким полям, как: почта, телефон и любым иным полям.
	 * Не осуществляется поиск по заметкам и задачам.
	 * @return array|false массив сущностей или FALSE в случае ошибки
	 */
	public function find($element, $search)
	{
		$element = ($element === 'companies') ? 'company' : $element;

		if (!isset($this->_methods[$element])) {
			return FALSE;
		}

		if (is_array($search)) {
			// поиск по параметру ['id' => 123] или ['id' => [123, 456, 789]]
			$query = http_build_query($search);
		} else {
			// Поиск по строке
			$key = ($element === self::ELEMENT_CUSTOMERS) ? 'term' : 'query';
			$query = http_build_query([$key => $search]);
		}

		$url = $this->_protocol . $this->_subdomain . '.' . $this->_host . $this->_methods[$element] . '?' . $query;

		$response = $this->send_request($url);

		if ($element === 'contacts_links') {
			$element = 'links';
		}

		$element = ($element === 'company') ? 'contacts' : $element; // костыль для нашего API, которое компании возвращает как контакты
		if (!$response || empty($response['response'][$element])) {
			return [];
		}

		return $response['response'][$element];
	}

	/**
	 * Добавление сущностей
	 * @param string $element    - company | contacts | leads
	 * @param array $action_data - массив массивов сущностей одного типа
	 * @param array $post_data   - дополнительны данные для POST-запроса
	 * @return bool результат
	 */
	public function add($element, $action_data, $post_data = [])
	{
		return $this->action('add', $element, $action_data, $post_data);
	}


	/**
	 * Обновление сущности
	 * @param $element - company | contacts | leads
	 * @param $data - массив массивов сущностей одного типа
	 * @param array $post_data - дополнительны данные для POST-запроса
	 * @return bool результат
	 */
	public function update($element, $data, $post_data = [])
	{
		return $this->action('update', $element, $data, $post_data);
	}

	/**
	 * POST-запрос на действие
	 * @param string $action
	 * @param string $element
	 * @param array $action_data
	 * @param array $post_data - дополнительны данные для POST-запроса
	 * @return array|false
	 */
	public function action($action, $element, $action_data, array $post_data = []) {
		$result = FALSE;

		if ($params = $this->get_action_element_and_method($element)) {
			$data = [
				'request' => [
					$params['element'] => [
						$action => $action_data,
					],
				],
			];
			$data = array_merge($data, $post_data);
			$response = $this->post_request($element, $data, TRUE);
			$result = $this->return_action_response($response, $params['element'], $action);
		}

		return $result;
	}

	/**
	 * @param string $element
	 * @param array $post_data
	 * @param bool $json_encode
	 * @return array|bool
	 */
	public function post_request($element, $post_data, $json_encode = FALSE)
	{
		$result = FALSE;

		if ($params = $this->get_action_element_and_method($element)) {
			$url = $this->_protocol . $this->_subdomain . '.' . $this->_host . $this->_methods[$params['method']];
			$result = $this->send_request($url, $post_data, $json_encode);
		}

		return $result;
	}

	/**
	 * В этот метод вынесена старая логика возврата результата метода "action".
	 * Данный метод можно переопределить в дочерних классах для реализации другой логики.
	 * @param array $response
	 * @param string $entity
	 * @param string|null $action
	 * @return bool
	 */
	protected function return_action_response($response, $entity, /** @noinspection PhpUnusedParameterInspection */ $action = NULL)
	{
		if (isset($response['response']) && !empty($response['response'][$entity])) {
			return $response['response'][$entity];
		}

		return FALSE;
	}


	/**
	 * Clear authorization
	 * @return $this
	 */
	public function clear_auth() {
		$this->_authorized = FALSE;
		if ($this->_cookie_file && is_file($this->_cookie_file)) {
			unlink($this->_cookie_file);
			$this->_cookie_file = NULL;
		}

		return $this;
	}

	public function  __destruct(){
		$this->clear_auth();
	}

}
