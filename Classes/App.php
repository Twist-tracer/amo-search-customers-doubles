<?php

class App {

	private $db;
	private $api;
	private $cli;
	private $logger;

	public $acc_info;

	public function __construct(DB $db, AmoCRM_API $api, Cli $cli) {
		$this->db = $db;
		$this->api = $api;
		$this->cli = $cli;
		$this->logger = new Logger($cli, $db);

		$this->acc_info = $this->get_account_info();
	}

	/**
	 * Возвращает информацию по аккаунту в случае успеха, и false в противном случае
	 * @return array|bool
	 */
	public function get_account_info() {
		$this->cli->show_message("Getting account info...");

		if($log = $this->db->get_last_record('account')) {
			$account_info = json_decode($log->data, TRUE);
		} else {
			$account_info = $this->api->get_account();
			$account_info = $account_info['account'];
			$this->db->add('account', [
				[
					'id' => $account_info['id'],
					'data' => json_encode($account_info),
				]
			]);
		}

		$this->cli->show_message("Account info has been received");
		echo PHP_EOL;

		return $account_info ?: FALSE;
	}

	/**
	 * Получает список сущностей и сохрает их в локальной базе
	 * @param $entity_name
	 * @param $step_id
	 * @param $progress_info
	 */
	public function download($entity_name, $step_id, $progress_info) {
		$this->step_start($step_id, "Getting $entity_name by API...");

		$counter = !empty($progress_info['received']) ? $progress_info['received'] : 0;
		$search = [
			'limit_rows' => API_LIMIT_ROWS,
			'limit_offset' => $counter,
		];

		while($entities = $this->api->find($entity_name, $search)) {
			$data = [];
			foreach($entities as $entity) {
				$tmp = [
					'id' => $entity['id'],
					'name' => $entity['name'],
					'data' => json_encode($entity)
				];

				if($entity_name === 'leads') {
					$tmp['pipeline_id'] = $entity['pipeline_id'];
				}

				$data[] = $tmp;
			}
			unset($tmp);

			$this->db->add($entity_name, $data);
			$search['limit_offset'] += API_LIMIT_ROWS;
			$counter += count($entities);

			$this->logger->update($step_id, [
				'entity' => $entity_name,
				'received' => $counter
			]);

			$this->cli->show_progress($counter);
		}

		$this->step_end($step_id, "$entity_name has been downloaded");
	}

	private function merge($type, $entity_name, $step_id, $progress_info) {

	}

	/**
	 * Переносит недостоющуу информацию из дублей в основную сущность и помечает дублю тегом для последующего удаления
	 * @param $entity_name
	 * @param $step_id
	 * @param $progress_info
	 */
	public function merge_fields($entity_name, $step_id, $progress_info) {
		$custom_fields = $this->acc_info['custom_fields'];

		$this->cli->show_message("Step $step_id. Merge $entity_name fields...");

		$log_isset = $this->db->find('runtime_log', 'COUNT(*) as count', ['step_id' => $step_id]);
		$log_isset = reset($log_isset)->count > 0;

		if(!$log_isset) {
			$this->db->add('runtime_log', [
				[
					'action' => 'merge_fields',
					'date_start' => time()
				]
			]);
		}

		$offset = !empty($progress_info['merged']) ? $progress_info['merged'] : 0;
		$entities = $this->db->search_doubles($entity_name, $offset);

		$upd_data = [];
		foreach($entities as $entity) {
			$order = [
				0 => $entity_name === 'leads' ? ['pipeline_id', 'id'] : ['id'],
				1 => 'ASC'
			];

			$doubles = $this->db->find($entity_name, '*', ['name' => $entity->name], $order);
			$main_entity = array_shift($doubles);
			$main_entity_data = json_decode($main_entity->data, TRUE);

			foreach($doubles as $double) {
				$double_entity_data = json_decode($double->data, TRUE);

				// Сначала перенесем все поля в основную сделку
				foreach($custom_fields[$entity_name] as $field) {
					// Если поле не заполнено в первой сделке
					if(!in_array($field['id'], array_column($main_entity_data['custom_fields'], 'id'))) {

						// Но есть в дублях - обновляем поле
						$index = array_search($field['id'], array_column($double_entity_data['custom_fields'], 'id'));
						if($index !== FALSE) {
							if(!isset($upd_data[$main_entity_data['id']])) {
								// Обновляем когда соберется партия
								if(count($upd_data) === API_LIMIT_ROWS) {
									$this->api->update($entity_name, $upd_data);
									$upd_data = [];
								}

								$upd_data[$main_entity_data['id']] = [
									'id' => $main_entity_data['id'],
									'last_modified' => time(),
									'custom_fields' => $main_entity_data['custom_fields']
								];
							}

							$upd_data[$main_entity_data['id']]['custom_fields'][] = $double_entity_data['custom_fields'][$index];
						}
					}
				}

				$tags = array_column($double_entity_data['tags'], 'name');
				if(!in_array(DOUBLE_TAG, $tags)) {
					$tags[] = DOUBLE_TAG;

					// Теперь проставим теги у дублей
					// Обновляем когда соберется партия
					if(count($upd_data) === API_LIMIT_ROWS) {
						$this->api->update($entity_name, $upd_data);
						$upd_data = [];
					}

					$upd_data[$double_entity_data['id']] = [
						'id' => $double_entity_data['id'],
						'last_modified' => time(),
						'tags' => implode(',', $tags)
					];
				}
			}

			// Отправляем остатки, если они есть.
			if(!empty($upd_data)) {
				$this->api->update($entity_name, $upd_data);
				$upd_data = [];
			}

			$this->db->update('runtime_log', [
				'step_info' => json_encode([
					'entity' => $entity_name,
					'merged' => ++$offset,
				])
			], ['step_id' => $step_id]);

			$this->cli->show_progress($offset);
		}

		$this->db->update('runtime_log', [
			'date_end' => time()
		], ['step_id' => $step_id]);

		$this->cli->show_message("$entity_name was merged");
		echo PHP_EOL;
	}

	/**
	 * Перемещает все задачи из дублей в главную сделку
	 * @param $entity_name
	 * @param $step_id
	 * @param $progress_info
	 */
	public function merge_tasks($entity_name, $step_id, $progress_info) {
		$this->cli->show_message("Step $step_id. Merge $entity_name tasks...");

		$log_isset = $this->db->find('runtime_log', 'COUNT(*) as count', ['step_id' => $step_id]);
		$log_isset = reset($log_isset)->count > 0;

		if(!$log_isset) {
			$this->db->add('runtime_log', [
				[
					'action' => 'merge_tasks',
					'date_start' => time()
				]
			]);
		}

		$offset = !empty($progress_info['merged']) ? $progress_info['merged'] : 0;
		$task_moved = !empty($progress_info['task_moved']) ? $progress_info['task_moved'] : 0;
		$entities = $this->db->search_doubles($entity_name, $offset);

		$upd_data = [];
		foreach($entities as $entity) {
			$order = [
				0 => $entity_name === 'leads' ? ['pipeline_id', 'id'] : ['id'],
				1 => 'ASC'
			];

			$doubles = $this->db->find($entity_name, '*', ['name' => $entity->name], $order);
			$main_entity = array_shift($doubles);

			foreach($doubles as $double) {
				$search = [
					'type' => $this->api->tasks_types[$entity_name],
					'element_id' => $double->id,
					'limit_rows' => API_LIMIT_ROWS,
					'limit_offset' => 0
				];

				while($tasks = $this->api->find(AmoCRM_API::ELEMENT_TASKS, $search)) {
					if(!empty($tasks)) {
						foreach($tasks as $task) {
							// Обновляем когда соберется партия
							if(count($upd_data) === API_LIMIT_ROWS) {
								$this->api->update(AmoCRM_API::ELEMENT_TASKS, $upd_data);
								$task_moved += API_LIMIT_ROWS;
								$upd_data = [];
							}

							$upd_data[] = [
								'id' => $task['id'],
								'element_id' => $main_entity->id,
								'last_modified' => time(),
							];
						}

						$search['limit_offset'] += API_LIMIT_ROWS;
					}

				}

			}

			// Отправляем остатки, если они есть.
			if(!empty($upd_data)) {
				$this->api->update(AmoCRM_API::ELEMENT_TASKS, $upd_data);
				$task_moved += count($upd_data);
				$upd_data = [];
			}

			$this->db->update('runtime_log', [
				'step_info' => json_encode([
					'entity' => $entity_name,
					'merged' => ++$offset,
					'task_moved' => $task_moved
				])
			], ['step_id' => $step_id]);

			$this->cli->show_progress($task_moved);
		}

		$this->db->update('runtime_log', [
			'date_end' => time()
		], ['step_id' => $step_id]);

		$this->cli->show_message("leads tasks was moved");
		echo PHP_EOL;
	}

	/**
	 * TODO Реализовать
	 */
	public function merge_chats($entity_name, $step_id, $progress_info) {
		$this->cli->show_message("Step $step_id. Merge $entity_name chats...");

		$log_isset = $this->db->find('runtime_log', 'COUNT(*) as count', ['step_id' => $step_id]);
		$log_isset = reset($log_isset)->count > 0;

		if(!$log_isset) {
			$this->db->add('runtime_log', [
				[
					'action' => 'merge_chats',
					'date_start' => time()
				]
			]);
		}

		$this->db->update('runtime_log', [
			'date_end' => time()
		], ['step_id' => $step_id]);

		$this->cli->show_message("leads chats was moved");
		echo PHP_EOL;
	}

	public function merge_notes($entity_name, $step_id, $progress_info) {
		$this->cli->show_message("Step $step_id. Merge $entity_name notes...");

		$log_isset = $this->db->find('runtime_log', 'COUNT(*) as count', ['step_id' => $step_id]);
		$log_isset = reset($log_isset)->count > 0;

		if(!$log_isset) {
			$this->db->add('runtime_log', [
				[
					'action' => 'merge_notes',
					'date_start' => time()
				]
			]);
		}

		$offset = !empty($progress_info['merged']) ? $progress_info['merged'] : 0;
		$notes_moved = !empty($progress_info['notes_moved']) ? $progress_info['notes_moved'] : 0;
		$entities = $this->db->search_doubles($entity_name, $offset);

		$upd_data = [];
		foreach($entities as $entity) {
			$order = [
				0 => $entity_name === 'leads' ? ['pipeline_id', 'id'] : ['id'],
				1 => 'ASC'
			];

			$doubles = $this->db->find($entity_name, '*', ['name' => $entity->name], $order);
			$main_entity = array_shift($doubles);
			$main_customer_note_isset = FALSE;

			$search = [
				'type' => $this->api->notes_types[$entity_name],
				'element_id' => $main_entity->id,
				'limit_rows' => API_LIMIT_ROWS,
				'limit_offset' => 0
			];

			while($notes = $this->api->find(AmoCRM_API::ELEMENT_TASKS, $search)) {
				$index = array_search(AmoCRM_API::NOTE_CUSTOMERS_TYPE, array_column($notes, 'note_type'));
				if($index !== FALSE) {
					$note = $notes[$index];
					$note['text'] =  json_decode($note['text'], TRUE);

					$main_customer_id = $this->get_main_customer_id_by_double_id($note['text']['customer_id']);
					if(($note['text']['customer_id'] !== $main_customer_id) || ($note['text']['lead_id'] !== $main_entity->id)) {
						$upd_data[] = [
							'id' => $note['id'],
							'element_id' => $main_entity->id,
							'last_modified' => time(),
							'text' => json_encode([
								'lead_id' => $main_entity->id,
								'customer_id' => $main_customer_id ?: $note['text']['customer_id'],
							])
						];
					}

					$main_customer_note_isset = TRUE;
					break;
				}

				$search['limit_offset'] += API_LIMIT_ROWS;
			}

			foreach($doubles as $double) {
				$search = [
					'type' => $this->api->notes_types[$entity_name],
					'element_id' => $double->id,
					'limit_rows' => API_LIMIT_ROWS,
					'limit_offset' => 0
				];

				while($notes = $this->api->find(AmoCRM_API::ELEMENT_NOTES, $search)) {
					if (!empty($notes)) {
						$customer_note_updated = FALSE;

						foreach ($notes as $note) {
							// Обновляем когда соберется партия
							if(count($upd_data) === API_LIMIT_ROWS) {
								$this->api->update(AmoCRM_API::ELEMENT_NOTES, $upd_data);
								$notes_moved += API_LIMIT_ROWS;
								$upd_data = [];
							}

							switch($note['note_type']) {
								// Пропускаем переходы по статусам
								case AmoCRM_API::NOTE_STATUS_TYPE:
								case AmoCRM_API::NOTE_ENTITY_ADD_TYPE:
									continue 2;
								case AmoCRM_API::NOTE_CUSTOMERS_TYPE:
									if (!$main_customer_note_isset && !$customer_note_updated) {
										$note['text'] = json_decode($note['text'], TRUE);

										$main_customer_id = $this->get_main_customer_id_by_double_id($note['text']['customer_id']);
										$upd_data[] = [
											'id' => $note['id'],
											'element_id' => $main_entity->id,
											'last_modified' => time(),
											'text' => json_encode([
												'lead_id' => $main_entity->id,
												'customer_id' => $main_customer_id ?: $note['text']['customer_id'],
											])
										];

										$customer_note_updated = TRUE;
									}
									break;
								default:
									$upd_data[] = [
										'id' => $note['id'],
										'element_id' => $main_entity->id,
										'last_modified' => time(),
										'text' => $note['text']
									];
							}
						}

						$search['limit_offset'] += API_LIMIT_ROWS;
					}
				}
			}

			// Отправляем остатки, если они есть.
			if(!empty($upd_data)) {
				$this->api->update(AmoCRM_API::ELEMENT_NOTES, $upd_data);
				$notes_moved += count($upd_data);
				$upd_data = [];
			}

			$this->db->update('runtime_log', [
				'step_info' => json_encode([
					'entity' => $entity_name,
					'merged' => ++$offset,
					'notes_moved' => $notes_moved
				])
			], ['step_id' => $step_id]);

			$this->cli->show_progress($notes_moved);
		}

		$this->db->update('runtime_log', [
			'date_end' => time()
		], ['step_id' => $step_id]);

		$this->cli->show_message("leads notes was moved");
		echo PHP_EOL;
	}

	public function merge_contacts($entity_name, $contact_type, $step_id, $progress_info) {
		$this->cli->show_message("Step $step_id. Merge $entity_name $contact_type...");

		$log_isset = $this->db->find('runtime_log', 'COUNT(*) as count', ['step_id' => $step_id]);
		$log_isset = reset($log_isset)->count > 0;

		if(!$log_isset) {
			$this->db->add('runtime_log', [
				[
					'action' => 'merge_'.$contact_type,
					'date_start' => time()
				]
			]);
		}

		$offset = !empty($progress_info['merged']) ? $progress_info['merged'] : 0;
		$contacts_moved = !empty($progress_info[$contact_type.'_moved']) ? $progress_info[$contact_type.'_moved'] : 0;
		$entities = $this->db->search_doubles($entity_name, $offset);

		$upd_data = [];
		foreach($entities as $entity) {
			$order = [
				0 => $entity_name === 'leads' ? ['pipeline_id', 'id'] : ['id'],
				1 => 'ASC'
			];

			$doubles = $this->db->find($entity_name, '*', ['name' => $entity->name], $order);
			$main_entity = reset($doubles);
			$main_entity = json_decode($main_entity->data, TRUE);

			switch($contact_type) {
				case AmoCRM_API::ELEMENT_CONTACTS:
					$ids = array_map(function($e) {
						return $e->id;
					}, $doubles);

					$results = $this->api->get_leads_links($ids);

					$contacts_ids = array_column(
						array_filter($results, function($e) use ($main_entity) {
							return $main_entity['id'] === $e['lead_id'];
						}), 'contact_id'
					);

					$need_contacts_ids = array_unique(
						array_column($results, 'contact_id')
					);

					$contacts_ids =	array_filter($need_contacts_ids, function($e) use ($contacts_ids) {
						return !in_array($e, $contacts_ids);
					});

					foreach($contacts_ids as $contact_id) {
						$upd_data[] = [
							'from' => $contact_type,
							'from_id' => $contact_id,
							'to' => $entity_name,
							'to_id' => $main_entity['id'],
						];
					}

					break;
				case AmoCRM_API::ELEMENT_COMPANIES:
					if(!empty($main_entity['linked_company_id'])) {
						continue 2;
					}

					foreach($doubles as $double) {
						$double = json_decode($double->data, TRUE);
						if(!empty($double['linked_company_id'])) {
							$upd_data[] = [
								'id' => $main_entity['id'],
								'linked_company_id' => $double['linked_company_id'],
								'last_modified' => time()
							];
						}
					}

					break;
			}

			if(count($upd_data) >= API_LIMIT_ROWS) {
				$contact_type === AmoCRM_API::ELEMENT_CONTACTS
					? $this->api->link(AmoCRM_API::ELEMENT_LINKS, $upd_data)
					: $this->api->update($entity_name, $upd_data);

				$contacts_moved += API_LIMIT_ROWS;
				$offset++;
				$this->cli->show_progress($contacts_moved);
				$upd_data = [];
			}

			$this->db->update('runtime_log', [
				'step_info' => json_encode([
					'entity' => $entity_name,
					'merged' => $offset,
					$contact_type.'_moved' => $contacts_moved
				])
			], ['step_id' => $step_id]);

		}

		// Отправляем остатки, если они есть.
		if(!empty($upd_data)) {
			$contact_type === AmoCRM_API::ELEMENT_CONTACTS
				? $this->api->link(AmoCRM_API::ELEMENT_LINKS, $upd_data)
				: $this->api->update($entity_name, $upd_data);
			$contacts_moved += count($upd_data);
			$this->cli->show_progress($contacts_moved);

			$this->db->update('runtime_log', [
				'step_info' => json_encode([
					'entity' => $entity_name,
					'merged' => ++$offset,
					$contact_type.'_moved' => $contacts_moved
				])
			], ['step_id' => $step_id]);
		}

		$this->db->update('runtime_log', [
			'date_end' => time()
		], ['step_id' => $step_id]);

		$this->cli->show_message("$entity_name $contact_type was moved");
		echo PHP_EOL;
	}

	public function remove_doubles($entity_name, $step_id, $progress_info) {
		$this->cli->show_message("Step $step_id. Remove $entity_name...");

		$log_isset = $this->db->find('runtime_log', 'COUNT(*) as count', ['step_id' => $step_id]);
		$log_isset = reset($log_isset)->count > 0;

		if(!$log_isset) {
			$this->db->add('runtime_log', [
				[
					'action' => 'remove_'.$entity_name,
					'date_start' => time()
				]
			]);
		}
		$entities_removed = !empty($progress_info['removed']) ? $progress_info['removed'] : 0;
		$entities = $this->db->search_doubles($entity_name);

		$upd_data = [];
		foreach($entities as $entity) {
			$order = [
				0 => $entity_name === 'leads' ? ['pipeline_id', 'id'] : ['id'],
				1 => 'ASC'
			];

			$doubles = $this->db->find($entity_name, '*', ['name' => $entity->name], $order);

			array_shift($doubles);

			$ids = array_map(function($e) {
				return $e->id;
			}, $doubles);

			// Удаляем когда соберется партия
			if(count($upd_data) === API_LIMIT_ROWS) {
				// TODO Раскоменить
				// $this->api->remove($entity_name, $upd_data);
				$this->db->remove($entity_name, ['id' => $upd_data]);
				$entities_removed += API_LIMIT_ROWS;
				$upd_data = [];
			}
			$upd_data = array_merge($upd_data, $ids);

			$this->db->update('runtime_log', [
				'step_info' => json_encode([
					'entity' => $entity_name,
					'deleted' => $entities_removed
				])
			], ['step_id' => $step_id]);

			$this->cli->show_progress($entities_removed);
		}

		// Удаялем остатки, если они есть.
		if(!empty($upd_data)) {
			// TODO Раскоменить
			// $this->api->remove($entity_name, $upd_data);
			$this->db->remove($entity_name, ['id' => $upd_data]);
			$entities_removed += count($upd_data);
			$this->cli->show_progress($entities_removed);

			$this->db->update('runtime_log', [
				'step_info' => json_encode([
					'entity' => $entity_name,
					'deleted' => $entities_removed,
				])
			], ['step_id' => $step_id]);
		}

		$this->db->update('runtime_log', [
			'date_end' => time()
		], ['step_id' => $step_id]);

		$this->cli->show_message("$entity_name removed");
		echo PHP_EOL;
	}

	private function get_main_customer_id_by_double_id($double_id) {
		$double = $this->db->find(AmoCRM_API::ELEMENT_CUSTOMERS, ['name'], ['id' => $double_id]);
		$double = reset($double);

		if(!isset($double->name)) {
			return FALSE;
		}

		$main = $this->db->find(AmoCRM_API::ELEMENT_CUSTOMERS, 'id', ['name' => $double->name], [['id'], 'ASC'], ['limit' => 1]);

		return isset(reset($main)->id) ? reset($main)->id : FALSE;
	}

	private function step_start($step_id, $description) {
		$this->cli->show_message("Step $step_id. $description");

		if(!$this->logger->log_exist($step_id)) {
			$this->logger->add($description);
		}
	}

	private function step_end($step_id, $description) {
		$this->db->update('runtime_log', [
			'date_end' => time()
		], ['step_id' => $step_id]);

		$this->cli->show_message($description);
		echo PHP_EOL;
	}
}