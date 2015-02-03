<?php

/**
 * Log wrapper to the official \Consolari\Logger and helper for each data type.
 *
 * Make it faster to log custom data
 */
class ConsolariHelper {
	/**
	 * Hold the logger instance
	 * @var \Consolari\Logger
	 */
	private $logger;

	/**
	 * Used for the log marker
	 * @var mixed
	 */
	private $startLogTime;

	/**
	 * Holds all the custom markers
	 * @var array
	 */
	private $marker = array();

	/**
	 * Holds the credentials
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Hold an instance of the class
	 *
	 * @var
	 */
	private static $instance;

	/**
	 * Enable logging
	 *
	 * @var bool
	 */
	private $logData = false;

	/**
	 * The singleton method
	 *
	 * @return mixed
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			$c              = __CLASS__;
			self::$instance = new $c;
		}

		return self::$instance;
	}

	/**
	 * Enable the logger
	 */
	public static function enableInsights() {
		$logger = self::instance()->logger;

		if ( ! empty( $logger ) ) {
			$logger->logData = true;
		}
	}

	/**
	 * Prevent users to clone the instance
	 */
	public function __clone() {
		trigger_error( 'Clone is not allowed.', E_USER_ERROR );
	}

	/**
	 * Set user credential
	 *
	 * @param $user
	 */
	public static function setUser( $user ) {
		$obj                  = self::instance();
		$obj->options['user'] = $user;
	}

	/**
	 * Set key credential
	 *
	 * @param $key
	 */
	public static function setKey( $key ) {
		$obj                 = self::instance();
		$obj->options['key'] = $key;
	}

	/**
	 * Private constructor called from instance to create singleton
	 */
	private function __construct() {
		/*
		* Require PHP5.3 because of namespaces
		*/
		$version = phpversion();

		$phpOk = version_compare( $version, 5.3, '>=' );

		if ( ! $phpOk ) {
			$this->logData = false;
		} else {

			require __DIR__ . '/../vendor/autoload.php';

			/*
			* Initiate session
			*/
			$this->logger = new \Consolari\Logger();
			$this->logger->setSource( $_SERVER['HTTP_HOST'] );
			$this->logger->setLevel( 'message' );
			$this->logger->setUrl( $_SERVER['REQUEST_URI'] );

			$this->startLogTime = microtime( true );
		}
	}

	/**
	 * Send data to Consolari
	 *
	 * @throws Exception
	 */
	public function __destruct() {
		if ( ! self::isEnabled() ) {
			return;
		}

		$this->log( 'server', ! empty( $_SESSION ) ? $_SESSION : array(), 'SESSION' );
		$this->log( 'server', ! empty( $_POST ) ? $_POST : 'no post', 'POST' );
		$this->log( 'server', ! empty( $_GET ) ? $_GET : 'no get', 'GET' );
		$this->log( 'server', ! empty( $_REQUEST ) ? $_REQUEST : 'no request', 'REQUEST' );
		$this->log( 'server', ! empty( $_FILES ) ? $_FILES : 'no files', 'FILES' );
		$this->log( 'server', $_COOKIE, 'COOKIE' );
		$this->log( 'server', $_SERVER, 'SERVER', 'array' );
		$this->log( 'server', self::instance()->marker, 'MARKER', 'table' );

		$transport = new Consolari\Transport\Curl();

		$this->logger->setKey( $this->options['key'] );
		$this->logger->setUser( $this->options['user'] );
		$this->logger->setTransport( $transport );
		$this->logger->send();
	}

	/**
	 * Check to see if we should log and send data
	 *
	 * @return bool
	 */
	public static function isEnabled() {
		$obj = self::instance();

		$active = false;

		if ( ! empty( $obj ) and ! empty( $obj->options['key'] ) and ! empty( $obj->logger ) ) {
			$active = true;
		}

		return $obj->logData or $active;
	}

	/**
	 * Log wrapper
	 *
	 * @param string $groupName
	 * @param string $data
	 * @param string $label
	 * @param string $dataType
	 *
	 * @throws Exception
	 */
	public static function log( $groupName = '', $data = '', $label = 'Data', $dataType = 'none' ) {
		if ( ! self::isEnabled() ) {
			return;
		}

		$logger = self::instance()->logger;

		self::logMarker( $groupName . '->' . $label );

		$trace = debug_backtrace();

		$contextData = self::getContext( $trace );
		unset( $trace );

		$context = new \Consolari\Context\Context();
		$context->setFile( $contextData['file'] );
		$context->setClass( $contextData['class'] );
		$context->setMethod( $contextData['method'] );
		$context->setLine( $contextData['line'] );
		$context->setCode( $contextData['code'] );
		$context->setLanguage( $contextData['language'] );
		unset( $contextData );

		if ( $dataType == 'none' ) {
			if ( is_array( $data ) ) {
				$dataType = 'array';
			} else {
				$dataType = 'string';
			}
		}

		if ( $dataType == 'array' and ! is_array( $data ) ) {
			$dataType = 'string';
		}

		switch ( $dataType ) {
			default:
				throw new Exception( 'Unknown datatype ' . $dataType );
				break;
			case 'array':
				$entry = new Consolari\Entries\ArrayEntry();

				if ( is_array( $data ) ) {
					$entry->setValue( $data );
				}
				break;
			case 'url':
			case 'string':
				$entry = new Consolari\Entries\String();
				$entry->setValue( $data );
				break;
			case 'html':
			case 'xml':
				$entry = new Consolari\Entries\String();
				$entry->setValue( $data );
				$entry->setContentType( Consolari\Entries\EntryContentType::XML );
				break;
			case 'json':
				$entry = new Consolari\Entries\String();
				$entry->setValue( $data );
				$entry->setContentType( Consolari\Entries\EntryContentType::JSON );
				break;
			case 'table':
				$entry = new Consolari\Entries\Table();
				$entry->setValue( $data );
				break;
		}

		$entry->setGroupName( $groupName );
		$entry->setLabel( $label );
		$entry->setContext( $context );

		$logger->addEntry( $entry );
	}

	/**
	 * Get contect of log requestor
	 *
	 * @param array $trace
	 */
	public static function getContext( $trace, $level = 0 ) {
		$contextLines  = 8;
		$ignoreClasses = array( 'ConsolariDatabase', 'wpdb' );

		$context = array(
			'file'   => '',
			'line'   => 0,
			'class'  => '',
			'method' => '',
		);

		for ( $i = $level; $i < $level + 10; $i ++ ) {
			if ( isset( $trace[ $i + 1 ]['class'] ) and ! in_array( $trace[ $i + 1 ]['class'], $ignoreClasses ) ) {
				$context = array(
					'file'   => $trace[ $i ]['file'],
					'line'   => $trace[ $i ]['line'],
					'class'  => $trace[ $i + 1 ]['class'],
					'method' => $trace[ $i + 1 ]['function'],
				);

				break;
			}
		}

		if ( file_exists( $context['file'] ) ) {
			$code = file( $context['file'] );
		} else {
			$code = array();
		}

		$codeStr = '';
		for ( $i = $context['line'] - $contextLines; $i < $context['line'] + $contextLines; $i ++ ) {

			if ( isset( $code[ $i ] ) ) {
				$codeStr .= $code[ $i ];
			}
		}

		$context['code']     = $codeStr;
		$context['language'] = 'php';

		return $context;
	}

	/**
	 * Log a SQL query with its result set
	 *
	 * @param string $sql
	 * @param null $rows
	 * @param int $results
	 */
	public static function logSQL( $sql = '', $rows = null, $results = 0 ) {
		if ( ! self::isEnabled() ) {
			return;
		}

		$logger = self::instance()->logger;

		self::logMarker( 'SQL->SQL' );

		$trace = debug_backtrace();

		$contextData = self::getContext( $trace, 0 );
		unset( $trace );

		$context = new \Consolari\Context\Context();
		$context->setFile( $contextData['file'] );
		$context->setClass( $contextData['class'] );
		$context->setMethod( $contextData['method'] );
		$context->setLine( $contextData['line'] );
		$context->setCode( $contextData['code'] );
		$context->setLanguage( $contextData['language'] );

		$entry = new Consolari\Entries\Query();
		$entry->setSql( $sql );
		$entry->setGroupName( 'SQL' );
		$entry->setLabel( 'SQL' );
		$entry->setContext( $context );

		if ( ! empty( $rows ) ) {
			$entry->setRows( $rows );
		}

		$logger->addEntry( $entry );

		return;
	}

	/**
	 * Log wrapper to support data type request. It will log the body request and response
	 *
	 * @param $group
	 * @param $action
	 * @param $wsdl
	 * @param $params
	 * @param $requestBody
	 * @param $requestHeaders
	 * @param $responseBody
	 * @param $responseHeaders
	 * @param $type
	 */
	public static function logRequest( $group, $action, $wsdl, $params, $requestBody, $requestHeaders, $responseBody, $responseHeaders, $type ) {
		if ( ! self::isEnabled() ) {
			return;
		}

		$logger = self::instance()->logger;

		self::logMarker( $group . '->' . $action );

		if ( ! empty( $logger ) ) {
			$entry = new Consolari\Entries\Request();
			$entry->setGroupName( $group );
			$entry->setLabel( $action );
			$entry->setUrl( $wsdl );
			$entry->setParams( $params );
			$entry->setRequestBody( $requestBody );
			$entry->setRequestHeader( $requestHeaders );
			$entry->setRequestType( $type );
			$entry->setResponseBody( $responseBody );
			$entry->setResponseHeader( $responseHeaders );

			$logger->addEntry( $entry );
		}
	}

	/**
	 * Log wrapper to support a SOAP request
	 *
	 * @param string $action
	 * @param SoapClient $client
	 * @param string $wsdl
	 */
	public static function logSoapObj( $action = '', $client, $wsdl = '' ) {
		$params = '';
		$group  = $action;

		self::logRequest( $group, $action, $wsdl, $params, $client->__getLastRequest(), $client->__getLastRequestHeaders(), $client->__getLastResponse(), $client->__getLastResponseHeaders(), 'POST' );
	}

	/**
	 * Log marker to get deeper insight of performance. It build a simple table with name, execution time and memoery usage at that point in time.
	 * Its not a special data type in consolari but a home brewed table.
	 *
	 * @param string $name
	 */
	public static function logMarker( $name = '' ) {
		if ( ! self::isEnabled() ) {
			return;
		}

		if ( function_exists( 'memory_get_usage' ) ) {
			$memory = round( memory_get_usage() / 1024, 1 ) . 'KB';
		} else {
			$memory = 'N/A';
		}

		self::instance()->marker[] = array(
			'name'   => $name,
			'time'   => round( ( microtime( true ) - self::instance()->startLogTime ), 4 ),
			'memory' => $memory,
		);
	}
}