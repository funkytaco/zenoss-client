<?php

namespace Shift31;

use Zend\Http\Client, Zend\Http\Request, Zend\Http\Cookies;


class ZenossClient
{
	protected $ROUTERS
		= array('MessagingRouter' => 'messaging',
				'EventsRouter'    => 'evconsole',
				'ProcessRouter'   => 'process',
				'ServiceRouter'   => 'service',
				'DeviceRouter'    => 'device',
				'NetworkRouter'   => 'network',
				'TemplateRouter'  => 'template',
				'DetailNavRouter' => 'detailnav',
				'ReportRouter'    => 'report',
				'MibRouter'       => 'mib',
				'ZenPackRouter'   => 'zenpack',
		);

	private $_host;
	private $_username;
	private $_password;

	private $_client;
	private $_cookies;
	private $_reqCount = 0;

	protected $_logger;


	/**
	 * @param      $host
	 * @param      $username
	 * @param      $password
	 * @param null $logger
	 */
	public function __construct($host, $username, $password, $logger = null)
	{
		$this->_host = $host;
		$this->_username = $username;
		$this->_password = $password;

		$this->_logger = $logger;

		$this->_client = new Client();
		$this->_cookies = new Cookies();
		$this->_reqCount = 1;


		try {
			$this->_client->setUri('http://' . $this->_host . '/zport/acl_users/cookieAuthHelper/login');

			$this->_client->setParameterPost(
				array(
					 '__ac_name'     => $this->_username,
					 '__ac_password' => $this->_password,
					 'submitted'     => 'true',
					 'came_from'     => $this->_host . '/zport/dmd'
				)
			);

			$response = $this->_client->setMethod(Request::METHOD_POST)->send();

			$this->_cookies->addCookiesFromResponse($response, $this->_client->getUri());

		} catch (\Exception $e) {
			$this->_log('crit', "Exception: " . $e->getCode() . " - " . $e->getMessage());
			$this->_log('debug', "Exception Stack Trace: " . $e->getTraceAsString());
		}

	}


	/**
	 * @param string $router
	 * @param string $method
	 * @param array  $data
	 *
	 * @return string
	 * @throws \Exception
	 */
	protected function _routerRequest($router, $method, $data = array())
	{
		if (!array_key_exists($router, $this->ROUTERS)) {
			try {
				throw new \Exception('Router ' . $router . ' not available.');
			} catch (\Exception $e) {
				$this->_log('crit', $e);
			}
		}

		$this->_client->setUri('http://' . $this->_host . '/zport/dmd/' . $this->ROUTERS[$router] . '_router');
		$this->_client->addCookie($this->_cookies->getMatchingCookies($this->_client->getUri()));

		$headers = $this->_client->getRequest()->getHeaders();

		# NOTE: Content-type MUST be set to 'application/json' for these requests
		$headers->addHeaderLine('Content-type', 'application/json; charset=utf-8');

		# Convert the request parameters into JSON
		$reqData = json_encode(
			array(
				 'action' => $router,
				 'method' => $method,
				 'data'   => $data,
				 'type'   => 'rpc',
				 'tid'    => $this->_reqCount,
			)
		);

		$this->_client->setRawBody($reqData);

		$response = $this->_client->setMethod(Request::METHOD_POST)->send();

		# Increment the request count ('tid'). More important if sending multiple calls in a single request
		$this->_reqCount++;

		$body = json_decode($response->getBody());

		if ($body->type == 'exception') {
			try {
				throw new \Exception($body->message);
			} catch (\Exception $ze) {
				$this->_log('crit', $ze->getMessage());

				return $ze->getMessage();
			}
		}

		return $body->result;
	}


	/**
	 * @param $id
	 *
	 * @return mixed
	 */
	public function getTree($id)
	{
		$parameters = new \stdClass();
		$parameters->id = $id;

		$data = array($parameters);

		$result = $this->_routerRequest('DeviceRouter', 'getTree', $data);

		return $result[0];
	}


	/**
	 * @param null   $uid
	 * @param null   $meta_type
	 * @param null   $keys
	 * @param int    $start
	 * @param int    $limit
	 * @param string $sort
	 * @param string $dir
	 * @param null   $name
	 *
	 * @return mixed
	 */
	public function getComponents(
		$uid = null, $meta_type = null, $keys = null, $start = 0,
		$limit = 50, $sort = 'name', $dir = 'ASC', $name = null
	) {
		$parameters = new \stdClass();
		$parameters->uid = $uid;
		$parameters->meta_type = $meta_type;
		if (!is_null($keys)) {
			$parameters->keys = $keys;
		}
		$parameters->start = $start;
		$parameters->limit = $limit;
		$parameters->sort = $sort;
		$parameters->dir = $dir;
		$parameters->name = $name;

		$data = array($parameters);

		/** @noinspection PhpUndefinedFieldInspection */

		return $this->_routerRequest('DeviceRouter', 'getComponents', $data)->data;
	}


	/**
	 * @param null $uid
	 *
	 * @return mixed
	 */
	public function getComponentTree($uid = null)
	{
		$parameters = new \stdClass();
		$parameters->uid = $uid;

		$data = array($parameters);

		return $this->_routerRequest('DeviceRouter', 'getComponentTree', $data);
	}


	/**
	 * @param      $uid
	 * @param null $keys
	 *
	 * @return mixed
	 */
	public function getInfo($uid, $keys = null)
	{
		$parameters = new \stdClass();
		$parameters->uid = $uid;
		if (!is_null($keys)) {
			$parameters->keys = $keys;
		}

		$data = array($parameters);

		/** @noinspection PhpUndefinedFieldInspection */

		return $this->_routerRequest('DeviceRouter', 'getInfo', $data)->data;
	}


	/**
	 * @param null   $uid
	 * @param int    $start
	 * @param array  $params
	 * @param int    $limit
	 * @param string $sort
	 * @param string $dir
	 *
	 * @return mixed
	 */
	public function getDevices($uid = null, $start = 0, $params = array(), $limit = 50, $sort = 'name', $dir = 'ASC')
	{
		$parameters = new \stdClass();
		$parameters->uid = $uid;
		$parameters->start = $start;
		$parameters->params = (object)$params;
		$parameters->limit = $limit;
		$parameters->sort = $sort;
		$parameters->dir = $dir;

		$data = array($parameters);

		/** @noinspection PhpUndefinedFieldInspection */

		return $this->_routerRequest('DeviceRouter', 'getDevices', $data)->devices;
	}


	/**
	 * @param        $name
	 * @param int    $limit
	 * @param string $sort
	 * @param string $dir
	 *
	 * @return mixed
	 */
	public function getDevicesByName($name, $limit = 50, $sort = 'name', $dir = 'ASC')
	{
		return $this->getDevices(null, 0, array('name' => $name), $limit, $sort, $dir);
	}


	/**
	 * @param        $deviceClass
	 * @param int    $limit
	 * @param string $sort
	 * @param string $dir
	 *
	 * @return mixed
	 */
	public function getDevicesByDeviceClass($deviceClass, $limit = 50, $sort = 'name', $dir = 'ASC')
	{
		return $this->getDevices(null, 0, array('deviceClass' => $deviceClass), $limit, $sort, $dir);
	}


	/**
	 * @param        $group
	 * @param int    $limit
	 * @param string $sort
	 * @param string $dir
	 *
	 * @return mixed
	 */
	public function getDevicesByGroup($group, $limit = 50, $sort = 'name', $dir = 'ASC')
	{
		return $this->getDevices('/zport/dmd/Groups/' . $group, 0, $params = array(), $limit, $sort, $dir);
	}


	/**
	 * @param        $productionState
	 * @param int    $limit
	 * @param string $sort
	 * @param string $dir
	 *
	 * @return mixed|null
	 * @throws \Exception
	 */
	public function getDevicesByProductionState($productionState, $limit = 50, $sort = 'name', $dir = 'ASC')
	{
		/* TO-DO: Handle multiple production states */

		$productionStates = $this->getProductionStates();

		if (array_key_exists($productionState, $productionStates)) {
			// productionState should be an array of states
			return $this->getDevices(
				null, 0, array('productionState' => array($productionStates[$productionState])), $limit, $sort, $dir
			);
		} else {
			try {
				throw new \Exception("Unknown production state: $productionState");
			} catch (\Exception $e) {
				$this->_log('crit', $e);
			}
		}

		return null;
	}


	/**
	 * @param $uid
	 *
	 * @return mixed
	 */
	public function getGraphDefs($uid)
	{
		$parameters = new \stdClass();
		$parameters->uid = $uid;

		$data = array($parameters);

		/** @noinspection PhpUndefinedFieldInspection */

		return $this->_routerRequest('DeviceRouter', 'getGraphDefs', $data)->data;
	}


	/**
	 * @param int    $limit
	 * @param int    $start
	 * @param string $sort
	 * @param string $dir
	 * @param array  $params
	 * @param bool   $history
	 * @param null   $uid
	 *
	 * @return mixed
	 */
	public function getEvents(
		$limit = 0, $start = 0, $sort = 'lastTime', $dir = 'DESC', $params = array(), $history = false, $uid = null
	) {
		$parameters = new \stdClass();
		$parameters->limit = $limit;
		$parameters->start = $start;
		$parameters->sort = $sort;
		$parameters->dir = $dir;
		$parameters->params = (object)$params;
		$parameters->history = $history;
		$parameters->uid = $uid;

		$data = array($parameters);

		/** @noinspection PhpUndefinedFieldInspection */
		return $this->_routerRequest('EventsRouter', 'query', $data)->events;
	}


	/**
	 * @param string $summary		New event's summary
	 * @param string $device		Device uid to use for new event
	 * @param string $component		Component uid to use for new event
	 * @param string $severity		Severity of new event. Can be one of the following: Critical, Error, Warning, Info, Debug, Clean
	 * @param string $evclasskey	The Event Class Key to assign to this event
	 * @param string $evclass		Event class for the new event
	 *
	 * @return string
	 */
	public function addEvent($summary, $device, $severity, $component = '', $evclasskey = '', $evclass = '')
	{
		$parameters = new \stdClass();
		$parameters->summary = $summary;
		$parameters->device = $device;
		$parameters->component = $component;
		$parameters->severity = $severity;
		$parameters->evclasskey = $evclasskey;
		$parameters->evclass = $evclass;

		$data = array($parameters);

		return $this->_routerRequest('EventsRouter', 'add_event', $data);
	}

	/**
	 * @param array $evids
	 * @param array $params
	 * @param null  $excludeIds
	 * @param null  $uid
	 * @param null  $asof
	 *
	 * @return string
	 */
	public function closeEvents($evids = array(), $params = array(), $excludeIds = null, $uid = null, $asof = null)
	{
		$parameters = new \stdClass();
		$parameters->evids = $evids;
		$parameters->params = (object)$params;
		$parameters->excludeIds = $excludeIds;
		$parameters->uid = $uid;
		$parameters->asof = $asof;

		$data = array($parameters);

		return $this->_routerRequest('EventsRouter', 'close', $data);
	}


	/**
	 * @return array
	 */
	public function getProductionStates()
	{
		$productionStates = array();

		/** @noinspection PhpUndefinedFieldInspection */
		$results = $this->_routerRequest('DeviceRouter', 'getProductionStates')->data;

		foreach ($results as $result) {
			$productionStates[$result->name] = $result->value;
		}

		return $productionStates;
	}


	/**
	 * @return mixed
	 */
	public function getCollectors()
	{
		return $this->_routerRequest('DeviceRouter', 'getCollectors');
	}


	/**
	 * @return array
	 */
	public function getDeviceClasses()
	{
		$deviceClasses = array();

		/** @noinspection PhpUndefinedFieldInspection */
		$results = $this->_routerRequest('DeviceRouter', 'getDeviceClasses')->deviceClasses;

		foreach ($results as $result) {
			$deviceClasses[] = $result->name;
		}

		return $deviceClasses;
	}


	/**
	 * @return mixed
	 */
	public function getSystems()
	{
		/** @noinspection PhpUndefinedFieldInspection */
		return $this->_routerRequest('DeviceRouter', 'getSystems')->systems;
	}


	/**
	 * @return mixed
	 */
	public function getGroups()
	{
		/** @noinspection PhpUndefinedFieldInspection */
		return $this->_routerRequest('DeviceRouter', 'getGroups')->groups;
	}


	/**
	 * @return mixed
	 */
	public function getLocations()
	{
		/** @noinspection PhpUndefinedFieldInspection */
		return $this->_routerRequest('DeviceRouter', 'getLocations')->locations;
	}


	/**
	 * @param int|string $prodState
	 * @param array      $uids
	 * @param int        $hashcheck
	 *
	 * @return mixed
	 */
	public function setProductionState($prodState, $uids = array(), $hashcheck = 1)
	{
		$productionStates = $this->getProductionStates();

		if (is_string($prodState)) {
			$prodState = $productionStates[$prodState];
		}

		$parameters = new \stdClass();
		$parameters->uids = $uids;
		$parameters->prodState = $prodState;
		$parameters->hashcheck = $hashcheck;

		$data = array($parameters);

		return $this->_routerRequest('DeviceRouter', 'setProductionState', $data);
	}


	/*
	 * gopts
	 */

	/**
	 * @static
	 *
	 * @param $gopts
	 *
	 * @return string
	 */
	public static function decodeGopts($gopts)
	{
		$sbz = str_replace('_', '/', str_replace('-', '+', $gopts));
		$sb = base64_decode($sbz);
		$s = gzuncompress($sb);
		$s = join("\n", explode('|', $s));

		return $s;
	}


	/**
	 * @static
	 *
	 * @param $ungopts
	 *
	 * @return mixed
	 */
	public static function encodeUngopts($ungopts)
	{
		$s = join('|', preg_split("/\r\n|\n/", $ungopts));
		$sz = gzcompress($s, 9);
		$szb = base64_encode($sz);
		$szb_safe = str_replace('+', '-', str_replace('/', '_', $szb));

		# strip newlines (created by b64 encode)
		$gopts = str_replace("\n", '', $szb_safe);

		return $gopts;
	}


	/* trees
	 *
	 * Adapted from http://kevin.vanzonneveld.net/techblog/article/convert_anything_to_tree_structures_in_php/
	 *
	 */

	/* build a single-dimensional array from a Zenoss tree */

	/**
	 * @static
	 *
	 * @param $tree
	 * @param $clean_tree
	 * @param $root
	 */
	public static function cleanGroupsTree($tree, &$clean_tree, $root)
	{
		$root = str_replace('/', '.', $root);

		foreach ($tree as $branch) {
			foreach ($branch as $key => $value) {
				if ($key == 'id') {
					$clean_tree[str_replace(".zport.dmd.Groups.$root.", '', $value)] = str_replace(
						'.zport.dmd.Groups.', '', $value
					);
				}

				if ($key == 'children' && is_array($value) && count($value) > 0) {
					self::cleanGroupsTree($value, $clean_tree, $root);
				}
			}
		}
	}


	/**
	 * @static
	 *
	 * @param        $array
	 * @param string $delimiter
	 * @param bool   $baseval
	 *
	 * @return array|bool
	 */
	public static function explodeTree($array, $delimiter = '_', $baseval = false)
	{
		if (!is_array($array)) {
			return false;
		}
		$splitRE = '/' . preg_quote($delimiter, '/') . '/';
		$returnArr = array();

		foreach ($array as $key => $val) {
			// Get parent parts and the current leaf
			$parts = preg_split($splitRE, $key, -1, PREG_SPLIT_NO_EMPTY);
			$leafPart = array_pop($parts);

			// Build parent structure
			// Might be slow for really deep and large structures
			$parentArr = & $returnArr;

			foreach ($parts as $part) {
				if (!isset($parentArr[$part])) {
					$parentArr[$part] = array();
				} elseif (!is_array($parentArr[$part])) {
					if ($baseval) {
						$parentArr[$part] = array('__base_val' => $parentArr[$part]);
					} else {
						$parentArr[$part] = array();
					}
				}
				$parentArr = & $parentArr[$part];
			}

			// Add the final part to the structure
			if (empty($parentArr[$leafPart])) {
				$parentArr[$leafPart] = $val;
			} elseif ($baseval && is_array($parentArr[$leafPart])) {
				$parentArr[$leafPart]['__base_val'] = $val;
			}
		}

		return $returnArr;
	}


	/**
	 * @static
	 *
	 * @param     $arr
	 * @param int $indent
	 */
	public static function makeOptionList($arr, $indent = 0)
	{
		foreach ($arr as $k => $v) {
			// skip the baseval thingy. Not a real node.
			if ($k == "__base_val") {
				continue;
			}

			// determine the real value of this node.
			$show_val = (is_array($v) ? $v["__base_val"] : $v);

			// show the actual node
			//<!-- <option value="NOC.Development">Development</option> -->
			echo "<option value=\"$show_val\">";
			echo ($indent > 0) ? str_repeat("-", $indent) . " $k" : "$k";
			echo "</option>\n";

			if (is_array($v)) {
				// this is what makes it recursive, rerun for childs
				self::makeOptionList($v, $indent + 1);
			}
		}
	}


	/**
	 *
	 * @param string $priority
	 * @param string $message
	 */
	protected function _log($priority, $message)
	{
		if ($this->_logger != null) {
			$class = str_replace(__NAMESPACE__ . "\\", '', get_called_class());
			$this->_logger->$priority("[$class] - $message");
		}
	}
}