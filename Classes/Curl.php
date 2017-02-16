<?php

class Curl {
	/** @var bool|resource */
	private $_curl = FALSE;
	private $_data = FALSE;


	public function init($url) {
		if ($this->filter('url', $url)) {
			$this->_data = array('url'=>$url);
			$this->_curl = curl_init($url);
			//базовые опции
			$this->option(CURLOPT_FOLLOWLOCATION, TRUE);
			$this->option(CURLOPT_HEADER, FALSE);
			$this->option(CURLOPT_RETURNTRANSFER, TRUE);
			$this->option(CURLOPT_SSL_VERIFYPEER, 0);
			$this->option(CURLOPT_SSL_VERIFYHOST, 0);
		}
		return $this;
	}

	public function option($key, $value) {
		if($this->is_curl()){
			curl_setopt($this->_curl,$key,$value);
		}
		return $this;
	}

	public function exec() {
		if ($this->is_curl()) {
			$this->_data = array(
				'result' => curl_exec($this->_curl),
				'info' => curl_getinfo($this->_curl),
				'error' => curl_error($this->_curl),
				'errno' => curl_errno($this->_curl),
			);
			$this->close();
		} else {
			throw new \Exception(__METHOD__ . ' curl not initialized');
		}
		return $this;
	}

	private function is_curl(){
		return (!empty($this->_curl) && is_resource($this->_curl));
	}

	public function info() {
		return $this->get(__FUNCTION__);
	}

	public function result() {
		return $this->get(__FUNCTION__);
	}

	public function error() {
		return $this->get(__FUNCTION__);
	}

	public function errno() {
		return $this->get(__FUNCTION__);
	}

	private function get($key){
		return isset($this->_data[$key]) ? $this->_data[$key] : FALSE;
	}

	public function close() {
		if ($this->is_curl()) {
			curl_close($this->_curl);
			$this->_curl = FALSE;
		}
	}

	public function resolve($host, $port, $ip){
		if(defined('CURLOPT_RESOLVE')){
			$resolve = [
				sprintf(
					"%s:%d:%s",
					$host,
					$port,
					$ip
				)
			];
			$this->option(CURLOPT_RESOLVE, $resolve);
		}
	}

	private $_types = [
		'int'   => FILTER_VALIDATE_INT,
		'url'   => FILTER_VALIDATE_URL,
		'bool'  => FILTER_VALIDATE_BOOLEAN,
		'email' => FILTER_VALIDATE_EMAIL,
	];

	protected function filter($type, $value) {
		if (isset($this->_types[$type])) {
			if (!filter_var($value, $this->_types[$type])) {
				throw new \Exception(__METHOD__ . ' validate method ' . $type . " fails!\n\n" . var_export([$type, $value], TRUE));
			}
		} else {
			throw new \Exception(__METHOD__ . ' validate method ' . $type . ' not found');
		}
		return TRUE;
	}
}