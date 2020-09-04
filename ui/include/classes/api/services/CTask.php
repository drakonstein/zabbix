<?php
/*
** Zabbix
** Copyright (C) 2001-2020 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


/**
 * Class containing methods for operations with task.
 */
class CTask extends CApiService {

	protected $tableName = 'task';
	protected $tableAlias = 't';
	protected $sortColumns = ['taskid'];

	/*
	 * String constant to retrieve all possible fields for dyagnostic requested.
	 * Must be synchronized with server.
	 */
	const FIELD_ALL = 'all';

	/**
	 * Get results of requested ZBX_TM_TASK_DATA task.
	 *
	 * @param array         $options
	 * @param string|array  $options['output']
	 * @param string|array  $options['taskids']       Task IDs to select data about.
	 * @param bool          $options['preservekeys']  Use IDs as keys in the resulting array.
	 *
	 * @return array | boolean
	 */
	public function get(array $options): array {
		if (self::$userData['type'] < USER_TYPE_SUPER_ADMIN) {
			self::exception(ZBX_API_ERROR_PERMISSIONS, _('You do not have permission to perform this operation.'));
		}

		$output_fields = ['taskid', 'type', 'status', 'clock', 'ttl', 'proxy_hostid', 'request', 'result'];

		$api_input_rules = ['type' => API_OBJECT, 'fields' => [
			// filter
			'taskids' =>		['type' => API_IDS, 'flags' => API_NORMALIZE | API_ALLOW_NULL, 'default' => null],
			// output
			'output' =>			['type' => API_OUTPUT, 'in' => implode(',', $output_fields), 'default' => API_OUTPUT_EXTEND],
			// flags
			'preservekeys' =>	['type' => API_BOOLEAN, 'default' => false]
		]];

		if (!CApiInputValidator::validate($api_input_rules, $options, '/', $error)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, $error);
		}

		$options += [
			'sortfield' => 'taskid',
			'sortorder' => ZBX_SORT_DOWN,
			'limit'		=> select_config()['search_limit']
		];

		$sql_parts = [
			'select'	=> ['task' => 't.taskid'],
			'from'		=> ['task' => 'task t'],
			'where'		=> [
				'type'		=> 't.type='.ZBX_TM_TASK_DATA
			]
		];

		if ($options['taskids'] !== null) {
			$sql_parts['where']['taskid'] = dbConditionInt('t.taskid', $options['taskids']);
		}

		$db_tasks = [];

		$sql_parts = $this->applyQueryOutputOptions($this->tableName(), $this->tableAlias(), $options, $sql_parts);
		$sql_parts = $this->applyQuerySortOptions($this->tableName(), $this->tableAlias(), $options, $sql_parts);

		$result = DBselect($this->createSelectQueryFromParts($sql_parts), $options['limit']);

		while ($row = DBfetch($result)) {
			if ($this->outputIsRequested('request', $options['output'])) {
				$row['request']['data'] = json_decode($row['request_data']);
				unset($row['request_data']);
			}

			if ($this->outputIsRequested('result', $options['output'])) {
				$row['result'] = [
					'data' => $row['result_info'] ? json_decode($row['result_info']) : [],
					'status' => $row['result_status']
				];
				unset($row['result_info'], $row['result_status']);
			}

			$db_tasks[$row['taskid']] = $row;
		}

		if ($db_tasks) {
			$db_tasks = $this->unsetExtraFields($db_tasks, ['taskid', 'clock'], $options['output']);

			if (!$options['preservekeys']) {
				$db_tasks = array_values($db_tasks);
			}
		}

		return $db_tasks;
	}

	/**
	 * Create tasks.
	 *
	 * @param array        $tasks                                       Tasks to create.
	 * @param string|array $tasks[]['type']                             Type of task.
	 * @param string|array $tasks[]['request']
	 * @param array        $tasks[]['request']['data']                  Request object for task to create.
	 * @param string       $tasks[]['request']['data']['itemid']        Must be set for ZBX_TM_TASK_CHECK_NOW task.
	 * @param array        $tasks[]['request']['data']['historycache']  (optional) object of history cache data request.
	 * @param array        $tasks[]['request']['data']['valuecache']    (optional) object of value cache data request.
	 * @param array        $tasks[]['request']['data']['preprocessing'] (optional) object of preprocessing data request.
	 * @param array        $tasks[]['request']['data']['alerting']      (optional) object of alerting data request.
	 * @param array        $tasks[]['request']['data']['lld']           (optional) object of lld cache data request.
	 * @param array        $tasks[]['proxy_hostid']                     (optional) Proxy to get diagnostic data about.
	 *
	 * @return array
	 */
	public function create(array $tasks): array {
		$this->validateCreate($tasks);

		$tasks_by_types = [
			ZBX_TM_DATA_TYPE_CHECK_NOW => [],
			ZBX_TM_DATA_TYPE_DIAGINFO => []
		];

		foreach ($tasks as $index => $task) {
			$tasks_by_types[$task['type']][$index] = $task;
		}

		$return = $this->createTasksCheckNow($tasks_by_types[ZBX_TM_DATA_TYPE_CHECK_NOW]);
		$return += $this->createTasksDiagInfo($tasks_by_types[ZBX_TM_DATA_TYPE_DIAGINFO]);

		ksort($return);

		return ['taskids' => array_values($return)];
	}

	/**
	 * Validates the input for create method.
	 *
	 * @param array $tasks  Tasks to validate.
	 *
	 * @throws APIException if the input is invalid.
	 */
	protected function validateCreate(array &$tasks) {
		$api_input_rules = ['type' => API_OBJECTS, 'flags' => API_NORMALIZE, 'fields' => [
			'type' =>		['type' => API_INT32, 'flags' => API_REQUIRED, 'in' => implode(',', [ZBX_TM_DATA_TYPE_DIAGINFO, ZBX_TM_DATA_TYPE_CHECK_NOW])],
			'request' =>	['type' => API_MULTIPLE, 'flags' => API_REQUIRED, 'rules' => [
								['if' => ['field' => 'type', 'in' => implode(',', [ZBX_TM_DATA_TYPE_DIAGINFO])], 'type' => API_OBJECT, 'flags' => API_REQUIRED | API_NOT_EMPTY, 'fields' => [
					'data' => ['type' => API_OBJECT, 'flags' => API_REQUIRED | API_NOT_EMPTY, 'fields' => [
						'historycache' =>	['type' => API_OBJECT, 'flags' => API_NOT_EMPTY, 'fields' => [
							'stats' =>			['type' => API_STRINGS_UTF8, 'in' => implode(',', [self::FIELD_ALL, 'items', 'values', 'memory', 'memory.data', 'memory.index'])],
							'top' =>			['type' => API_OBJECT, 'flags' => API_NOT_EMPTY, 'fields' => [
								'values' =>			['type' => API_INT32]
							]]
						]],
						'valuecache' =>		['type' => API_OBJECT, 'flags' => API_NOT_EMPTY, 'fields' => [
							'stats' =>			['type' => API_STRINGS_UTF8, 'in' => implode(',', [self::FIELD_ALL, 'items', 'values', 'memory', 'mode'])],
							'top' =>			['type' => API_OBJECT, 'flags' => API_NOT_EMPTY, 'fields' => [
								'values' =>			['type' => API_INT32],
								'request.values' =>	['type' => API_INT32]
							]]
						]],
						'preprocessing' =>	['type' => API_OBJECT, 'flags' => API_NOT_EMPTY, 'fields' => [
							'stats' =>			['type' => API_STRINGS_UTF8, 'in' => implode(',', [self::FIELD_ALL, 'values', 'preproc.values'])],
							'top' =>			['type' => API_OBJECT, 'flags' => API_NOT_EMPTY, 'fields' => [
								'values' =>			['type' => API_INT32]
							]]
						]],
						'alerting' =>		['type' => API_OBJECT, 'flags' => API_NOT_EMPTY, 'fields' => [
							'stats' =>			['type' => API_STRINGS_UTF8, 'in' => implode(',', [self::FIELD_ALL, 'alerts'])],
							'top' =>			['type' => API_OBJECT, 'flags' => API_NOT_EMPTY, 'fields' => [
								'media.alerts' =>	['type' => API_INT32],
								'source.alerts' =>	['type' => API_INT32]
							]]
						]],
						'lld' =>			['type' => API_OBJECT, 'flags' => API_NOT_EMPTY, 'fields' => [
							'stats' =>			['type' => API_STRINGS_UTF8, 'in' => implode(',', [self::FIELD_ALL, 'rules', 'values'])],
							'top' =>			['type' => API_OBJECT, 'flags' => API_NOT_EMPTY, 'fields' => [
								'values' =>			['type' => API_INT32]
							]]
						]]
					]]
								]],
								['if' => ['field' => 'type', 'in' => implode(',', [ZBX_TM_DATA_TYPE_CHECK_NOW])], 'type' => API_OBJECT, 'fields' => [
					'data' => ['type' => API_OBJECT, 'flags' => API_REQUIRED | API_NOT_EMPTY, 'fields' => [
						'itemid' => ['type' => API_ID, 'flags' => API_REQUIRED | API_NOT_EMPTY]
					]]
								]]
				]
			],
			'proxy_hostid' => ['type' => API_ID, 'default' => 0]
		]];

		if (!CApiInputValidator::validate($api_input_rules, $tasks, '/', $error)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, $error);
		}

		$min_permissions = USER_TYPE_ZABBIX_ADMIN;
		$itemids_editable = [];
		$proxy_hostids = [];

		foreach ($tasks as $task) {
			switch ($task['type']) {
				case ZBX_TM_DATA_TYPE_DIAGINFO:
					$min_permissions = USER_TYPE_SUPER_ADMIN;

					$proxy_hostids[$task['proxy_hostid']] = $task['proxy_hostid'];
					break;

				case ZBX_TM_DATA_TYPE_CHECK_NOW:
					$itemids_editable[$task['request']['data']['itemid']] = true;
					break;
			}
		}

		unset($proxy_hostids[0]);

		if (self::$userData['type'] < $min_permissions) {
			self::exception(ZBX_API_ERROR_PERMISSIONS, _('You do not have permission to perform this operation.'));
		}

		$this->checkProxyHostids(array_keys($proxy_hostids));
		$this->checkEditableItems(array_keys($itemids_editable));
	}

	/**
	 * Special validation for ZBX_TM_DATA_TYPE_CHECK_NOW create.
	 *
	 * @param array        $data             Request object for tasks to create.
	 * @param string|array $data['itemid']   Item or LLD rule IDs to create tasks for.
	 *
	 * @throws APIException
	 */
	protected function validateCreateTaskCheckNow(array $data) {
		// Check if user has permissions to items and LLD rules.
		$items = API::Item()->get([
			'output' => ['itemid', 'type', 'hostid', 'status'],
			'itemids' => $data['data']['itemid'],
			'editable' => true
		]);

		$discovery_rules = [];
		$itemids_cnt = count([$data['data']['itemid']]); // TODO
		$items_cnt = count($items);

		if ($items_cnt != $itemids_cnt) {
			$discovery_rules = API::DiscoveryRule()->get([
				'output' => ['itemid', 'type', 'hostid', 'status'],
				'itemids' => $data['data']['itemid'],
				'editable' => true
			]);

			if (count($discovery_rules) + $items_cnt != $itemids_cnt) {
				self::exception(ZBX_API_ERROR_PERMISSIONS,
					_('No permissions to referred object or it does not exist!')
				);
			}
		}

		// Validate item and LLD rule types and statuses, and collect host IDs for later.
		$hostids = [];
		$allowed_types = checkNowAllowedTypes();

		foreach ($items as $item) {
			if (!in_array($item['type'], $allowed_types)) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s('Cannot send request: %1$s.', _('wrong item type')));
			}

			if ($item['status'] != ITEM_STATUS_ACTIVE) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s('Cannot send request: %1$s.', _('item is disabled')));
			}

			$hostids[$item['hostid']] = $item['hostid'];
		}

		foreach ($discovery_rules as $discovery_rule) {
			if (!in_array($discovery_rule['type'], $allowed_types)) {
				self::exception(ZBX_API_ERROR_PARAMETERS,
					_s('Cannot send request: %1$s.', _('wrong discovery rule type'))
				);
			}

			if ($discovery_rule['status'] != ITEM_STATUS_ACTIVE) {
				self::exception(ZBX_API_ERROR_PARAMETERS,
					_s('Cannot send request: %1$s.', _('discovery rule is disabled'))
				);
			}

			$hostids[$discovery_rule['hostid']] = $discovery_rule['hostid'];
		}

		// Check if those are actually hosts because given hostids could actually be templateids.
		$hosts = API::Host()->get([
			'output' => ['status'],
			'hostids' => $hostids,
			'templated_hosts' => true,
			'nopermissions' => true
		]);

		// Check host status. Allow only monitored hosts.
		foreach ($hosts as $host) {
			if ($host['status'] != HOST_STATUS_MONITORED) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s('Cannot send request: %1$s.', _('host is not monitored')));
			}
		}
	}

	/**
	 * Create ZBX_TM_TASK_CHECK_NOW task.
	 *
	 * @param array        $data             Request object for tasks to create.
	 * @param string|array $data['itemid']   Item or LLD rule IDs to create tasks for.
	 *
	 * @throws APIException
	 *
	 * @return array
	 */
	protected function createTasksCheckNow(array $tasks): array {
		return [];

		/*
		// Check if tasks for items and LLD rules already exist.
		$db_tasks = DBselect(
			'SELECT t.taskid,tcn.itemid'.
			' FROM task t, task_check_now tcn'.
			' WHERE t.taskid=tcn.taskid'.
				' AND t.type='.ZBX_TM_TASK_CHECK_NOW.
				' AND t.status='.ZBX_TM_STATUS_NEW.
				' AND '.dbConditionId('tcn.itemid', [$data['data']['itemid']]) // TODO
		);

		$item_tasks = [];

		foreach ($data['data']['itemid'] as $itemid) {
			$item_tasks[$itemid] = 0;
		}

		while ($db_task = DBfetch($db_tasks)) {
			$item_tasks[$db_task['itemid']] = $db_task['taskid'];
		}

		$itemids = [];

		foreach ($item_tasks as $itemid => $taskid) {
			if ($taskid == 0) {
				$itemids[] = $itemid;
			}
		}

		if ($itemids) {
			$taskid = DB::reserveIds('task', count($itemids));
			$ins_tasks = [];
			$ins_check_now_tasks = [];
			$time = time();

			foreach ($itemids as $i => $itemid) {
				$ins_tasks[] = [
					'taskid' => $taskid,
					'type' => ZBX_TM_TASK_CHECK_NOW,
					'status' => ZBX_TM_STATUS_NEW,
					'clock' => $time,
					'ttl' => SEC_PER_HOUR
				];
				$ins_check_now_tasks[] = [
					'taskid' => $taskid,
					'itemid' => $itemid
				];

				$item_tasks[$itemid] = (string) $taskid++;
			}

			DB::insertBatch('task', $ins_tasks, false);
			DB::insertBatch('task_check_now', $ins_check_now_tasks, false);
		}

		return ['taskids' => array_values($item_tasks)];
		*/
	}

	/**
	 * Create ZBX_TM_DATA_TYPE_DIAGINFO tasks.
	 *
	 * @param array    $task[]
	 * @param array    $task[]['request']
	 * @param array    $task[]['request']['data']                  Request object for task to create.
	 * @param array    $task[]['request']['data']['historycache']  (optional) object of history cache data request.
	 * @param array    $task[]['request']['data']['valuecache']    (optional) object of value cache data request.
	 * @param array    $task[]['request']['data']['preprocessing'] (optional) object of preprocessing data request.
	 * @param array    $task[]['request']['data']['alerting']      (optional) object of alerting data request.
	 * @param array    $task[]['request']['data']['lld']	       (optional) object of lld cache data request.
	 * @param array    $task[]['proxy_hostid']                     (optional) object of lld cache data request.
	 *
	 * @throws APIException
	 *
	 * @return array
	 */
	protected function createTasksDiagInfo(array $tasks): array {
		$task_rows = [];
		$task_data_rows = [];

		foreach ($tasks as $index => $task) {
			$task_rows[$index] = [
				'type' => ZBX_TM_TASK_DATA,
				'status' => ZBX_TM_STATUS_NEW,
				'clock' => time(),
				'ttl' => SEC_PER_HOUR,
				'proxy_hostid' => $task['proxy_hostid']
			];
		}

		$task_rows = DB::insertBatch('task', $task_rows);

		foreach ($tasks as $index => $task) {
			$task_data_rows[$index] = [
				'taskid' => $task_rows[$index],
				'type' => $task['type'],
				'data' => json_encode($task['request']['data']),
				'parent_taskid' => $task_rows[$index]
			];
		}

		DB::insertBatch('task_data', $task_data_rows, false);

		return $task_rows;
	}

	protected function applyQueryOutputOptions($tableName, $tableAlias, array $options, array $sql_parts) {
		$sql_parts = parent::applyQueryOutputOptions($tableName, $tableAlias, $options, $sql_parts);

		if ($this->outputIsRequested('request', $options['output'])) {
			$sql_parts['left_join'][] = ['alias' => 'req', 'table' => 'task_data', 'using' => 'parent_taskid'];
			$sql_parts['left_table'] = ['alias' => $this->tableAlias, 'table' => $this->tableName()];

			$sql_parts = $this->addQuerySelect('req.data AS request_data', $sql_parts);
		}

		if ($this->outputIsRequested('result', $options['output'])) {
			$sql_parts['left_join'][] = ['alias' => 'resp', 'table' => 'task_result', 'using' => 'parent_taskid'];
			$sql_parts['left_table'] = ['alias' => $this->tableAlias, 'table' => $this->tableName()];

			$sql_parts = $this->addQuerySelect('resp.info AS result_info', $sql_parts);
			$sql_parts = $this->addQuerySelect('resp.status AS result_status', $sql_parts);
		}

		return $sql_parts;
	}

	/**
	 * Validate user permissions to items and LLD rules;
	 * Check if requested items are allowed to make 'check now' operation;
	 * Check if items are monitored and they belong to monitored hosts.
	 *
	 * @param array $itemids
	 *
	 * @throws Exception
	 */
	protected function checkEditableItems(array $itemids): void {
		// Check permissions.
		$items = API::Item()->get([
			'output' => ['type', 'hostid', 'status', 'flags'],
			'itemids' => $itemids,
			'editable' => true,
			'preservekeys' => true
		]);

		$discovery_rules = [];
		$itemids_cnt = count($itemids);

		if (count($items) != $itemids_cnt) {
			$items += API::DiscoveryRule()->get([
				'output' => ['type', 'hostid', 'status', 'flags'],
				'itemids' => $itemids,
				'editable' => true,
				'preservekeys' => true
			]);

			if (count($items) != $itemids_cnt) {
				self::exception(ZBX_API_ERROR_PERMISSIONS,
					_('No permissions to referred object or it does not exist!')
				);
			}
		}

		// Validate item and LLD rule types and statuses, and collect host IDs for later.
		$hostids = [];
		$allowed_types = checkNowAllowedTypes();

		foreach ($items as $item) {
			if (!in_array($item['type'], $allowed_types)) {
				self::exception(ZBX_API_ERROR_PARAMETERS,
					_s('Cannot send request: %1$s.',
						($item['flags'] == ZBX_FLAG_DISCOVERY_RULE)
							? _('wrong discovery rule type')
							: _('wrong item type')
					)
				);
			}

			if ($item['status'] != ITEM_STATUS_ACTIVE) {
				self::exception(ZBX_API_ERROR_PARAMETERS,
					_s('Cannot send request: %1$s.',
						($item['flags'] == ZBX_FLAG_DISCOVERY_RULE)
							? _('discovery rule is disabled')
							: _('item is disabled')
					)
				);
			}

			$hostids[$item['hostid']] = true;
		}

		// Check if those are actually monitored hosts because given hostids could actually be templateids.
		$hosts = API::Host()->get([
			'output' => [],
			'hostids' => array_keys($hostids),
			'filter' => [
				'status' => HOST_STATUS_MONITORED
			],
			'templated_hosts' => true,
			'nopermissions' => true
		]);

		if (count($hosts) != count($hostids)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _s('Cannot send request: %1$s.', _('host is not monitored')));
		}
	}

	/**
	 * Function to check if specified proxies exists.
	 *
	 * @param array $proxy_hostids  Proxy IDs to check.
	 *
	 * @throws Exception if proxy doesn't exist.
	 */
	protected function checkProxyHostids(array $proxy_hostids): void {
		if (!$proxy_hostids) {
			return;
		}

		$proxies = API::Proxy()->get([
			'countOutput' => true,
			'proxyids' => $proxy_hostids
		]);

		if ($proxies != count($proxy_hostids)) {
			self::exception(ZBX_API_ERROR_PERMISSIONS, _('No permissions to referred object or it does not exist!'));
		}
	}
}
