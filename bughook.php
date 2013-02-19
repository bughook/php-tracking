<?php
/**
 * BugHook PHP error tracker
 *
 * Supports PHP 5.2+
 * *
 * @package     BugHook
 * @version     1.0.0
 * @author      Alexander Adam <info@bughook.com>
 * @copyright   (c) 2013 Bughook
 * @license     MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class BugHook {
	// "Constants"
	private static $NOTIFIER = array(
		'name'      => 'BugHook PHP (Official)',
		'version'   => '1.0.0',
		'url'       => ''
	);

	private static $FATAL_ERRORS = array(
		E_ERROR,
		E_PARSE,
		E_CORE_ERROR,
		E_CORE_WARNING,
		E_COMPILE_ERROR,
		E_COMPILE_WARNING,
		E_STRICT
	);

	// Configuration state
	private static $apiKey;
	private static $releaseStage = 'production';
	private static $notifyReleaseStages = array('production');
	private static $useSSL = false;
	private static $projectRoot;
	private static $filters = array('password','PHPSESSID','Cookie');
	private static $endpoint = '';
	private static $context;
	private static $userId;
	private static $metaDataFunction;
	private static $errorReportingLevel;
	private static $ignoreReportingLevel = false;

	private static $registeredShutdown = false;
	private static $projectRootRegex;
	private static $errorQueue = array();
	private static $metaData = null;


	/**
	 * Initialize BugHook
	 *
	 * @param String $apiKey your BugHook API key
	 */
	public static function register($apiKey) {
		self::$apiKey = $apiKey;

		// Attempt to determine a sensible default for projectRoot
		if(isset($_SERVER) && !empty($_SERVER['DOCUMENT_ROOT']) && !isset(self::$projectRoot)) {
			self::setProjectRoot($_SERVER['DOCUMENT_ROOT']);
		}

		// Register a shutdown function to check for fatal errors
		if(!self::$registeredShutdown) {
			register_shutdown_function('BugHook::fatalErrorHandler');
			self::$registeredShutdown = true;
		}
	}

	/**
	 * Set your release stage, eg "production" or "development"
	 *
	 * @param String $releaseStage the app's current release stage
	 */
	public static function setReleaseStage($releaseStage) {
		self::$releaseStage = $releaseStage;
	}

	/**
	 * Set which release stages should be allowed to notify BugHook
	 * eg array("production", "development")
	 *
	 * @param Array $notifyReleaseStages array of release stages to notify for
	 */
	public static function setNotifyReleaseStages($notifyReleaseStages) {
		self::$notifyReleaseStages = $notifyReleaseStages;
	}

	/**
	 * Set whether or not to use SSL when notifying BugHook
	 *
	 * @param Boolean $useSSL whether to use SSL
	 */
	public static function setUseSSL($useSSL) {
		self::$useSSL = $useSSL;
	}

	/**
	 * Set the absolute path to the root of your application.
	 * We use this to help with error grouping and to highlight "in project"
	 * stacktrace lines.
	 *
	 * @param String $projectRoot the root path for your application
	 */
	public static function setProjectRoot($projectRoot) {
		self::$projectRoot = $projectRoot;
		self::$projectRootRegex = '/'.preg_quote($projectRoot, '/')."[\\/]?/i";
	}

	/**
	 * Set the strings to filter out from metaData arrays before sending then
	 * to BugHook. Eg. array("password", "credit_card")
	 *
	 * @param Array $filters an array of metaData filters
	 */
	public static function setFilters($filters) {
		self::$filters = $filters;
	}

	/**
	 * Set the unique userId representing the current request.
	 *
	 * @param String $userId the current user id
	 */
	public static function setUserId($userId) {
		self::$userId = $userId;
	}

	/**
	 * Set a context representing the current type of request, or location in code.
	 *
	 * @param String $context the current context
	 */
	public static function setContext($context) {
		self::$context = $context;
	}

	/**
	 * Set a custom metadata generation function to call before notifying
	 * BugHook of an error. You can use this to add custom tabs of data
	 * to each error on your BugHook dashboard.
	 *
	 * @param Callback $metaDataFunction a function that should return an
	 *        array of arrays of custom data. Eg:
	 *        array(
	 *            "user" => array(
	 *                "name" => "James",
	 *                "email" => "james@example.com"
	 *            )
	 *        )
	 */
	public static function setMetaDataFunction($metaDataFunction) {
		self::$metaDataFunction = $metaDataFunction;
	}

	/**
	 * Set BugHook's error reporting level.
	 * If this is not set, we'll use your current PHP error_reporting value
	 * from your ini file or error_reporting(...) calls.
	 *
	 * @param Integer $errorReportingLevel the error reporting level integer
	 *                exactly as you would pass to PHP's error_reporting
	 */
	public static function setErrorReportingLevel($errorReportingLevel) {
		self::$errorReportingLevel = $errorReportingLevel;
	}

	/**
	 * BugHook will ignore error reporting level and log all events
	 *
	 * @param boolean $ignoreReportingLevel
	 */
	public static function setIgnoreReportingLevel($ignoreReportingLevel) {
		self::$ignoreReportingLevel = $ignoreReportingLevel;
	}

	/**
	 * Notify BugHook of a non-fatal/handled exception
	 *
	 * @param Exception $exception the exception to notify BugHook about
	 * @param Array $metaData optional metaData to send with this error
	 */
	public static function notifyException($exception, $metaData=null) {
		// Build a sensible stacktrace
		$stacktrace = self::buildStacktrace($exception->getFile(), $exception->getLine(), $exception->getTrace());

		// Send the notification to BugHook
		self::notify(get_class($exception), $exception->getMessage(), $stacktrace, $metaData);
	}

	/**
	 * Notify BugHook of a non-fatal/handled error
	 *
	 * @param String $errorName the name of the error, a short (1 word) string
	 * @param String $errorMessage the error message
	 * @param Array $metaData optional metaData to send with this error
	 */
	public static function notifyError($errorName, $errorMessage, $metaData=null) {
		// Get the stack, remove the current function, build a sensible stacktrace]
		$backtrace = debug_backtrace();
		$firstFrame = array_shift($backtrace);
		$stacktrace = self::buildStacktrace($firstFrame["file"], $firstFrame["line"], $backtrace);

		// Send the notification to BugHook
		self::notify($errorName, $errorMessage, $stacktrace, $metaData);
	}



	// Exception handler callback, should only be called internally by PHP's set_exception_handler
	public static function exceptionHandler($exception) {
		self::notifyException($exception);

		// call original exception handler
		global $old_exception_handler;
		if(!empty($old_exception_handler) && function_exists($old_exception_handler)) {
			call_user_func($old_exception_handler, $exception);
		}

		// return false to have PHP logging it
		return false;
	}

	// Exception handler callback, should only be called internally by PHP's set_error_handler
	public static function errorHandler($errno, $errstr, $errfile='', $errline=0, $errcontext=array()) {
		// Check if we should notify BugHook about errors of this type
		if(!self::shouldNotify($errno)) {
			return;
		}

		// Get the stack, remove the current function, build a sensible stacktrace]
		// TODO: Add a method to remove any user's set_error_handler functions from this stacktrace
		$backtrace = debug_backtrace();
		array_shift($backtrace);
		$stacktrace = self::buildStacktrace($errfile, $errline, $backtrace);

		// Send the notification to BugHook
		self::notify($errno, $errstr, $stacktrace);
		
		// call original error handler
		global $old_error_handler;
		if(!empty($old_error_handler) && function_exists($old_error_handler)) {
			call_user_func($old_error_handler, $errno, $errstr, $errfile, $errline, $errcontext);
		}

		// return false to have PHP logging it
		return false;
	}

	// Shutdown handler callback, should only be called internally by PHP's register_shutdown_function
	public static function fatalErrorHandler() {
		// Get last error
		$lastError = error_get_last();

		// Check if a fatal error caused this shutdown
		if(!is_null($lastError) && in_array($lastError['type'], self::$FATAL_ERRORS)) {
			// NOTE: We can't get the error's backtrace here :(
			$stacktrace = self::buildStacktrace($lastError['file'], $lastError['line']);

			// Send the notification to BugHook
			self::notify($lastError['type'], $lastError['message'], $stacktrace);
		}

		// Check if we should flush errors
		if(self::sendErrorsOnShutdown()) {
			self::flushErrorQueue();
		}
	}



	// Private methods
	private static function notify($errorName, $errorMessage, $stacktrace=null, $passedMetaData=null) {
		// Check if we should notify
		if(is_array(self::$notifyReleaseStages) && !in_array(self::$releaseStage, self::$notifyReleaseStages)) {
			return;
		}

		// Check we have at least an api_key
		if(!isset(self::$apiKey)) {
			error_log('BugHook Warning: No API key configured, couldn\'t notify');
			return;
		}

		// Add metadata
		if(is_null(self::$metaData)) {
			self::$metaData = self::getMetaData($passedMetaData);
		}

		// Build the error payload to send to BugHook
		$error = array(
			'userId' => self::getUserId(),
			'releaseStage' => self::$releaseStage,
			'context' => self::getContext(),
			'exceptions' => array(array(
				'errorClass' => $errorName,
				'message' => $errorMessage,
				'stacktrace' => $stacktrace
			))
		);

		// Add this error payload to the send queue
		self::$errorQueue[] = $error;

		// Flush the queue immediately unless we are batching errors
		if(!self::sendErrorsOnShutdown()) {
			self::flushErrorQueue();
		}
	}

	private static function sendErrorsOnShutdown() {
		return self::isRequest();
	}

	private static function flushErrorQueue() {
		if(!empty(self::$errorQueue)) {
			// Post the request to BugHook
			self::postJSON(self::getEndpoint(), array(
				'apiKey' => self::$apiKey,
				'notifier' => self::$NOTIFIER,
				'events' => self::$errorQueue,
				'metaData' => self::$metaData
			));

			// Clear the error queue
			self::$errorQueue = array();
		}
	}

	private static function buildStacktrace($topFile, $topLine, $backtrace=null) {
		$stacktrace = array();

		if(!is_null($backtrace)) {
			// PHP backtrace's are misaligned, we need to shift the file/line down a frame
			foreach ($backtrace as $line) {
				$stacktrace[] = self::buildStacktraceFrame($topFile, $topLine, $line['function']);

				if(isset($line['file']) && isset($line['line'])) {
					$topFile = $line['file'];
					$topLine = $line['line'];
				} else {
					$topFile = "[internal]";
					$topLine = 0;
				}
			}

			// Add a final stackframe for the "main" method
			$stacktrace[] = self::buildStacktraceFrame($topFile, $topLine, '[main]');
		} else {
			// No backtrace given, show what we know
			$stacktrace[] = self::buildStacktraceFrame($topFile, $topLine, '[unknown]');
		}

		return $stacktrace;
	}

	private static function buildStacktraceFrame($file, $line, $method) {
		// Check if this frame is inProject
		$inProject = !is_null(self::$projectRoot) && preg_match(self::$projectRootRegex, $file);

		// Strip out projectRoot from start of file path
		if($inProject) {
			$file = preg_replace(self::$projectRootRegex, '', $file);
		}

		// Construct and return the frame
		return array(
			'file' => $file,
			'lineNumber' => $line,
			'method' => $method,
			'inProject' => $inProject
		);
	}

	/**
	 * @param $url
	 * @param $data
	 */
	private static function postJSON($url, $data) {
		$post_string = 'data='.urlencode(json_encode($data));

		$parts=parse_url($url);

		$fp = fsockopen($parts['host'],
			isset($parts['port'])?$parts['port']:80,
			$errno, $errstr, 30);

		$out = "POST ".$parts['path']." HTTP/1.1\r\n";
		$out.= "Host: ".$parts['host']."\r\n";
		$out.= "Content-Type: application/x-www-form-urlencoded\r\n";
		$out.= "Content-Length: ".strlen($post_string)."\r\n";
		$out.= "Connection: Close\r\n\r\n";
		if (isset($post_string)) $out.= $post_string;

		fwrite($fp, $out);
		fclose($fp);
	}

	private static function getEndpoint() {
		return self::$useSSL ? 'https://'.self::$endpoint : 'http://'.self::$endpoint;
	}

	private static function isRequest() {
		return isset($_SERVER['REQUEST_METHOD']);
	}

	private static function getMetaData($passedMetaData=array()) {
		$metaData = array();

		// Add http request info
		if(self::isRequest()) {
			$metaData = array_merge_recursive($metaData, self::getRequestData());
		}

		// Add environment info
		if(!empty($_ENV)) {
			$metaData['environment'] = $_ENV;
		}

		// Merge user-defined metadata if custom function is specified
		if(isset(self::$metaDataFunction) && is_callable(self::$metaDataFunction)) {
			$customMetaData = call_user_func(self::$metaDataFunction);
			if(!is_null($customMetaData) && is_array($customMetaData)) {
				$metaData = array_merge_recursive($metaData, $customMetaData);
			}
		}

		// Merge $passedMetaData
		if(!empty($passedMetaData)) {
			$metaData = array_merge_recursive($metaData, $passedMetaData);
		}

		// Filter metaData according to self::$filters
		$metaData = self::applyFilters($metaData);

		return $metaData;
	}

	private static function getRequestData() {
		$requestData = array();

		// Request Tab
		$requestData['request'] = array();
		$requestData['request']['url'] = self::getCurrentUrl();
		if(isset($_SERVER['REQUEST_METHOD'])) {
			$requestData['request']['httpMethod'] = $_SERVER['REQUEST_METHOD'];
		}

		if(!empty($_POST)) {
			$requestData['request']['params'] = $_POST;
		} else {
			if(isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') === 0) {
				$requestData['request']['params'] = json_decode(file_get_contents('php://input'));
			}
		}

		$requestData['request']['ip'] = self::getRequestIp();
		if(isset($_SERVER['HTTP_USER_AGENT'])) {
			$requestData['request']['userAgent'] = $_SERVER['HTTP_USER_AGENT'];
		}

		if(function_exists("getallheaders")) {
			$headers = getallheaders();
			if(!empty($headers)) {
				$requestData['request']['headers'] = $headers;
			}
		}

		// Session Tab
		if(!empty($_SESSION)) {
			$requestData['session'] = $_SESSION;
		}

		// Cookies Tab
		if(!empty($_COOKIE)) {
			$requestData['cookies'] = $_COOKIE;
		}

		return $requestData;
	}

	private static function getCurrentUrl() {
		$schema = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';

		return $schema.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
	}

	private static function getRequestIp() {
		if(isset($_SERVER['X-Forwarded-For'])) {
			return $_SERVER['X-Forwarded-For'];
		} else if(isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			return $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else {
			return $_SERVER['REMOTE_ADDR'];
		}
	}

	private static function getContext() {
		if(self::$context) {
			return self::$context;
		} elseif(self::isRequest() && isset($_SERVER['REQUEST_METHOD']) && isset($_SERVER["REQUEST_URI"])) {
			return $_SERVER['REQUEST_METHOD'] . ' ' . strtok($_SERVER["REQUEST_URI"], '?');
		} else {
			return null;
		}
	}

	private static function getUserId() {
		if(self::$userId) {
			return self::$userId;
		} elseif(self::isRequest()) {
			return self::getRequestIp();
		} else {
			return null;
		}
	}

	private static function applyFilters($metaData) {
		if(!empty(self::$filters)) {
			$cleanMetaData = array();

			foreach ($metaData as $key => $value) {
				$shouldFilter = false;
				foreach(self::$filters as $filter) {
					if(strpos($key, $filter) !== false) {
						$shouldFilter = true;
						break;
					}
				}

				if($shouldFilter) {
					$cleanMetaData[$key] = '[FILTERED]';
				} else {
					if(is_array($value)) {
						$cleanMetaData[$key] = self::applyFilters($value);
					} else {
						$cleanMetaData[$key] = $value;
					}
				}
			}

			return $cleanMetaData;

		} else {
			return $metaData;
		}
	}

	private static function shouldNotify($errno) {
		if(self::$ignoreReportingLevel)  {
			return true;
		} else if(isset(self::$errorReportingLevel)) {
			return self::$errorReportingLevel & $errno;
		} else {
			return error_reporting() & $errno;
		}
	}
}