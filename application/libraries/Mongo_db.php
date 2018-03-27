<?php if (!defined('BASEPATH')) exit('No direct script access allowed');
/**
 *  ------------------------------------------------------------------------
 *  
 *  Open Source CodeIgniter MongoDB Library (ALL-IN-ONE).
 *
 *  This library is based on a brand new PHP 7 to work with CodeIgniter 
 *  since version 3 and MongoDB since version 3.2. I have implemented the 
 *  following main functions MongoDB:  select, insert, update, delete and 
 *  aggregate documents; execute commands.
 *  
 *  @author   Dmitriy Verkhoumov <verkhoumov@yandex.ru> <https://github.com/verkhoumov>
 *  @since    14.10.2016
 *  @license  MIT License
 *  
 *  @version  1.1.3
 *  @link     https://github.com/verkhoumov/codeigniter-mongodb-library
 *
 *  ------------------------------------------------------------------------
 *
 *  NOTE: I express my gratitude to Alexander (@link https://github.com/Alexter-progs) for the translation into English.
 *
 *  ------------------------------------------------------------------------
 */

# TODO #1: Get document in format gotten from database, without convertation to object or array.
# TODO #2: updateWhere, updateAllWhere.
# TODO #3: deleteWhere, deleteAllWhere.
# TODO #4: switch_db by config_group.

/**
 *  MongoDB namespaces.
 */
use function MongoDB\BSON\{toJSON, fromPHP};

use MongoDB\BSON\{Regex, UTCDatetime, Timestamp, ObjectID, Javascript};
use MongoDB\Driver\{Query, Manager, Command, BulkWrite, Cursor, WriteConcern, WriteResult, ReadPreference, ReadConcern};
use MongoDB\Driver\Exception\{Exception, ConnectionException, InvalidArgumentException};

Class Mongo_db
{
	/**
	 *  CodeIgniter handler.
	 */
	private $CodeIgniter;

	/**
	 *  Default config parameters.
	 *
	 *  During library initialization and reconnection arguments
	 *  recursively replaced with passed to method accordingly to priority type:
	 *  
	 *  1) High priority - data passed during [__construct()], to methods [connect()] and [reconnect()].
	 *  2) Medium priority - data from config file [/config/mongo_db.php].
	 *  3) Low priority - values specified as default for class parameters
	 */
	private $config = [
		'settings'          => [],
		'connection_string' => '',
		'connection'        => [],
		'driver'            => []
	];

	/**
	 *  Group name in config file [/config/mongo_db.php], used by default.
	 *  
	 *  @var string
	 */
	private $config_group = 'default';

	/**
	 *  CONFIG_FILE_NAME - config file name [/config/mongo_db.php].
	 *  CONFIG_ARRAY_NAME - array name in config file. Array contains list of groups
	 *  and other parameters (for example, active_config_group).
	 */
	const CONFIG_FILE_NAME  = 'mongo_db';
	const CONFIG_ARRAY_NAME = 'mongo_db';

	/**
	 *  Protocol for creating a database connection.
	 *  
	 *  @var string
	 */
	private $protocol = 'mongodb://';

	/**
	 *  Database connection.
	 *  
	 *  @var MongoDB\Driver\Manager
	 */
	private $db, $connection;

	/**
	 *  Is authentication needed to be used? When connection 
	 *  through UNIX Domain Socket must be FALSE.
	 *  
	 *  @var boolean
	 */
	private $auth = TRUE;

	/**
	 *  Show debug info when working with data base?
	 *  I recommend to use TRUE during development.
	 *  
	 *  @var boolean
	 */
	private $debug = TRUE;

	/**
	 *  Format in which query results should be presented.
	 *  
	 *  @var 'array' or 'object'
	 */
	private $return_as = 'array';

	/**
	 *  Automatic reset of constructed query after it's initialization,
	 *  e.g after following method calls:
	 *  insert(), insertAll(), update(), updateAll(), delete(), deleteAll(), 
	 *  get(), getWhere(), getOne(), getOneWhere(), count().
	 *  
	 *  @var boolean
	 */
	private $auto_reset_query = TRUE;

	/**
	 *  Connection data.
	 */
	private $hosts         = [],
			$ports         = [],
			$user_name     = '',
			$user_password = '',
			$db_name       = '',
			$db_options    = [];

	/**
	 *  Driver options.
	 *  
	 *  @var array
	 */
	private $driver_options = [];

	/**
	 *  Query parameters.
	 */
	private $selects = [],
			$updates = [],
			$wheres  = [],
			$sorts   = [];

	/**
	 *  Additional query options.
	 */
	private $options = [
		'get'    => [],
		'update' => [],
		'delete' => []
	];
	
	/**
	 *  Limit & offset.
	 */
	const DEFAULT_LIMIT = 999999;
	private $limit  = self::DEFAULT_LIMIT,
			$offset = 0;

	/**
	 *  Settings for MongoDB\Driver\WriteConcern.
	 */
	const WRITE_CONCERN_MODE    = WriteConcern::MAJORITY;
	const WRITE_CONCERN_TIMEOUT = 1000;
	const WRITE_CONCERN_JOURNAL = FALSE;
	
	/**
	 *  Settings for MongoDB\Driver\BulkWrite.
	 */
	const BULK_WRITE_OPTIONS = [
		'ordered' => TRUE
	];
	
	/**
	 *  Settings for MongoDB\Driver\ReadPreference and MongoDB\Driver\ReadConcern.
	 */
	const READ_PREFERENCE_MODE = ReadPreference::RP_PRIMARY;
	const READ_CONCERN_LEVEL   = ReadConcern::LOCAL;

	// ------------------------------------------------------------------------

	/**
	 *  Library constructor. Accepts array with two arguments: config_group and config.
	 *  
	 *  1) Argument config_group contains name of used group in config file.
	 *  2) Argument config may contain data like in group from config file:
	 *     settings, connection_string, connection, driver and etc.
	 *
	 *  @param  array  $config  [Group name and config parameters]
	 *  
	 *  @uses   $this->load->library('mongo_db', ['config' => ['connection_string' => 'localhost:27015']]);
	 */
	function __construct(array $config = ['config_group' => '', 'config' => []])
	{
		// Check MongoDB PHP driver.
		if (!class_exists('MongoDB\Driver\Manager'))
		{
			$this->error('The MongoDB PECL extension has not been installed or enabled');
		}
		
		$this->CodeIgniter = &get_instance();

		$this->connect($config);
	}

	// ------------------------------------------------------------------------

	/**
	 *  Reconnect to DB.
	 *
	 *  @uses    $this->mongo_db->reconnect();
	 *  @uses    $this->mongo_db->reconnect(['config_group' => 'default_group']);
	 *  
	 *  @param   array   $config  [Group name and config parameters]
	 *  @see __construct @param description.
	 *  
	 *  @return  MongoDB\Driver\Manager
	 */
	public function reconnect(array $config = ['config_group' => '', 'config' => []]): Manager
	{
		return $this->connect($config);
	}

	/**
	 *  New connection to DB.
	 *
	 *  @uses    $this->mongo_db->connect();
	 *  @uses    $this->mongo_db->connect(['config_group' => 'default_group']);
	 *  
	 *  @param   array   $config  [Group name and config parameters]
	 *  @see __construct @param description.
	 *  
	 *  @return  MongoDB\Driver\Manager
	 */
	public function connect(array $config = ['config_group' => '', 'config' => []]): Manager
	{
		$this->config($config)->prepare();

		$connection_string = $this->create_connection_string();

		try
		{
			$this->connection = new Manager($connection_string, $this->db_options, $this->driver_options);
			$this->db = $this->connection;
		}
		catch (ConnectionException $e)
		{
			$this->error("Failed to connect to MongoDB: {$e->getMessage()}", __METHOD__);
		}

		return $this->db;
	}


	///////////////////////////////////////////////////////////////////////////
	//
	//  SELECT operation.
	//
	///////////////////////////////////////////////////////////////////////////

	/**
	 *  Creates list of fields during retrieving query result.
	 *
	 *  $includes contains list of fields, which needs to be included in query results.
	 *  $excludes contains list of fields, which needs to be excluded from query results.
	 *
	 *  @uses     $this->mongo_db->select(['_id', 'title', 'cost'])->get('payment');
	 *  @uses     $this->mongo_db->select([], ['description'])->get('payment');
	 *  @uses     $this->mongo_db->select(['_id', 'title', 'cost'], ['description'])->get('payment');
	 *  
	 *  @param     array   $includes  [<field1>, <field2>, ...]
	 *  @param     array   $excludes  [<field1>, <field2>, ...]
	 *  @return    $this
	 */
	public function select(array $includes = [], array $excludes = []): self
	{
		if (empty($includes) && empty($excludes))
		{
			$this->error('At least 1 argument should be passed to method', __METHOD__);
		}

		if (!empty($includes))
		{
			foreach ($includes as $field)
			{
				if (is_string($field) && $field != '')
				{
					$this->push_select_field($field, TRUE);
				}
				else
				{
					$this->error('Field name must not be an empty value of string type', __METHOD__);
				}
			}
		}
		
		if (!empty($excludes))
		{
			foreach ($excludes as $field)
			{
				if (is_string($field) && $field != '')
				{
					$this->push_select_field($field, FALSE);
				}
				else
				{
					$this->error('Field name must not be an empty value of string type', __METHOD__);
				}
			}
		}
		
		return $this;
	}




	///////////////////////////////////////////////////////////////////////////
	//
	//  WHERE operations.
	//
	///////////////////////////////////////////////////////////////////////////
	
	/**
	 *  List of comparison operators.
	 *  @see https://docs.mongodb.com/manual/reference/operator/query-comparison/
	 */
	
	/**
	 *  $fields equal $value ($fields = $number).
	 *  
	 *  Analogous of $eq in MongoDB. For construction of complex multidimensional 
	 *  queries use this method as a base in cooperation with other operators.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/eq/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->where('cost', 4.50)->get('payment');
	 *  @uses     $this->mongo_db->where(['cost' => 4.50, 'user.firstname' => 'Alex'])->get('payment');
	 *  @uses     $this->mongo_db->where('status', ['http' => $this->mongo_db->in([200, 201, 404, 500, 503])])->get('payment');
	 *  
	 *  @param    string|array  $fields  <field> OR [<field1> => <value|condition>, <field2> => <value|condition>, ...]
	 *  @param    mixed         $value   <value|condition>
	 *  @return   $this
	 */
	public function where($fields, $value = ''): self
	{
		if (isset($fields, $value) && is_string($fields) && $fields != '')
		{
			$this->push_where_field($fields, $value);
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $value)
			{
				if (is_string($field) && $field != '')
				{
					$this->push_where_field($field, $value);
				}
				else
				{
					$this->error('Each field name in list must not be an empty string', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Returns construction for operator $gt.
	 *
	 *  @uses     ['result' => $this->mongo_db->gt(5.5)]
	 *  
	 *  @param    float  $number  Number to compare
	 *  @return   array
	 */
	public function gt(float $number): array
	{
		$result = [];

		if (isset($number))
		{
			$result = ['$gt' => (float) $number];
		}
		else
		{
			$this->error('Specify 1 required numeric argument', __METHOD__);
		}
		
		return $result;
	}

	/**
	 *  $fields greater than $number ($fields > $number).
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/gt/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->where_gt('year', 2014)->get('purchase_history');
	 *  @uses     $this->mongo_db->where_gt(['year' => 2010, 'cost' => 4.99])->get('purchase_history');
	 *  
	 *  @param    string|array  $fields  <field> OR [<field1> => <number>, <field2> => <number>, ...]
	 *  @param    float         $number  <number>
	 *  @return   $this
	 */
	public function where_gt($fields, float $number = 0): self
	{
		if (isset($fields, $number) && is_string($fields) && $fields != '')
		{
			$this->push_where_field($fields, $this->gt($number));
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $number)
			{
				if (is_string($field) && $field != '' && is_float($number))
				{
					$this->push_where_field($field, $this->gt($number));
				}
				else
				{
					$this->error('Each field name in list must not be an empty string, value must be numeric', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Returns construction for operator $gte.
	 *
	 *  @uses     ['money.summary' => $this->mongo_db->gte(79)]
	 *  
	 *  @param     float  $number  Number to compare
	 *  @return    array
	 */
	public function gte(float $number): array
	{
		$result = [];

		if (isset($number))
		{
			$result = ['$gte' => (float) $number];
		}
		else
		{
			$this->error('Specify 1 required numeric argument', __METHOD__);
		}
		
		return $result;
	}

	/**
	 *  $fields greater than or equal $number ($fields >= $number).
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/gte/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->where_gte('cost', 99.9)->get('purchase_history');
	 *  @uses     $this->mongo_db->where_gte(['cost' => 99.9, 'time' => 147282174234])->get('purchase_history');
	 *  
	 *  @param    string|array  $fields  <field> OR [<field1> => <number>, <field2> => <number>, ...]
	 *  @param    float         $number  <number>
	 *  @return   $this
	 */
	public function where_gte($fields, float $number = 0): self
	{
		if (isset($fields, $number) && is_string($fields) && $fields != '')
		{
			$this->push_where_field($fields, $this->gte($number));
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $number)
			{
				if (is_string($field) && $field != '' && is_float($number))
				{
					$this->push_where_field($field, $this->gte($number));
				}
				else
				{
					$this->error('Each field name in list must not be an empty string, value must be numeric', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Returns construction for operator $lt.
	 *
	 *  @uses     ['k' => $this->mongo_db->lt(0.567)]
	 *  
	 *  @param    float  $number  Number to compare
	 *  @return   array
	 */
	public function lt(float $number): array
	{
		$result = [];

		if (isset($number))
		{
			$result = ['$lt' => (float) $number];
		}
		else
		{
			$this->error('Specify 1 required numeric argument', __METHOD__);
		}
		
		return $result;
	}

	/**
	 *  $fields lower than $number ($fields < $number).
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/lt/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->where_lt('day', 20)->updateAll('datepicker');
	 *  @uses     $this->mongo_db->where_lt(['day' => 20, 'minute' => 51])->get('datepicker');
	 *  
	 *  @param    string|array  $fields  <field> OR [<field1> => <number>, <field2> => <number>, ...]
	 *  @param    float         $number  <number>
	 *  @return   $this
	 */
	public function where_lt($fields, float $number = 0): self
	{
		if (isset($fields, $number) && is_string($fields) && $fields != '')
		{
			$this->push_where_field($fields, $this->lt($number));
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $number)
			{
				if (is_string($field) && $field != '' && is_float($number))
				{
					$this->push_where_field($field, $this->lt($number));
				}
				else
				{
					$this->error('Each field name in list must not be an empty string, value must be numeric', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Returns construction for operator $lte.
	 *
	 *  @uses     ['years' => $this->mongo_db->lte(35)]
	 *  
	 *  @param    float  $number  Number to compare
	 *  @return   array
	 */
	public function lte(float $number): array
	{
		$result = [];

		if (isset($number))
		{
			$result = ['$lte' => (float) $number];
		}
		else
		{
			$this->error('Specify 1 required numeric argument', __METHOD__);
		}
		
		return $result;
	}

	/**
	 *  $fields lower than or equal $number ($fields <= $number).
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/lte/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->where_lte('version', 3)->get('phones');
	 *  @uses     $this->mongo_db->where_lte(['version' => 3, 'count' => 50])->get('phones');
	 *  
	 *  @param    string|array  $fields  <field> OR [<field1> => <number>, <field2> => <number>, ...]
	 *  @param    float         $number  <number>
	 *  @return   $this
	 */
	public function where_lte($fields, float $number = 0): self
	{
		if (isset($fields, $number) && is_string($fields) && $fields != '')
		{
			$this->push_where_field($fields, $this->lte($number));
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $number)
			{
				if (is_string($field) && $field != '' && is_float($number))
				{
					$this->push_where_field($field, $this->lte($number));
				}
				else
				{
					$this->error('Each field name in list must not be an empty string, value must be numeric', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Returns construction based on usage of two operators: $gt and $lt.
	 *
	 *  @uses     ['years' => $this->mongo_db->beth(0, 11)]
	 *  
	 *  @param    float  $number_start  Minimum value
	 *  @param    float  $number_end    Maximum value
	 *  @return   array
	 */
	public function beth(float $number_start, float $number_end): array
	{
		$result = [];

		if (isset($number_start, $number_end))
		{
			$result = [
				'$gt' => (float) $number_start,
				'$lt' => (float) $number_end
			];
		}
		else
		{
			$this->error('Two required numeric arguments should be passed to method', __METHOD__);
		}
		
		return $result;
	}

	/**
	 *  $field value between $number_start and $number_end ($number_start < $field < $number_end).
	 *  
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $field
	 *
	 *  @uses     $this->mongo_db->where_beth('phone.version', 0, 11)->get('phones');
	 *  
	 *  @param    string  $field         <field>
	 *  @param    float   $number_start  <number1>
	 *  @param    float   $number_end    <number2>
	 *  @return   $this
	 */
	public function where_beth(string $field = '', float $number_start, float $number_end): self
	{
		if ($field != '' && isset($number_start, $number_end))
		{
			$this->push_where_field($field, $this->beth($number_start, $number_end));
		}
		else
		{
			$this->error('Three required arguments should be passed to method: field name, min and max values', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Returns construction based on usage of two operators: $gte and $lte.
	 *
	 *  @uses     ['years' => $this->mongo_db->beth_equal(1, 10)]
	 *  
	 *  @param    float  $number_start  Minimum value
	 *  @param    float  $number_end    Maximum value
	 *  @return   array
	 */
	public function beth_equal(float $number_start, float $number_end): array
	{
		$result = [];

		if (isset($number_start, $number_end))
		{
			$result = [
				'$gte' => (float) $number_start,
				'$lte' => (float) $number_end
			];
		}
		else
		{
			$this->error('Two required numeric arguments should be passed to method', __METHOD__);
		}
		
		return $result;
	}

	/**
	 *  $field value between or equal $number_start and/or $number_end ($number_start <= $field <= $number_end).
	 *  
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $field
	 *
	 *  @uses     $this->mongo_db->where_beth_equal('phone.version', 1, 10)->get('phones');
	 *  
	 *  @param    string  $field         <field>
	 *  @param    float   $number_start  <number1>
	 *  @param    float   $number_end    <number2>
	 *  @return   $this
	 */
	public function where_beth_equal(string $field = '', float $number_start, float $number_end): self
	{
		if ($field != '' && isset($number_start, $number_end))
		{
			$this->push_where_field($field, $this->beth_equal($number_start, $number_end));
		}
		else
		{
			$this->error('Three required arguments should be passed to method: field name, min and max values', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Returns construction based on usage of two operators: $lt and $gt.
	 *
	 *  @uses     ['years' => $this->mongo_db->nbeth(19, 34)]
	 *  
	 *  @param    float  $number_start  Minimum value
	 *  @param    float  $number_end    Maximum value
	 *  @return   array
	 */
	public function nbeth(float $number_start, float $number_end): array
	{
		$result = [];

		if (isset($number_start, $number_end))
		{
			$result = [
				'$lt' => (float) $number_start,
				'$gt' => (float) $number_end
			];
		}
		else
		{
			$this->error('Two required numeric arguments should be passed to method', __METHOD__);
		}
		
		return $result;
	}

	/**
	 *  $field value NO between $number_start and $number_end ($field < $number_start AND $field > $number_end).
	 *  
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $field
	 *
	 *  @uses     $this->mongo_db->where_nbeth('years', 19, 34)->get('users');
	 *  
	 *  @param    string  $field         <field>
	 *  @param    float   $number_start  <number1>
	 *  @param    float   $number_end    <number2>
	 *  @return   $this
	 */
	public function where_nbeth(string $field = '', float $number_start, float $number_end): self
	{
		if ($field != '' && isset($number_start, $number_end))
		{
			$this->push_where_field($field, $this->nbeth($number_start, $number_end));
		}
		else
		{
			$this->error('Three required arguments should be passed to method: field name, max and min values', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Returns construction based on usage of two operators: $lte and $gte.
	 *
	 *  @uses     ['years' => $this->mongo_db->nbeth_equal(18, 35)]
	 *  
	 *  @param    float  $number_start  Minimum value
	 *  @param    float  $number_end    Maximum value
	 *  @return   array
	 */
	public function nbeth_equal(float $number_start, float $number_end): array
	{
		$result = [];

		if (isset($number_start, $number_end))
		{
			$result = [
				'$lte' => (float) $number_start,
				'$gte' => (float) $number_end
			];
		}
		else
		{
			$this->error('Two required numeric arguments should be passed to method', __METHOD__);
		}
		
		return $result;
	}

	/**
	 *  $field value NO between or equal $number_start and/or $number_end ($field <= $number_start AND $field >= $number_end).
	 *  
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $field
	 *
	 *  @uses     $this->mongo_db->where_nbeth_equal('years', 18, 35)->get('users');
	 *  
	 *  @param    string  $field         <field>
	 *  @param    float   $number_start  <number1>
	 *  @param    float   $number_end    <number2>
	 *  @return   $this
	 */
	public function where_nbeth_equal(string $field = '', float $number_start, float $number_end): self
	{
		if ($field != '' && isset($number_start, $number_end))
		{
			$this->push_where_field($field, $this->nbeth_equal($number_start, $number_end));
		}
		else
		{
			$this->error('Three required arguments should be passed to method: field name, max and min values', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Returns construction based on usage $ne operators.
	 *
	 *  @uses     ['phone.os' => $this->mongo_db->ne('ios')]
	 *  
	 *  @param    mixed  $value  Any value
	 *  @return   array
	 */
	public function ne($value): array
	{
		$result = [];

		if (isset($value))
		{
			$result = ['$ne' => $value];
		}
		else
		{
			$this->error('One required numeric argument should be passed to method', __METHOD__);
		}
		
		return $result;
	}

	/**
	 *  $fields value NOT equal $value ($fields != $value).
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/ne/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->where_ne('phone.os', 'ios')->get('phones');
	 *  @uses     $this->mongo_db->where_ne(['phone.os' => 'ios', 'phone.type' => 'touch'])->get('phones');
	 *  
	 *  @param    string|array  $fields  <field> OR [<field1> => <value>, <field2> => <value>, ...]
	 *  @param    mixed         $value   <value>
	 *  @return   $this
	 */
	public function where_ne($fields, $value = ''): self
	{
		if (isset($fields, $value) && is_string($fields) && $fields != '')
		{
			$this->push_where_field($fields, $this->ne($value));
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $value)
			{
				if (is_string($field) && $field != '')
				{
					$this->push_where_field($field, $this->ne($value));
				}
				else
				{
					$this->error('Each field name in list must not be an empty string', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Returns construction based on usage of $in operator.
	 *
	 *  @uses     ['number' => $this->mongo_db->in(['one', 'two', 'three', 'four'])]
	 *  
	 *  @param    array  $list  List of possible values
	 *  @return   array
	 */
	public function in(array $list): array
	{
		$result = [];

		if (isset($list) && !empty($list))
		{
			$result = ['$in' => $list];
		}
		else
		{
			$this->error('One required non empty array argument should be passed to method', __METHOD__);
		}
		
		return $result;
	}

	/**
	 *  $fields value exists in $list (as PHP function in_array()).
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/in/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->where_in('number', ['one', 'two', 'three', 'four'])->get('numbers');
	 *  @uses     $this->mongo_db->where_in(['number' => ['one', 'two', 'four'], 'type' => ['error', 'warning']])->get('numbers');
	 *  
	 *  @param    string|array  $fields  <field> OR [<field1> => [<value1>, ..., <valueN>], <field2> => [<value1>, ..., <valueN>]]
	 *  @param    array         $list    [<value1>, ..., <valueN>]
	 *  @return   $this
	 */
	public function where_in($fields, array $list = []): self
	{
		if (isset($fields, $list) && is_string($fields) && $fields != '' && !empty($list))
		{
			$this->push_where_field($fields,  $this->in($list));
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $list)
			{
				if (is_string($field) && $field != '' && is_array($list) && !empty($list))
				{
					$this->push_where_field($field, $this->in($list));
				}
				else
				{
					$this->error('Each field name in list must not be an empty string, value must be not an empty array', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Returns construction based on usage of $nin operator.
	 *
	 *  @uses     ['version' => $this->mongo_db->nin(['ios', 'android'])]
	 *  
	 *  @param    array  $list  Any value
	 *  @return   array
	 */
	public function nin(array $list): array
	{
		$result = [];

		if (isset($list) && !empty($list))
		{
			$result = ['$nin' => $list];
		}
		else
		{
			$this->error('One required non empty array argument should be passed to method', __METHOD__);
		}
		
		return $result;
	}

	/**
	 *  $fields value NO exists in $list (as PHP function in_array() === FALSE).
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/nin/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->where_nin('version', ['ios', 'android'])->get('phones');
	 *  @uses     $this->mongo_db->where_nin(['version' => ['ios', 'android'], 'type' => ['touch']])->get('phones');
	 *  
	 *  @param    string|array  $fields  <field> OR [<field1> => [<value1>, ..., <valueN>], <field2> => [<value1>, ..., <valueN>]]
	 *  @param    array         $list    [<value1>, ..., <valueN>]
	 *  @return   $this
	 */
	public function where_nin($fields, array $list = []): self
	{
		if (isset($fields, $list) && is_string($fields) && $fields != '' && !empty($list))
		{
			$this->push_where_field($fields,  $this->in($list));
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $list)
			{
				if (is_string($field) && $field != '' && is_array($list) && !empty($list))
				{
					$this->push_where_field($field, $this->in($list));
				}
				else
				{
					$this->error('Each field name in list must not be an empty string, value must be not an empty array', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}




	/**
	 *  Logical operators.
	 *  @see https://docs.mongodb.com/manual/reference/operator/query-logical/
	 */

	/**
	 *  Returns construction based on usage of $or operator.
	 *
	 *  @uses     $this->mongo_db->or(['user.name' => 'Alex', 'city' => 'Moscow', 'years' => $this->mongo_db->gte(18)]);
	 *  
	 *  @param    array  $conditions  List of conditions
	 *  @return   array
	 */
	public function or(array $conditions): array
	{
		$result = [];

		if (isset($conditions) && !empty($conditions))
		{
			foreach ($conditions as $field => $value)
			{
				if (is_string($field) && $field != '')
				{
					$result['$or'][] = [$field => $value];
				}
				else
				{
					$this->error('Each field name in list must not be an empty string', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('One required non empty array argument should be passed to method', __METHOD__);
		}
		
		return $result;
	}

	/**
	 *  List of conditions, where at least one of listed should be fulfilled.
	 *  $fieldA = $valueA OR $fieldB = $valueB OR ... etc.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/or/
	 *
	 *  @uses     $this->mongo_db->or_where(['years' => $this->mongo_db->gte(18), 'name' => 'Dima'])->get('users');
	 *  
	 *  @param    array   $conditions  [<expression1>, <expression2>, ... , <expressionN>]
	 *  @return   $this
	 */
	public function or_where(array $conditions): self
	{
		if (isset($conditions) && !empty($conditions))
		{
			$this->create_where_field('$or');
			
			foreach ($conditions as $field => $value)
			{
				if (is_string($field) && $field != '')
				{
					$this->wheres['$or'][] = [$field => $value];
				}
				else
				{
					$this->error('Each field name in list must not be an empty string', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('One required non empty array argument should be passed to method', __METHOD__);
		}

		return $this;
	}

	/**
	 *  Returns construction based on usage of $and operator.
	 *
	 *  @uses     $this->mongo_db->and(['price' => 100, 'type' => 'keyboard']);
	 *  
	 *  @param    array  $conditions  List of conditions
	 *  @return   array
	 */
	public function and(array $conditions): array
	{
		$result = [];

		if (isset($conditions) && !empty($conditions))
		{
			foreach ($conditions as $field => $value)
			{
				if (is_string($field) && $field != '')
				{
					$result['$and'][] = [$field => $value];
				}
				else
				{
					$this->error('Each field name in list must not be an empty string', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('One required non empty array argument should be passed to method', __METHOD__);
		}
		
		return $result;
	}

	/**
	 *  List of all conditions, which should be fulfilled.
	 *  $fieldA = $valueA AND $fieldB = $valueB AND ... etc.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/and/
	 *
	 *  @uses     $this->mongo_db->and_where(['type' => 'keyboard', 'cost' => $this->mongo_db->gt(1980.50)])->get('purchase');
	 *  
	 *  @param    array   $conditions  [<expression1>, <expression2>, ... , <expressionN>]
	 *  @return   $this
	 */
	public function and_where(array $conditions): self
	{
		if (isset($conditions) && !empty($conditions))
		{
			$this->create_where_field('$and');
			
			foreach ($conditions as $field => $value)
			{
				if (is_string($field) && $field != '')
				{
					$this->wheres['$and'][] = [$field => $value];
				}
				else
				{
					$this->error('Each field name in list must not be an empty string', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('One required non empty array argument should be passed to method', __METHOD__);
		}

		return $this;
	}

	/**
	 *  Returns construction based on usage of $not operator.
	 *
	 *  @uses     ['price' => $this->mongo_db->not($this->mongo_db->lte(600))];
	 *  
	 *  @param    mixed  $condition  Condition/List of conditions/Value
	 *  @return   array
	 */
	public function not($condition): array
	{
		$result = [];

		if (isset($condition))
		{
			$result = ['$not' => $condition];
		}
		else
		{
			$this->error('One required numeric argument should be passed to method', __METHOD__);
		}
		
		return $result;
	}

	/**
	 *  Field or list of fields which does not satisfy specified conditions.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/not/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->not_where('price', $this->mongo_db->lte(600))->get('collection');
	 *  @uses     $this->mongo_db->not_where(['item.type' => $this->mongo_db->nin(['phone', 'keyboard']), 'cost' => $this->mongo_db->lt(99.90)])->get('collection');
	 *  
	 *  @param    string|array  $fields      <field> OR [<field1> => <operator-expression>, <field2> => <operator-expression>, ...]
	 *  @param    mixed         $condition   <operator-expression>
	 *  @return   $this
	 */
	public function not_where($fields, $condition = ''): self
	{
		if (isset($fields, $condition) && is_string($fields) && $fields != '')
		{
			$this->push_where_field($fields, $this->not($condition));
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $condition)
			{
				if (is_string($field) && $field != '')
				{
					$this->push_where_field($field, $this->not($condition));
				}
				else
				{
					$this->error('Each field name in list must not be an empty string', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Returns construction based on usage of $nor operator.
	 *
	 *  @uses     $this->mongo_db->nor(['price' => 1.99, 'sale' => TRUE]);
	 *  
	 *  @param    array  $conditions  List of conditions
	 *  @return   array
	 */
	public function nor(array $conditions): array
	{
		$result = [];

		if (isset($conditions) && !empty($conditions))
		{
			foreach ($conditions as $field => $value)
			{
				if (is_string($field) && $field != '')
				{
					$result['$nor'][] = [$field => $value];
				}
				else
				{
					$this->error('Each field name in list must not be an empty string', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('One required non empty array argument should be passed to method', __METHOD__);
		}
		
		return $result;
	}

	/**
	 *  Detailed information presented in MongoDB documentation.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/nor/
	 *
	 *  @uses     $this->mongo_db->nor_where(['price' => 1.99, 'sale' => TRUE])->get('cart');
	 *  
	 *  @param    array   $conditions  [<expression1>, <expression2>, ... , <expressionN>]
	 *  @return   $this
	 */
	public function nor_where(array $conditions): self
	{
		if (isset($conditions) && !empty($conditions))
		{
			$this->create_where_field('$nor');
			
			foreach ($conditions as $field => $value)
			{
				if (is_string($field) && $field != '')
				{
					$this->wheres['$nor'][] = [$field => $value];
				}
				else
				{
					$this->error('Each field name in list must not be an empty string', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('One required non empty array argument should be passed to method', __METHOD__);
		}

		return $this;
	}




	/**
	 *  Element operators.
	 *  @see https://docs.mongodb.com/manual/reference/operator/query-element/
	 */
	
	/**
	 *  Returns construction based on usage of $exists operator.
	 *
	 *  @uses     ['user.country' => $this->mongo_db->exists(TRUE)];
	 *  
	 *  @param    boolean  $exists  List of conditions
	 *  @return   array
	 */
	public function exists(bool $exists): array
	{
		$result = [];

		if (isset($exists))
		{
			$result = ['$exists' => (bool) $exists];
		}
		else
		{
			$this->error('One required numeric argument should be passed to method', __METHOD__);
		}
		
		return $result;
	}

	/**
	 *  Field or list of fields which must be presented (or not presented) in document.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/exists/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->where_exists('user.country', TRUE)->get('users');
	 *  @uses     $this->mongo_db->where_exists(['user.city' => TRUE, 'user.banned_reason' => FALSE])->get('users');
	 *  
	 *  @param    string|array  $fields  <field> OR [<field1> => <boolean>, <field2> => <boolean>, ...]
	 *  @param    bool          $exists  <boolean>
	 *  @return   $this
	 */
	public function where_exists($fields, bool $exists = FALSE): self
	{
		if (isset($fields, $exists) && is_string($fields) && $fields != '')
		{
			$this->push_where_field($fields, $this->exists($exists));
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $exists)
			{
				if (is_string($field) && $field != '' && is_bool($exists))
				{
					$this->push_where_field($field, $this->exists($exists));
				}
				else
				{
					$this->error('Each field name in list must be not an empty string, value should be of type boolean', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Returns construction based on usage of $type operator.
	 *
	 *  @uses     ['js_code' => $this->mongo_db->type('javascript')];
	 *  
	 *  @param    integer|string  $type  <BSON type number|String alias>
	 *  @return   array
	 */
	public function type($type): array
	{
		$result = [];

		if (isset($type) && $type != '')
		{
			$result = ['$type' => $type];
		}
		else
		{
			$this->error('One required numeric argument should be passed to method — <BSON type number> or <String alias>', __METHOD__);
		}
		
		return $result;
	}

	/**
	 *  Field or list of fields and data type of values of all fields Double, String, ObjectID, etc).
	 *  List of available data types presented in MongoDB documentation.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/type/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->where_type('js_code', 'javascript')->get('collection');
	 *  @uses     $this->mongo_db->where_type(['js_code' => 'javascript', 'create_at' => 'date'])->get('collection');
	 *  
	 *  @param    string|array    $fields  <field> OR [<field1> => <BSON type number|String alias>, <field2> => <BSON type number|String alias>, ...]
	 *  @param    integer|string  $type    <BSON type number|String alias>
	 *  @return   $this
	 */
	public function where_type($fields, $type = ''): self
	{
		if (isset($fields, $type) && is_string($fields) && $fields != '' && $type != '')
		{
			$this->push_where_field($fields, $this->type($type));
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $type)
			{
				if (is_string($field) && $field != '' && $type != '')
				{
					$this->push_where_field($field, $this->type($type));
				}
				else
				{
					$this->error('Each field name in list must be not an empty string, data type should be <BSON type number> or <String alias>', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}




	/**
	 *  Evaluation operators.
	 *  @see https://docs.mongodb.com/manual/reference/operator/query-evaluation/
	 */
	
	/**
	 *  Returns construction based on usage of $mod operator.
	 *
	 *  @uses     ['cost' => $this->mongo_db->mod(4, 2)];
	 *  
	 *  @param    float   $divisor    [Divisor value]
	 *  @param    float   $remainder  [Remainder value]
	 *  @return   array
	 */
	public function mod(float $divisor, float $remainder): array
	{
		$result = [];

		if (isset($divisor, $remainder))
		{
			$result = ['$mod' => [(float) $divisor, (float) $remainder]];
		}
		else
		{
			$this->error('Two required argument should be passed to method — divisor and remainder value', __METHOD__);
		}
		
		return $result;
	}

	/**
	 *  Division of $field on $divisor as such that modulo $remainder.
	 *  Note that with irrational division problems with result occurs (problem occurs on MongoDB level).
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/mod/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $field
	 *
	 *  @uses     $this->mongo_db->where_mod('cost', 4, 2)->get('collection');
	 *  
	 *  @param    string  $field      [Field name]
	 *  @param    float   $divisor    [Divisor value]
	 *  @param    float   $remainder  [Remainder value]
	 *  @return   $this
	 */
	public function where_mod(string $field = '', float $divisor, float $remainder): self
	{
		if ($field != '' && isset($divisor, $remainder))
		{
			$this->push_where_field($field, $this->mod($divisor, $remainder));
		}
		else
		{
			$this->error('Three required argument should be passed to method — field name, divisor and remainder value', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Returns regular expression constructed with help of MongoDB\BSON\Regex.
	 *
	 *  @uses     ['user.name' => $this->mongo_db->regex('Dmitr(i|y)i', iU, FALSE)];
	 *  
	 *  @param    string        $regex                  [Regex line: [0-9A-Z], .*, ...]
	 *  @param    string        $flags                  [Regex flags: i, U, ...]
	 *  @param    bool|boolean  $enable_start_wildcard  [Should be used opening symbol ^]
	 *  @param    bool|boolean  $enable_end_wildcard    [Should be used closing symbol $]
	 *  @return   Regex
	 */
	public function regex(string $regex = '', string $flags = 'i', bool $enable_start_wildcard = TRUE, bool $enable_end_wildcard = TRUE)
	{
		$regex = quotemeta($regex);
		
		if ($enable_start_wildcard === TRUE)
		{
			$regex = '^' . $regex;
		}
		
		if ($enable_end_wildcard === TRUE)
		{
			$regex .= '$';
		}

		return new Regex($regex, (string) $flags);
	}

	/**
	 *  Search by $field, that satisfies specified regular expression.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/regex/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $field
	 *
	 *  @uses     $this->mongo_db->like('user.name', 'alex')->get('users');
	 *  
	 *  @param    string   $field                  [Field name]
	 *  @param    string   $regex                  [Regex line: [0-9A-Z], .*, ...]
	 *  @param    string   $flags                  [Regex flags: i, U, ...]
	 *  @param    boolean  $enable_start_wildcard  [Should be used opening symbol ^]
	 *  @param    boolean  $enable_end_wildcard    [Should be used closing symbol $]
	 *  @return   $this
	 */
	public function like(string $field = '', string $regex = '', string $flags = 'i', bool $enable_start_wildcard = TRUE, bool $enable_end_wildcard = TRUE): self
	{
		if ($field != '')
		{
			$this->push_where_field($field, $this->regex($regex, $flags, $enable_start_wildcard, $enable_end_wildcard));
		}
		else
		{
			$this->error('At least 1 argument should be passed to method — field name', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Options parsing when full text search.
	 *  
	 *  @param   array   $options  [Options list]
	 *  @return  array
	 */
	private function get_text_options(array $options = []): array
	{
		$result = [];

		if (!empty($options))
		{
			if (isset($options['language']) && is_string($options['language']) && $options['language'] != '')
			{
				$result['$language'] = (string) trim($options['language']);
			}

			if (isset($options['case']) && is_bool($options['case']))
			{
				$result['$caseSensitive'] = (bool) $options['case'];
			}

			if (isset($options['diacritic']) && is_bool($options['diacritic']))
			{
				$result['$diacriticSensitive'] = (bool) $options['diacritic'];
			}
		}

		return $result;
	}

	/**
	 *  Full text search.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/text/
	 *
	 *  @uses     $this->mongo_db->where_text('cake', ['caseSensitive' => TRUE])->get('collection');
	 *  
	 *  @param    string  $text     [Search string]
	 *  @param    array   $options  [Search options]
	 *
	 *  $options can contains following parameters:
	 *  1) language - search language @see https://docs.mongodb.com/manual/reference/text-search-languages/#text-search-languages
	 *  2) case - case sensitive (default is FALSE)
	 *  3) diacritic - diacritic symbols sensitive (default is FALSE)
	 *  
	 *  @return    $this
	 */
	public function where_text(string $text, array $options = []): self
	{
		if (isset($text) && $text != '')
		{
			$this->push_where_field('$text', ['$search' => (string) $text] + $this->get_text_options($options));
		}
		else
		{
			$this->error('At least 1 argument should be passed to method — search text', __METHOD__);
		}

		return $this;
	}

	/**
	 *  Used when full text search for sorting results.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/text/#sort-by-text-search-score
	 *
	 *  @uses     $this->mongo_db->where_text('tea cup')->textScore('score')->textScore_sort('score')->get('book');
	 *  
	 *  @param    string  $result_field  [Field name with relevant value of search result]
	 *  @return   $this
	 */
	public function textScore_sort(string $result_field = ''): self
	{
		if ($result_field != '')
		{
			$this->push_sort_field($result_field, ['$meta' => 'textScore']);
		}
		else
		{
			$this->error('Field name must be not an empty string', __METHOD__);
		}

		return $this;
	}

	/**
	 *  Used when full text search for recording text relevant 
	 *  value of each found document to specified field.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/text/#return-the-text-search-score
	 *
	 *  @uses     $this->mongo_db->where_text('tea cup')->textScore('score', TRUE)->get('book');
	 *  
	 *  @param    string   $result_field  [Field name, place where text relevant value will be recorded]
	 *  @param    boolean  $sort          [Should sort search results by relevant value?]
	 *  @return   $this
	 */
	public function textScore(string $result_field = '', bool $sort = FALSE): self
	{
		if ($result_field != '')
		{
			$this->push_select_field($result_field, ['$meta' => 'textScore']);

			if ($sort === TRUE)
			{
				$this->textScore_sort($result_field);
			}
		}
		else
		{
			$this->error('Field name must be not an empty string', __METHOD__);
		}

		return $this;
	}

	/**
	 *  Returns construction based on usage of $where operator.
	 *
	 *  @uses     $this->mongo_db->js('function() { return (this.credits == this.debits) }');
	 *  
	 *  @param    string   $js   [JavaScript function as string]
	 *  @return   array
	 */
	public function js(string $js = ''): array
	{
		$result = [];

		if ($js != '')
		{
			$result = ['$where' => new Javascript($js)];
		}
		else
		{
			$this->error('JavaScript-code should be passed as non empty string', __METHOD__);
		}
		
		return $result;
	}

	/**
	 *  Use JavaScript to search documents.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/where/
	 *
	 *  @uses     $this->mongo_db->where_js('function() { return (this.credits == this.debits) }')->get('economics');
	 *  
	 *  @param    string  $js  [JavaScript function as string]
	 *  @return   $this
	 */
	public function where_js(string $js = ''): self
	{
		if ($js != '')
		{
			$this->push_where_field('$where', new Javascript($js));
		}
		else
		{
			$this->error('JavaScript-code should be passed as non empty string', __METHOD__);
		}

		return $this;
	}




	/**
	 *  Geospatial operators.
	 *  @see https://docs.mongodb.com/manual/reference/operator/query-geospatial/
	 *
	 *  In current library there is not operator implementation.
	 */

	/**
	 *  None.
	 */



	/**
	 *  Array operators.
	 *  @see https://docs.mongodb.com/manual/reference/operator/query-array/
	 */
	
	/**
	 *  Returns construction based on usage of $all operator.
	 *
	 *  @uses     ['article.tags' => $this->mongo_db->all(['news', 'yellow', 'trades'])];
	 *  
	 *  @param    array   $list    [<value1>, <value2>, ... , <valueN>]
	 *  @return   array
	 */
	public function all(array $list): array
	{
		$result = [];

		if (isset($list) && !empty($list))
		{
			$result = ['$all' => $list];
		}
		else
		{
			$this->error('One required non empty array argument should be passed to method', __METHOD__);
		}
		
		return $result;
	}

	/**
	 *  Field contains each value.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/all/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->where_all('tags', ['news', 'war', 'people'])->get('articles');
	 *  @uses     $this->mongo_db->where_all(['tags' => ['news', 'war', 'people'], 'authors' => ['Dima', 'Alex']])->get('articles');
	 *  
	 *  @param    string|array  $fields  <field> OR [<field1> => [<value1>, ... , <valueN>], <field2> => [<value1>, ... , <valueN>], ...]
	 *  @param    array         $list    [<value1>, <value2>, ... , <valueN>]
	 *  @return   $this
	 */
	public function where_all($fields, array $list = []): self
	{
		if (isset($fields, $list) && is_string($fields) && $fields != '' && !empty($list))
		{
			$this->push_where_field($fields,  $this->all($list));
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $list)
			{
				if (is_string($field) && $field != '' && is_array($list) && !empty($list))
				{
					$this->push_where_field($field, $this->all($list));
				}
				else
				{
					$this->error('Each field name in list must not be an empty string, value must be not an empty array', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Returns construction based on usage of $elemMatch operator.
	 *
	 *  @uses     ['results' => $this->mongo_db->elemMatch([$this->mongo_db->gt(10), $this->mongo_db->lt(50)])];
	 *  
	 *  @param    array   $list    [<query1>, <query2>, ... , <queryN>]
	 *  @return   array
	 */
	public function elemMatch(array $list): array
	{
		$result = [];

		if (isset($list) && !empty($list))
		{
			$result = ['$elemMatch' => $list];
		}
		else
		{
			$this->error('Specify 1 required argument - not an empty array', __METHOD__);
		}
		
		return $result;
	}

	/**
	 *  Checks each array element with given condition.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/elemMatch/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->where_elemMatch('results', [$this->mongo_db->gt(10), $this->mongo_db->lt(50)])->get('news');
	 *  @uses     $this->mongo_db->where_elemMatch(['res' => [$this->mongo_db->gt(10), $this->mongo_db->lt(50)], 'tags' => ['$in' => ['tag1', 'tag2']]])->get('news');
	 *  
	 *  @param    string|array  $fields  <field> OR [<field1> => [<query1>, ... , <queryN>], <field2> => [<query1>, ... , <queryN>], ...]
	 *  @param    array         $list    [<query1>, <query2>, ... , <queryN>]
	 *  @return   $this
	 */
	public function where_elemMatch($fields, array $list = []): self
	{
		if (isset($fields, $list) && is_string($fields) && $fields != '' && !empty($list))
		{
			$this->push_where_field($fields,  $this->elemMatch($list));
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $list)
			{
				if (is_string($field) && $field != '' && is_array($list) && !empty($list))
				{
					$this->push_where_field($field, $this->elemMatch($list));
				}
				else
				{
					$this->error('Each field name in list must not be an empty string, value must be not an empty array', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Returns construction based on useage of $size operator.
	 *
	 *  @uses     ['some.array' => $this->mongo_db->size(5)];
	 *  
	 *  @param    int   $size    <number>
	 *  @return   array
	 */
	public function size(int $size): array
	{
		$result = [];

		if (isset($size))
		{
			$result = ['$size' => (int) $size];
		}
		else
		{
			$this->error('Specify 1 required numeric argument', __METHOD__);
		}
		
		return $result;
	}

	/**
	 *  Sets array size in initial document.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/size/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->where_size('some.array', 5)->get('users');
	 *  @uses     $this->mongo_db->where_size(['user.news' => 10, 'user.tags' => 8])->get('users');
	 *  
	 *  @param    string|array  $fields  <field> OR [<field1> => <number>, <field2> => <number>, ...]
	 *  @param    integer       $list    <number>
	 *  @return   $this
	 */
	public function where_size($fields, int $size = 0): self
	{
		if (isset($fields, $size) && is_string($fields) && $fields != '')
		{
			$this->push_where_field($fields,  $this->size($size));
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $size)
			{
				if (is_string($field) && $field != '' && is_integer($size))
				{
					$this->push_where_field($field, $this->size($size));
				}
				else
				{
					$this->error('Each field name in list must not be an empty string, value must be numeric', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}




	/**
	 *  Bitwise operators.
	 *  @see https://docs.mongodb.com/manual/reference/operator/query-bitwise/
	 */
	
	/**
	 *  Bitwise operation constructor.
	 *  
	 *  @param   string  $type  [Bitwise operation mode]
	 *  @param   string  $data  <numeric bitmask|BinData bitmask|[<position1>, <position2>, ... ]>
	 *  @return  array
	 */
	private function _bits(string $type = '', $data = '', string $method = ''): array
	{
		$result = [];

		if ($type != '' && $data != '')
		{
			$result = [$type => $data];
		}
		else
		{
			$this->error('Specify 1 required argument in numeric bitmask, BinData bitmask or positions list format', $method);
		}
		
		return $result;
	}

	/**
	 *  Construction for where bitwise operation.
	 *  
	 *  @param   string  $type   [Bitwise operation mode]
	 *  @param   string  $field  <field>
	 *  @param   string  $data   <numeric bitmask|BinData bitmask|[<position1>, <position2>, ... ]>
	 *  @return  $this
	 */
	private function _where_bits(string $type = '', string $field = '', $data = '', string $method = ''): self
	{
		if ($type != '' && $field != '' && $data != '')
		{
			$this->push_where_field($field, $this->_bits($type, $data));
		}
		else
		{
			$this->error('Specify 2 required arguments - field name and value in numeric bitmask, BinData bitmask or positions list format', $method);
		}

		return $this;
	}

	/**
	 *  Returns construction based on usage of $bitsAllSet operator.
	 *
	 *  @uses     ['bit' => $this->mongo_db->bitsAllSet(35)];
	 *  @uses     ['bit' => $this->mongo_db->bitsAllSet([1, 5])];
	 *  
	 *  @param    mixed   $data    <numeric bitmask|BinData bitmask|[<position1>, <position2>, ... ]>
	 *  @return   array
	 */
	public function bitsAllSet($data = ''): array
	{
		return $this->_bits('$bitsAllSet', $data, __METHOD__);
	}

	/**
	 *  Bitwise operation $bitsAllSet.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/bitsAllSet/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $field
	 *
	 *  @uses     $this->mongo_db->where_bitsAllSet('bit', 35)->get('collection');
	 *  @uses     $this->mongo_db->where_bitsAllSet('bit', [1, 5])->get('collection');
	 *  
	 *  @param    string  $field  <field>
	 *  @param    mixed   $data   <numeric bitmask|BinData bitmask|[<position1>, <position2>, ... ]>
	 *  @return   $this
	 */
	public function where_bitsAllSet(string $field = '', $data = ''): self
	{
		return $this->_where_bits('$bitsAllSet', $field, $data, __METHOD__);
	}
	
	/**
	 *  Returns construction based on usage of $bitsAnySet operator.
	 *
	 *  @uses     ['bit' => $this->mongo_db->bitsAnySet('22')];
	 *  @uses     ['bit' => $this->mongo_db->bitsAnySet([1, 5])];
	 *  
	 *  @param    mixed   $data    <numeric bitmask|BinData bitmask|[<position1>, <position2>, ... ]>
	 *  @return   array
	 */
	public function bitsAnySet($data = ''): array
	{
		return $this->_bits('$bitsAnySet', $data, __METHOD__);
	}

	/**
	 *  Bitwise operation $bitsAnySet.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/bitsAnySet/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $field
	 *
	 *  @uses     $this->mongo_db->where_bitsAnySet('bit', 35)->get('collection');
	 *  @uses     $this->mongo_db->where_bitsAnySet('bit', [1, 5])->get('collection');
	 *  
	 *  @param    string  $field  <field>
	 *  @param    mixed   $data   <numeric bitmask|BinData bitmask|[<position1>, <position2>, ... ]>
	 *  @return   $this
	 */
	public function where_bitsAnySet(string $field = '', $data = ''): self
	{
		return $this->_where_bits('$bitsAnySet', $field, $data, __METHOD__);
	}

	/**
	 *  Returns construction based on usage of $bitsAllClear operator.
	 *
	 *  @uses     ['bit' => $this->mongo_db->bitsAllClear(35)];
	 *  @uses     ['bit' => $this->mongo_db->bitsAllClear([1, 5])];
	 *  
	 *  @param    mixed   $data    <numeric bitmask|BinData bitmask|[<position1>, <position2>, ... ]>
	 *  @return   array
	 */
	public function bitsAllClear($data = ''): array
	{
		return $this->_bits('$bitsAllClear', $data, __METHOD__);
	}

	/**
	 *  Bitwise operation $bitsAllClear.
	 *  
	 *  @see       https://docs.mongodb.com/manual/reference/operator/query/bitsAllClear/
	 *  @see       https://docs.mongodb.com/manual/core/document/#document-dot-notation for $field
	 *
	 *  @uses     $this->mongo_db->where_bitsAllClear('bit', 35)->get('collection');
	 *  @uses     $this->mongo_db->where_bitsAllClear('bit', [1, 5])->get('collection');
	 *  
	 *  @param     string  $field  <field>
	 *  @param     mixed   $data   <numeric bitmask|BinData bitmask|[<position1>, <position2>, ... ]>
	 *  @return    $this
	 */
	public function where_bitsAllClear(string $field = '', $data = ''): self
	{
		return $this->_where_bits('$bitsAllClear', $field, $data, __METHOD__);
	}

	/**
	 *  Returns construction based on usage of $bitsAnyClear operator.
	 *
	 *  @uses     ['bit' => $this->mongo_db->bitsAnyClear(35)];
	 *  @uses     ['bit' => $this->mongo_db->bitsAnyClear([1, 5])];
	 *  
	 *  @param    mixed   $data    <numeric bitmask|BinData bitmask|[<position1>, <position2>, ... ]>
	 *  @return   array
	 */
	public function bitsAnyClear($data = ''): array
	{
		return $this->_bits('$bitsAnyClear', $data, __METHOD__);
	}

	/**
	 *  Bitwise operation $bitsAnyClear.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/query/bitsAnyClear/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $field
	 *
	 *  @uses     $this->mongo_db->where_bitsAnyClear('bit', 35)->get('collection');
	 *  @uses     $this->mongo_db->where_bitsAnyClear('bit', [1, 5])->get('collection');
	 *  
	 *  @param    string  $field  <field>
	 *  @param    mixed   $data   <numeric bitmask|BinData bitmask|[<position1>, <position2>, ... ]>
	 *  @return   $this
	 */
	public function where_bitsAnyClear(string $field = '', $data = ''): self
	{
		return $this->_where_bits('$bitsAnyClear', $field, $data, __METHOD__);
	}




	/**
	 *  Projection operators.
	 *  @see https://docs.mongodb.com/manual/reference/operator/projection/
	 */
	
	/**
	 *  Returns only first element of array satisfying given conditions.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/projection/positional/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->findOne('prices')->get('cart');
	 *  @uses     $this->mongo_db->findOne(['prices', 'tags'])->get('cart');
	 *  
	 *  @param    string|array  $fields  <field> OR [<field1>, <field2>, ..., <fieldN>]
	 *  @return   $this
	 */
	public function findOne($fields): self
	{
		if (isset($fields) && is_string($fields) && $fields != '')
		{
			$this->push_select_field("{$fields}.\$", TRUE);
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field)
			{
				if (is_string($field) && $field != '')
				{
					$this->push_select_field("{$field}.\$", TRUE);
				}
				else
				{
					$this->error('Each field name in list must not be an empty string', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Returns only first element of array satisfying given conditions.
	 *  For better understanding see MongoDB documentation.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/projection/elemMatch/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->findOne_elemMatch('students', ['school' => 102])->get('school');
	 *  @uses     $this->mongo_db->findOne_elemMatch(['students' => ['school' => 102, 'age' => $this->mongo_db->gte(18)], 'teachers' => ['age' => $this->mongo_db->gte(30)]])->get('school');
	 *  
	 *  @param    string|array  $fields      <field> OR [<field1> => <conditions>, <field2> => <conditions>, ...]
	 *  @param    array         $conditions  <conditions>
	 *  @return   $this
	 */
	public function findOne_elemMatch($fields, array $conditions = []): self
	{
		if (isset($fields, $conditions) && is_string($fields) && $fields != '' && !empty($conditions))
		{
			$this->push_select_field($fields, ['$elemMatch' => $conditions]);
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $conditions)
			{
				if (is_string($field) && $field != '' && is_array($conditions) && !empty($conditions))
				{
					$this->push_select_field($field, ['$elemMatch' => $conditions]);
				}
				else
				{
					$this->error('Each field name in list must not be an empty string, value must be not an empty array', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  For $meta @see textScore() and textScore_sort() methods.
	 */ 

	/**
	 *  $slice constructor by array [x, y] or integer x.
	 *  
	 *  @param   array|integer  $slice  [Slice value]
	 *  @return  array|integer
	 */
	private function get_slice_option($slice, string $method = '') // : array|integer
	{
		$result = 0;

		if (isset($slice))
		{
			if (is_integer($slice))
			{
				$result = (int) $slice;
			}
			elseif (is_array($slice) && isset($slice[0], $slice[1]) && is_integer($slice[0]) && is_integer($slice[1]))
			{
				$result = [(int) $slice[0], (int) $slice[1]];
			}
			else
			{
				$this->error('Argument $slice must be specified in integer $X or in array [$X, $Y]', $method);
			}
		}

		return $result;
	}

	/**
	 *  Slice array.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/projection/slice/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->slice('comments', -5)->get('news');
	 *  @uses     $this->mongo_db->slice('comments', [20, 10])->get('news');
	 *  @uses     $this->mongo_db->slice(['comments' => 3, likes => [-15, 10]])->get('news');
	 *  
	 *  @param    string|array   $fields  <field> OR [<field1> => <slice>, <field2> => <slice>, ...]
	 *  @param    integer|array  $slice   <slice>
	 *
	 *  $slice types:
	 *  1) 10 (positive number) - returns first 10 elements of array;
	 *  2) -10 (negative number) - returns last 10 elements of array;
	 *  3) [3, 10] - skips first 3 elements and returns after next 10;
	 *  4) [-23, -10] - skips last 23 elements and returns previous 10;
	 *  5) same with [-x, y], [x, -y].
	 *  
	 *  @return  $this
	 */
	public function slice($fields, $slice = 0): self
	{
		if (isset($fields, $slice) && is_string($fields) && $fields != '')
		{
			$this->push_select_field($fields, ['$slice' => $this->get_slice_option($slice, __METHOD__)]);
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $slice)
			{
				if (is_string($field) && $field != '')
				{
					$this->push_select_field($field, ['$slice' => $this->get_slice_option($slice, __METHOD__)]);
				}
				else
				{
					$this->error('Each field name in list must not be an empty string', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}




	///////////////////////////////////////////////////////////////////////////
	//
	//  SORT operation.
	//
	///////////////////////////////////////////////////////////////////////////

	/**
	 *  Checks sorting type and adapts it to MongoDB.
	 *  
	 *  @param   mixed   $sort  [Sort type: ASC|DESC]
	 *  @return  integer|array
	 */
	private function get_sort_type($sort)
	{
		if (is_array($sort))
		{
			if (empty($sort))
			{
				$this->error('An array with sort parameters can not be empty!', __METHOD__);
			}

			$result = [];

			foreach ($sort as $key => $value)
			{
				$result[$key] = $this->get_sort_type($value);
			}

			return $result;
		}
		else
		{
			if ($sort == -1 || $sort === FALSE || strtolower($sort) == 'desc')
			{
				return -1;
			}
			else
			{
				return 1;
			}
		}
	}

	/**
	 *  Sorts list of documents by field.
	 *  
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->sort('_id', 'desc')->get('users');
	 *  @uses     $this->mongo_db->sort(['likes' => 'desc', '_id' => FALSE])->get('users');
	 *  
	 *  @param    string|array   $fields  <field> OR [<field1> => <sort>, <field2> => <sort>, ...]
	 *  @param    mixed          $sort    <sort>
	 *
	 *  $sort types:
	 *  1) DESC, -1 or FALSE - for descending sorting.
	 *  2) ASC, 1 or TRUE - for ascending sort.
	 *  
	 *  @return    $this
	 */
	public function sort($fields, $sort = 1): self
	{
		if (isset($fields, $sort) && is_string($fields) && $fields != '')
		{
			$this->push_sort_field($fields, $this->get_sort_type($sort));
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $sort)
			{
				if (is_string($field) && $field != '')
				{
					$this->push_sort_field($field, $this->get_sort_type($sort));
				}
				else
				{
					$this->error('Each field name in list must not be an empty string', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}




	///////////////////////////////////////////////////////////////////////////
	//
	//  LIMIT & OFFSET operations.
	//
	///////////////////////////////////////////////////////////////////////////

	/**
	 *  Limits query results.
	 *
	 *  @uses    $this->mongo_db->limit(15)->get('gamers');
	 *  
	 *  @param   integer  $limit  <number>
	 *  @return  $this
	 */
	public function limit(int $limit): self
	{
		if (!isset($limit))
		{
			$this->limit = self::DEFAULT_LIMIT;
		}
		else
		{
			if ($limit < 0)
			{
				$this->error('Limit can not be lower than 0', __METHOD__);
			}
			elseif ($limit == 0)
			{
				$this->limit = self::DEFAULT_LIMIT;
			}
			else
			{
				$this->limit = $limit;
			}
		}
		
		return $this;
	}

	/**
	 *  Skips specified amount of query results.
	 *
	 *  @uses    $this->mongo_db->offset(5)->get('collection');
	 *  
	 *  @param   integer  $offset  <number>
	 *  @return  $this
	 */
	public function offset(int $offset): self
	{
		if (!isset($offset))
		{
			$this->offset = 0;
		}
		else
		{
			if ($offset < 0)
			{
				$this->error('Offset can not be lower than 0', __METHOD__);
			}
			else
			{
				$this->offset = $offset;
			}
		}

		return $this;
	}




	///////////////////////////////////////////////////////////////////////////
	//
	//  DOCUMENT editor.
	//
	///////////////////////////////////////////////////////////////////////////

	/**
	 *  Converts document from format gotten from database to classical array.
	 *  
	 *  @param   stdClass  $document  [Document]
	 *  @return  array
	 */
	public function document_to_array(stdClass $document): array
	{
		$result = [];

		if (isset($document))
		{
			$document = $this->convert_document_id($document);

			$BSON = fromPHP($document);
			$result = json_decode(toJSON($BSON), TRUE);
		}

		return $result;
	}

	/**
	 *  Converts document from format gotten from database to classical object.
	 *  
	 *  @param   stdClass  $document  [Document]
	 *  @return  stdClass
	 */
	public function document_to_object(stdClass $document = NULL): stdClass
	{
		$result = [];

		if (isset($document))
		{
			$result = $this->convert_document_id($document);
		}

		return $result;
	}




	///////////////////////////////////////////////////////////////////////////
	//
	//  DOCUMENT ID editor.
	//
	///////////////////////////////////////////////////////////////////////////

	/**
	 *  Converts document ID and returns document back.
	 *  
	 *  @param   stdClass  $document  [Document]
	 *  @return  stdClass
	 */
	private function convert_document_id(stdClass $document): stdClass
	{
		if (isset($document) && property_exists($document, '_id') && $document->{'_id'} instanceof ObjectID)
		{
			$document->{'_id'} = $document->{'_id'}->__toString();
		}

		return $document;
	}

	/**
	 *  Generates new ID for document in format MongoDB\BSON\ObjectID.
	 *
	 *  @uses     $this->mongo_db->create_document_id();
	 *  
	 *  @param    mixed   $id  [Document ID]
	 *  @return   MongoDB\BSON\ObjectID
	 */
	public function create_document_id($id = NULL): ObjectID
	{
		if (isset($id))
		{
			return new ObjectId((string) $id);
		}
		else
		{
			return new ObjectId();
		}
	}

	/**
	 *  Returns document ID of $document.
	 *  
	 *  @param   array   $document  [Document]
	 *  @return  string
	 */
	public function get_document_id(array $document): string
	{
		$result = '';

		if (!empty($document) && isset($document['_id']))
		{
			$id = $document['_id'];
			
			if ($id instanceof ObjectID)
			{
				$result = $id->__toString();
			}
			elseif ($id != '')
			{
				$result = $id;
			}

		}

		return (string) $result;
	}




	///////////////////////////////////////////////////////////////////////////
	//
	//  GET & COUNT operations.
	//
	///////////////////////////////////////////////////////////////////////////

	/**
	 *  Gets documents from database.
	 *  
	 *  @param   string   $collection  [Collection name]
	 *  @param   array    $options     [Query options]
	 *  @param   bool     $get_count   [Do return number of found documents?]
	 *  @return  array|integer
	 */
	private function _get(string $collection = '', array $options = [], bool $get_count = FALSE, string $method = '') // : array | int
	{
		if ($collection == '')
		{
			$this->error('Specify collection name to which query will be applied', $method);
		}
		
		try
		{
			$read_concern    = new ReadConcern(self::READ_CONCERN_LEVEL);
			$read_preference = new ReadPreference(self::READ_PREFERENCE_MODE);

			$options += $this->options['get'] + [
				'projection'  => $this->selects,
				'sort'        => $this->sorts,
				'skip'        => $this->offset,
				'limit'       => $this->limit,
				'readConcern' => $read_concern
			];
			
			$query = new Query($this->wheres, $options);

			// Get documents list.
			$cursor = $this->db->executeQuery($this->db_name . '.' . $collection, $query, $read_preference);

			// Query reset.
			if ($this->auto_reset_query === TRUE)
			{
				$this->reset_query();
			}

			if ($get_count === TRUE)
			{
				$result = 0;
			}
			else
			{
				$result = [];
			}
			
			if ($cursor instanceof Cursor)
			{
				$array = $cursor->toArray();

				if ($get_count === TRUE)
				{
					$result = count($array);
				}
				elseif ($this->return_as == 'object')
				{
					foreach ($array as $document)
					{
						$result[] = $this->document_to_object($document);
					}
				}
				elseif ($this->return_as == 'array')
				{
					foreach ($array as $document)
					{
						$result[] = $this->document_to_array($document);
					}
				}
			}
			
			return $result;
		}
		catch (Exception $e)
		{
			$this->error("Failed to complete query to get data: {$e->getMessage()}", $method);
		}
	}

	/**
	 *  Initializes query to get one or more documents.
	 *
	 *  @see      http://php.net/manual/ru/mongodb-driver-query.construct.php (queryOptions) for $options
	 *
	 *  @uses     $this->mongo_db->where('user.name', 'Dmitriy')->get('users');
	 *  @uses     $this->mongo_db->where('user.name', 'Dmitriy')->where_beth('user.years', 18, 35)->get('users', ['awaitData' => TRUE]);
	 *  @uses     $this->mongo_db->where(['user.name' => 'Dmitriy', 'user.years' => $this->mongo_db->beth(18, 35)])->sort('_id', 'desc')->limit(10)->get('users', ['awaitData' => TRUE]);
	 *  
	 *  @param    string  $collection  [Collection name]
	 *  @param    array   $options     [Query options]
	 *  @return   array
	 */
	public function get(string $collection = '', array $options = []): array
	{
		return $this->_get($collection, $options, FALSE, __METHOD__);
	}

	/**
	 *  Initializes query with search condition to get one or more documents.
	 *
	 *  @see      http://php.net/manual/ru/mongodb-driver-query.construct.php (queryOptions) for $options
	 *  @see      where() method for $where
	 *
	 *  @uses     $this->mongo_db->getWhere('users', ['user.name' => 'Alexter']);
	 *  
	 *  @param    string  $collection  [Collection name]
	 *  @param    array   $where       [Search conditions]
	 *  @param    array   $options     [Query options]
	 *  @return   array
	 */
	public function getWhere(string $collection = '', array $where = [], array $options = []): array
	{
		return $this->where($where)->_get($collection, $options, FALSE, __METHOD__);
	}

	/**
	 *  Initializes query to get first document.
	 *
	 *  @see      http://php.net/manual/ru/mongodb-driver-query.construct.php (queryOptions) for $options
	 *
	 *  @uses     $this->mongo_db->where('phone.os', 'iOS')->sort('likes', -1)->getOne('phones');
	 *  
	 *  @param    string  $collection  [Collection name]
	 *  @param    array   $options     [Query options]
	 *  @return   array
	 */
	public function getOne(string $collection = '', array $options = []): array
	{
		return $this->_get($collection, ['limit' => 1] + $options, FALSE, __METHOD__);
	}

	/**
	 *  Initializes query with search condition to get first document.
	 *
	 *  @see      http://php.net/manual/ru/mongodb-driver-query.construct.php (queryOptions) for $options
	 *  @see      where() method for $where
	 *
	 *  @uses     $this->mongo_db->getOneWhere('news', ['stars' => ['$gt' => 10]]);
	 *  
	 *  @param    string  $collection  [Collection name]
	 *  @param    array   $options     [Query options]
	 *  @return   array
	 */
	public function getOneWhere(string $collection = '', array $where = [], array $options = []): array
	{
		return $this->where($where)->_get($collection, ['limit' => 1] + $options, FALSE, __METHOD__);
	}

	/**
	 *  Initializes query to get number of found documents.
	 *
	 *  @see      http://php.net/manual/ru/mongodb-driver-query.construct.php (queryOptions) for $options
	 *
	 *  @uses     $this->mongo_db->count('news');
	 *  @uses     $this->mongo_db->count('news', ['noCursorTimeout' => TRUE, 'offset' => 50]);
	 *  
	 *  @param    string  $collection  [Collection name]
	 *  @param    array   $options     [Query options]
	 *  @return   integer
	 */
	public function count(string $collection = '', array $options = []): int
	{
		return $this->_get($collection, $options, TRUE, __METHOD__);
	}




	///////////////////////////////////////////////////////////////////////////
	//
	//  INSERT, UPDATE & DELETE operations and exec.
	//
	///////////////////////////////////////////////////////////////////////////

	/**
	 *  Field operations.
	 *  @see https://docs.mongodb.com/manual/reference/operator/update-field/
	 */
	
	/**
	 *  $fields value + $number.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/update/inc/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->inc('phone.cost', -75.50)->where('phone-cost', 500)->update('cart');
	 *  @uses     $this->mongo_db->inc(['phone.cost' => 25, 'phone.priority' => -1])->update('cart');
	 *  
	 *  @param    string|array   $fields  <field> OR [<field1> => <number1>, <field2> => <number2>, ...]
	 *  @param    integer|float  $number  <number>
	 *
	 *  $number can be positive (for increment) or negative (for decrement).
	 *  
	 *  @return   $this
	 */
	public function inc($fields, float $number = 0): self
	{
		if (isset($fields, $number) && is_string($fields) && $fields != '')
		{
			$this->push_update_method('$inc', [$fields => (float) $number]);
		}
		elseif (is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $number)
			{
				if (is_string($field) && $field != '' && is_float($number))
				{
					$this->push_update_method('$inc', [$field => (float) $number]);
				}
				else
				{
					$this->error('Each field name in list must not be an empty string, value must be numeric', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  $fields value * $number.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/update/mul/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->mul('money', 2)->update('course');
	 *  @uses     $this->mongo_db->mul(['money' => 2, 'rubbles' => 0.9])->update('course');
	 *  
	 *  @param    string|array   $fields  <field> OR [<field1> => <number1>, <field2> => <number2>, ...]
	 *  @param    integer|float  $number  <number>
	 *  @return   $this
	 */
	public function mul($fields, float $number = 0): self
	{
		if (isset($fields, $number) && is_string($fields) && $fields != '')
		{
			$this->push_update_method('$mul', [$fields => (float) $number]);
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $number)
			{
				if (is_string($field) && $field != '')
				{
					$this->push_update_method('$mul', [$field => (float) $number]);
				}
				else
				{
					$this->error('Each field name in list must not be an empty string', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Changes initial field name to new.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/update/rename/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $olds
	 *
	 *  @uses     $this->mongo_db->rename('tags', 'tag_list')->update('news');
	 *  @uses     $this->mongo_db->rename(['tag_list' => 'tags', 'news.title' => 'news.head', 'popularity' => 'stars'])->update('news');
	 *  
	 *  @param    string|array  $olds  <field> OR [<field1> => <newName1>, <field2> => <newName2>, ...]
	 *  @param    string        $new   <newName>
	 *  @return   $this
	 */
	public function rename($olds, string $new = ''): self
	{
		if (isset($olds, $new) && is_string($olds) && $olds != '' && $new != '')
		{
			$this->push_update_method('$rename', [$olds => $new]);
		}
		elseif (isset($olds) && is_array($olds) && !empty($olds))
		{
			foreach ($olds as $old => $new)
			{
				if (is_string($old) && $old != '' && is_string($new) && $new != '')
				{
					$this->push_update_method('$rename', [$old => $new]);
				}
				else
				{
					$this->error('Old and new field name in list must be not an empty string', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Inserts data only while insert operation.
	 *  Method works only when [upsert => true], this option applied automatically.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/update/setOnInsert/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->setOnInsert('status.variants', ['stable', 'relase', 'alpha'])->update('statuses');
	 *  @uses     $this->mongo_db->setOnInsert(['status.code' => 'stable', 'status.priority' => 100])->update('statuses');
	 *  
	 *  @param    string|array   $fields  <field> OR [<field1> => <value1>, <field2> => <value2>, ...]
	 *  @param    mixed          $value   <value>
	 *  @return   $this
	 */
	public function setOnInsert($fields, $value = ''): self
	{
		if (isset($fields, $value) && is_string($fields) && $fields != '')
		{
			$this->push_update_method('$setOnInsert', [$fields => $value]);
			$this->options['update']['upsert'] = TRUE;
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $value)
			{
				if (is_string($field) && $field != '')
				{
					$this->push_update_method('$setOnInsert', [$field => $value]);
					$this->options['update']['upsert'] = TRUE;
				}
				else
				{
					$this->error('Each field name in list must not be an empty string', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Adds data in document.
	 *  
	 *  Use this method to implement query through positional $ update operator:
	 *  @see https://docs.mongodb.com/manual/reference/operator/update/positional/
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/update/set/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->set('title', 'Super news')->where('_id' => 999)->update('news');
	 *  @uses     $this->mongo_db->set(['tags' => ['tagA', 'tagB', 'tagC'], 'title' => 'Super news', 'rank' => 0])->updateAll('news');
	 *  
	 *  @param    string|array   $fields  <field> OR [<field1> => <value1>, <field2> => <value2>, ...]
	 *  @param    mixed          $value   <value>
	 *  @return   $this
	 */
	public function set($fields, $value = ''): self
	{
		if (isset($fields, $value) && is_string($fields) && $fields != '')
		{
			$this->push_update_method('$set', [$fields => $value]);
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $value)
			{
				if (is_string($field) && $field != '')
				{
					$this->push_update_method('$set', [$field => $value]);
				}
				else
				{
					$this->error('Each field name in list must not be an empty string', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Deletes one or more fields from document.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/update/unset/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->unset('phone.cost')->update('phones');
	 *  @uses     $this->mongo_db->unset(['city.code', 'city.attitude'])->update('cities');
	 *  
	 *  @param    string|array   $fields  <field> OR [<field1>, <field2>, ..., <fieldN>]
	 *  @return   $this
	 */
	public function unset($fields): self
	{
		if (isset($fields) && is_string($fields) && $fields != '')
		{
			$this->push_update_method('$unset', [$fields => 1]);
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field)
			{
				if (is_string($field) && $field != '')
				{
					$this->push_update_method('$unset', [$field => 1]);
				}
				else
				{
					$this->error('Each field name in list must not be an empty string', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('Specify 1 valid argument', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Decreases value to specified if initial is higher.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/update/min/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->min('count', 10)->update('cart');
	 *  @uses     $this->mongo_db->min(['count' => 50, 'price' => 999])->update('cart');
	 *  
	 *  @param    string|array    $fields  <field> OR [<field1> => <value1>, <field2> => <value2>, ...]
	 *  @param    integer|float   $number  <value>
	 *  @return   $this
	 */
	public function min($fields, float $number = 0): self
	{
		if (isset($fields, $number) && is_string($fields) && $fields != '')
		{
			$this->push_update_method('$min', [$fields => (float) $number]);
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $number)
			{
				if (is_string($field) && $field != '' && is_float($number))
				{
					$this->push_update_method('$min', [$field => (float) $number]);
				}
				else
				{
					$this->error('Each field name in list must not be an empty string, value must be numeric', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Increases value to specified if initial value is less.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/update/max/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->max('cost', 2000)->update('cart');
	 *  @uses     $this->mongo_db->max(['cost' => 2000, 'stars' => 15])->update('cart');
	 *  
	 *  @param    string|array    $fields  <field> OR [<field1> => <value1>, <field2> => <value2>, ...]
	 *  @param    integer|float   $number  <value>
	 *  @return   $this
	 */
	public function max($fields, float $number = 0): self
	{
		if (isset($fields, $number) && is_string($fields) && $fields != '')
		{
			$this->push_update_method('$max', [$fields => (float) $number]);
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $number)
			{
				if (is_string($field) && $field != '' && is_float($number))
				{
					$this->push_update_method('$max', [$field => (float) $number]);
				}
				else
				{
					$this->error('Each field name in list must not be an empty string, value must be numeric', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Specify current date in given format.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/update/currentDate/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->setCurrentDate('article.date', 'date')->updateAll('news');
	 *  @uses     $this->mongo_db->setCurrentDate(['article.date' => 'date', 'last_change' => 'timestamp'])->where('_id', 19)->update('news');
	 *  
	 *  @param    string|array   $fields  <field> OR [<field1> => <typeSpecification1>, <field2> => <typeSpecification2>, ...]
	 *  @param    string         $type    <typeSpecification>
	 *
	 *  typeSpecification:
	 *  1) 'timestamp' for Timestamp(1410996356, 1)
	 *  2) 'date' for ISODate("2013-10-02T01:11:18.965Z")
	 *  
	 *  @return    $this
	 */
	public function setCurrentDate($fields, string $type = ''): self
	{
		$type_variants = ['timestamp', 'date'];

		if (isset($fields, $type) && is_string($fields) && $fields != '' && in_array($type, $type_variants))
		{
			$this->push_update_method('$currentDate', [$fields => ['$type' => $type]]);
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $type)
			{
				if (is_string($field) && $field != '' && in_array($type, $type_variants))
				{
					$this->push_update_method('$currentDate', [$field => ['$type' => $type]]);
				}
				else
				{
					$this->error('Each field name in list must not be empty string, value must be \'timestamp\', or \'date\'', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}




	/**
	 *  Array operations.
	 *  @see https://docs.mongodb.com/manual/reference/operator/update-array/
	 */
	
	/**
	 *  $ operation.
	 *  
	 *  @see set() method.
	 */

	/**
	 *  Adds all elements of array $data to the end of source array.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/update/addToSet/#each-modifier
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->addToSetAll('tags', ['iphone', 'samsung', 'nokia'])->updateAll('phones');
	 *  @uses     $this->mongo_db->addToSetAll(['tags' => ['iphone', 'nokia'], 'authors' => ['Dima', 'Sasha']])->update('phones');
	 *  
	 *  @param    string|array   $fields  <field> OR [<field1> => [<value1>, ... , <valueN>], <field2> => [<value1>, ... , <valueN>], ...]
	 *  @param    array          $data    [<value1>, <value2>, ... , <valueN>]
	 *  @return   $this
	 */
	public function addToSetAll($fields, array $data = []): self
	{
		if (isset($fields, $data) && is_string($fields) && $fields != '' && !empty($data))
		{
			$this->push_update_method('$addToSet', [$fields => ['$each' => $data]]);
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $data)
			{
				if (is_string($field) && $field != '' && is_array($data) && !empty($data))
				{
					$this->push_update_method('$addToSet', [$fields => ['$each' => $data]]);
				}
				else
				{
					$this->error('Each field name in list must not be an empty string, value must be not an empty array', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Adds $data to the end of source array.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/update/addToSet/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->addToSet('days', 'friday')->update('dates');
	 *  @uses     $this->mongo_db->addToSet(['days' => 'friday', 'months' => ['december', 'november']])->update('dates');
	 *  
	 *  @param    string|array   $fields  <field> OR [<field1> => <value1>, <field2> => <value2>, ...]
	 *  @param    mixed          $data    <value>
	 *  @return   $this
	 */
	public function addToSet($fields, $data = ''): self
	{
		if (isset($fields, $data) && is_string($fields) && $fields != '')
		{
			$this->push_update_method('$addToSet', [$fields => $data]);
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $data)
			{
				if (is_string($field) && $field != '')
				{
					$this->push_update_method('$addToSet', [$fields => $data]);
				}
				else
				{
					$this->error('Each field name in list must not be an empty string', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Constructor for pop() and shift() methods.
	 *  
	 *  @param   string|array  $fields  <field>
	 *  @param   integer       $side    [1 - pop() - from end, -1 - shift() - from start]
	 *  @return  $this
	 */
	private function _pop($fields, int $side, string $method = ''): self
	{
		if (isset($fields, $side) && is_string($fields) && $fields != '')
		{
			$this->push_update_method('$pop', [$fields => (int) $side]);
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $side)
			{
				if (is_string($field) && $field != '' && is_integer($side))
				{
					$this->push_update_method('$pop', [$fields => (int) $side]);
				}
				else
				{
					$this->error('Each field name must not be empty string, values must not me integer', $method);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', $method);
		}
		
		return $this;
	}

	/**
	 *  Deletes last element from array.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/update/pop/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->pop('tags')->update('news');
	 *  @uses     $this->mongo_db->pop(['tags', 'comments'])->update('news');
	 *  
	 *  @param    string|array   $fields  <field> OR [<field1>, <field2>, ...]
	 *  @return   $this
	 */
	public function pop($fields = ''): self
	{
		return $this->_pop($fields, 1, __METHOD__);
	}

	/**
	 *  Deletes first element from array.
	 *  
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->shift('tags')->update('news');
	 *  @uses     $this->mongo_db->shift(['tags', 'comments'])->updateAll('news');
	 *  
	 *  @param    string|array   $fields  <field> OR [<field1>, <field2>, ...]
	 *  @return   $this
	 */
	public function shift($fields = ''): self
	{
		return $this->_pop($fields, -1, __METHOD__);
	}

	/**
	 *  Deletes all listed elements from array.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/update/pullAll/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->pullAll('fact', ['Jack', 'Ma', 'likes', 'girls', ':)'])->update('facts');
	 *  @uses     $this->mongo_db->pullAll(['fact' => ['I', 'love', 'my', 'mom'], 'array' => ['one', two]])->update('facts');
	 *  
	 *  @param    string|array   $fields  <field> OR [<field1> => [<value1>, ... , <valueN>], <field2> => [<value1>, ... , <valueN>], ...]
	 *  @param    array          $data    [<value1>, <value2>, ... , <valueN>]
	 *  @return   $this
	 */
	public function pullAll($fields, array $data = []): self
	{
		if (isset($fields, $data) && is_string($fields) && $fields != '' && !empty($data))
		{
			$this->push_update_method('$pullAll', [$fields => $data]);
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $data)
			{
				if (is_string($field) && $field != '' && is_array($data) && !empty($data))
				{
					$this->push_update_method('$pullAll', [$fields => $data]);
				}
				else
				{
					$this->error('Each field name in list must not be an empty string, value must be not an empty array', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Deletes all listed elements satisfying given conditions from array.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/update/pull/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->pull('vegetables', 'carrots')->update('fruitsCollection');
	 *  @uses     $this->mongo_db->pull(['fruits' => ['$in' => ['apples', 'oranges']], 'vegetables' => 'carrots'])->update('fruitsCollection');
	 *  
	 *  @param    string|array   $fields  <field> OR [<field1> => <value1|condition1>, <field2> => <value2|condition2>, ...]
	 *  @param    mixed          $value   <value|condition>
	 *  @return   $this
	 */
	public function pull($fields, $value = ''): self
	{
		if (isset($fields, $value) && is_string($fields) && $fields != '')
		{
			$this->push_update_method('$pull', [$fields => $value]);
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $value)
			{
				if (is_string($field) && $field != '')
				{
					$this->push_update_method('$pull', [$fields => $value]);
				}
				else
				{
					$this->error('Each field name in list must not be an empty string', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Prepare options for pushAll() method.
	 *  
	 *  @param   array   $options  [Options list: slice, sort, position]
	 *  @return  array
	 */
	private function get_push_options(array $options = []): array
	{
		$result = [];

		if (!empty($options))
		{
			foreach ($options as $option => $value)
			{
				if ($option == 'slice' && is_integer($value))
				{
					$result['$slice'] = (int) $value;
				}

				if ($option == 'sort')
				{
					$result['$sort'] = $this->get_sort_type($value);
				}

				if ($option == 'position' && is_integer($value))
				{
					$result['$position'] = (int) $value;
				}
			}
		}

		return $result;
	}

	/**
	 *  Adds all listed data into document.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/update/push/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $field
	 *
	 *  @uses     $this->mongo_db->pushAll('players', ['Dmitriy', 'Alex', 'John'], ['sort' => 'DESC'])->where('_id', 962134)->update('games');
	 *  
	 *  @param    string  $field    <field>
	 *  @param    array   $data     [<document1>, <document2>, ... , <documentN>]
	 *  @param    array   $options  <options>
	 *
	 *  $options array can take following arguments:
	 *  1) slice - limits array with given values, positive (from start), negative (from end).
	 *  2) sort - sorts array by chosen field. Look available values in sort() method description.
	 *  3) position - specifies place for data insertion in source array.
	 *  
	 *  @return    $this
	 */
	public function pushAll(string $field = '', array $data = [], array $options = []): self
	{
		if ($field != '')
		{
			$this->push_update_method('$push', [$field => ['$each' => $data] + $this->get_push_options($options)]);
		}
		else
		{
			$this->error('Field name must me be not empty string', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  Adds new value to source array.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/update/push/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $fields
	 *
	 *  @uses     $this->mongo_db->push('scores', 89)->update('games');
	 *  @uses     $this->mongo_db->push(['scores' => 89, 'players' => 6, 'type' => 'basketball'])->update('games');
	 *  
	 *  @param    string|array  $fields   <field> OR [<field1> => <value1>, <field2> => <value2>, ...]
	 *  @param    mixed         $value    <value>
	 *  @return   $this
	 */
	public function push($fields, $value = ''): self
	{
		if (isset($fields, $value) && is_string($fields) && $fields != '')
		{
			$this->push_update_method('$push', [$fields => $value]);
		}
		elseif (isset($fields) && is_array($fields) && !empty($fields))
		{
			foreach ($fields as $field => $value)
			{
				if (is_string($field) && $field != '')
				{
					$this->push_update_method('$push', [$fields => $value]);
				}
				else
				{
					$this->error('Each field name in list must not be an empty string', __METHOD__);
				}
			}
		}
		else
		{
			$this->error('No specified or valid arguments were given', __METHOD__);
		}
		
		return $this;
	}

	/**
	 *  $each operation.
	 *  
	 *  @see addToSetAll() & pushAll() methods.
	 */
	
	/**
	 *  $slice operation.
	 *  
	 *  @see pushAll() method.
	 */
	
	/**
	 *  $sort operation.
	 *  
	 *  @see pushAll() method.
	 */
	
	/**
	 *  $position operation.
	 *  
	 *  @see pushAll() method.
	 */




	/**
	 *  Bitwise operation.
	 *  @see https://docs.mongodb.com/manual/reference/operator/update-bitwise/
	 */
	
	/**
	 *  Bitwise operation $bit.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/update/bit/
	 *  @see      https://docs.mongodb.com/manual/core/document/#document-dot-notation for $field
	 *
	 *  @uses     $this->mongo_db->bit('score', 'and', 50)->update('scores');
	 *  
	 *  @param    string  $field   <field>
	 *  @param    string  $type    <and|or|xor>
	 *  @param    int     $number  <number>
	 *  @return   $this
	 */
	public function bit(string $field = '', string $type = '', int $number): self
	{
		$type_variants = ['and', 'or', 'xor'];

		if ($field != '' && in_array($type, $type_variants) && isset($number))
		{
			$this->push_update_method('$bit', [$field => [$type => (int) $number]]);
		}
		else
		{
			$this->error('Specify 3 arguments: field name, operation type (and, or, xor), integer value', __METHOD__);
		}
		
		return $this;
	}




	/**
	 *  Isolation operation.
	 *  @see https://docs.mongodb.com/manual/reference/operator/update-isolation/
	 */
	
	/**
	 *  Prevents a write operation that affects multiple documents from yielding 
	 *  to other reads or writes once the first document is written. By using 
	 *  the $isolated option, you can ensure that no client sees the changes 
	 *  until the operation completes or errors out.
	 *  
	 *  @see      https://docs.mongodb.com/manual/reference/operator/update/isolated/
	 *
	 *  @uses     $this->mongo_db->isolated()->max('scrore', 100)->updateAll('scores');
	 *  
	 *  @return   $this
	 */
	public function isolated(): self
	{
		return $this->push_where_field('$isolated', TRUE);
	}




	///////////////////////////////////////////////////////////////////////////
	//
	//  INSERT exec.
	//
	///////////////////////////////////////////////////////////////////////////

	/**
	 *  Adds new document into database.
	 *
	 *  @uses    $this->mongo_db->insert('players', ['player' => ['name' => 'Alex', 'years' => 18]]);
	 *  
	 *  @param   string  $collection  <collectionName>
	 *  @param   array   $document    <document>
	 *  @return  string  <insertId>
	 */
	public function insert(string $collection = '', array $document = []): string
	{
		if ($collection == '')
		{
			$this->error('Specify collection name to which query will be applied', __METHOD__);
		}
		
		try
		{
			$bulk_write = new BulkWrite(self::BULK_WRITE_OPTIONS);
			$write_concern = new WriteConcern(self::WRITE_CONCERN_MODE, self::WRITE_CONCERN_TIMEOUT, self::WRITE_CONCERN_JOURNAL);

			$result = '';

			$insert_id = $bulk_write->insert($document);
			$write = $this->db->executeBulkWrite($this->db_name . '.' . $collection, $bulk_write, $write_concern);

			// Query reset.
			if ($this->auto_reset_query === TRUE)
			{
				$this->reset_query();
			}
			
			if ($write instanceof WriteResult)
			{
				// Returns ID generated automatically only.
				if (isset($insert_id) && $insert_id instanceof ObjectID)
				{
					$result = $insert_id->__toString();
				}
				else
				{
					$result = $this->get_document_id($document);
				}
			}
			
			return $result;
		}
		catch (Exception $e)
		{
			$this->error("Failed insert query for document: {$e->getMessage()}", __METHOD__);
		}
	}

	/**
	 *  Adds multiple documents into database.
	 *
	 *  @uses    $this->mongo_db->insertAll('news', [['title' => 'Hi', 'stars' => 0, 'comments' => 0], ['title' => 'Hello', 'stars' => 3, 'comments' => 5]]);
	 *  
	 *  @param   string  $collection  <collectionName>
	 *  @param   array   $documents   [<document1>, <document2>, ... , <documentN>]
	 *  @return  array   [<insertId1> by <document1>, <insertId2> by <document2>, ..., <insertIdN> by <documentN>]
	 */
	public function insertAll(string $collection = '', array $documents = []): array
	{
		if ($collection == '' && !empty($documents))
		{
			$this->error('Specify collection name to which query will be applied and documents list', __METHOD__);
		}
		
		try
		{
			$bulk_write = new BulkWrite(self::BULK_WRITE_OPTIONS);
			$write_concern = new WriteConcern(self::WRITE_CONCERN_MODE, self::WRITE_CONCERN_TIMEOUT, self::WRITE_CONCERN_JOURNAL);

			$result = [];
			$inserts_ids = [];

			foreach ($documents as $document)
			{
				$insert_id = $bulk_write->insert($document);
				
				if (isset($insert_id) && $insert_id instanceof ObjectID)
				{
					$inserts_ids[] = $insert_id->__toString();
				}
				else
				{
					$inserts_ids[] = $this->get_document_id($document);
				}
			}
			
			$write = $this->db->executeBulkWrite($this->db_name . '.' . $collection, $bulk_write, $write_concern);
			
			// Query reset.
			if ($this->auto_reset_query === TRUE)
			{
				$this->reset_query();
			}
			
			if ($write instanceof WriteResult)
			{
				$result = $inserts_ids;
			}

			return $result;
		}
		catch (Exception $e)
		{
			$this->error("Failed to complete insert query for multiple documents: {$e->getMessage()}", __METHOD__);
		}
	}




	///////////////////////////////////////////////////////////////////////////
	//
	//  UPDATE exec.
	//
	///////////////////////////////////////////////////////////////////////////

	/**
	 *  Updates existing document.
	 *  
	 *  @param   string  $collection  <collectionName>
	 *  @param   array   $options     <options>
	 *  @return  boolean
	 */
	private function _update(string $collection = '', array $options = [], string $method = ''): bool
	{
		if ($collection == '')
		{
			$this->error('Specify collection name to which query will be applied', $method);
		}

		try
		{
			$options += $this->options['update'] + [
				'multi'  => FALSE,
				'upsert' => FALSE
			];

			$bulk_write = new BulkWrite(self::BULK_WRITE_OPTIONS);
			$write_concern = new WriteConcern(self::WRITE_CONCERN_MODE, self::WRITE_CONCERN_TIMEOUT, self::WRITE_CONCERN_JOURNAL);

			$bulk_write->update($this->wheres, $this->updates, $options);
			$result = $this->db->executeBulkWrite($this->db_name . '.' . $collection, $bulk_write, $write_concern);
			
			// Query reset.
			if ($this->auto_reset_query === TRUE)
			{
				$this->reset_query();
			}
			
			if ($result instanceof WriteResult)
			{
				return TRUE;
			}
			else
			{
				return FALSE;
			}
		}
		catch (Exception $e)
		{
			$this->error("Failed to complete update query for one or more documents: {$e->getMessage()}", $method);
		}
	}

	/**
	 *  Updates existing document.
	 *
	 *  @uses    $this->mongo_db->set('type', 'juice')->update('fruits', ['upsert' => TRUE]);
	 *  
	 *  @param   string  $collection  <collectionName>
	 *  @param   array   $options     <options>
	 *
	 *  $options can take following values:
	 *  1) 'multi' - default FALSE;
	 *  2) 'upsert' - default FALSE.
	 *  
	 *  @return  boolean
	 */
	public function update(string $collection = '', array $options = []): bool
	{
		return $this->_update($collection, $options, __METHOD__);
	}

	/**
	 *  Updates all found documents. Parameter 'multi' automatically applied
	 *  for documents update.
	 *
	 *  @uses    $this->mongo_db->set('type', 'juice')->updateAll('fruits');
	 *  
	 *  @param   string  $collection  <collectionName>
	 *  @param   array   $options     <options>
	 *
	 *  $options can take following values:
	 *  1) 'multi' - default TRUE;
	 *  2) 'upsert' - default FALSE.
	 *  
	 *  @return  boolean
	 */
	public function updateAll(string $collection = '', array $options = []): bool
	{
		return $this->_update($collection, ['multi' => TRUE] + $options, __METHOD__);
	}



	///////////////////////////////////////////////////////////////////////////
	//
	//  DELETE exec.
	//
	///////////////////////////////////////////////////////////////////////////
	
	/**
	 *  Deletes documents from collection.
	 *  
	 *  @param   string  $collection  <collectionName>
	 *  @param   array   $options     <options>
	 *
	 *  $options can take following values:
	 *  1) 'limit' - default TRUE;
	 *  
	 *  @return  boolean
	 */
	private function _delete(string $collection = '', array $options = [], string $method = ''): bool
	{
		if ($collection == '')
		{
			$this->error('Specify collection name to which query will be applied', $method);
		}
		
		try
		{
			$options += $this->options['delete'] + [
				'limit'  => TRUE
			];

			$bulk_write = new BulkWrite(self::BULK_WRITE_OPTIONS);
			$write_concern = new WriteConcern(self::WRITE_CONCERN_MODE, self::WRITE_CONCERN_TIMEOUT, self::WRITE_CONCERN_JOURNAL);

			$bulk_write->delete($this->wheres, $options);
			$result = $this->db->executeBulkWrite($this->db_name . '.' . $collection, $bulk_write, $write_concern);
			
			// Query reset.
			if ($this->auto_reset_query === TRUE)
			{
				$this->reset_query();
			}

			if ($result instanceof WriteResult)
			{
				return TRUE;
			}
			else
			{
				return FALSE;
			}
		}
		catch (Exception $e)
		{
			$this->error("Failed to complete delete query for one or more documents: {$e->getMessage()}", $method);
		}
	}

	/**
	 *  Deletes first found document from collection.
	 *
	 *  @uses    $this->mongo_db->where('_id', 2953)->delete('news');
	 *  
	 *  @param   string  $collection  <collectionName>
	 *  @param   array   $options     <options>
	 *
	 *  $options can take following values:
	 *  1) 'limit' - default TRUE;
	 *  
	 *  @return  boolean
	 */
	public function delete(string $collection = '', array $options = []): bool
	{
		return $this->_delete($collection, $options, __METHOD__);
	}

	/**
	 *  Deletes all found documents from collection.
	 *
	 *  @uses    $this->mongo_db->where('deleted', TRUE)->deleteAll('news');
	 *  
	 *  @param   string  $collection  <collectionName>
	 *  @param   array   $options     <options>
	 *
	 *  $options can take following values:
	 *  1) 'limit' - default FALSE;
	 *  
	 *  @return  boolean
	 */
	public function deleteAll(string $collection = '', array $options = []): bool
	{
		return $this->_delete($collection, ['limit' => FALSE] + $options, __METHOD__);
	}




	///////////////////////////////////////////////////////////////////////////
	//
	//  COMMANDS & AGGREGATION exec.
	//
	///////////////////////////////////////////////////////////////////////////
	
	/**
	 *  Executes commands and aggregations MongoDB.
	 *  
	 *  @param   array   $command  <command>
	 *  @param   string  $method   Initiator method name
	 *  @return  array
	 */
	private function _command(array $command = [], string $method = ''): array
	{
		if (empty($command))
		{
			$this->error('Specify command', $method);
		}
		
		try
		{
			$read_preference = new ReadPreference(self::READ_PREFERENCE_MODE);
			$command = new Command($command);

			$cursor = $this->db->executeCommand($this->db_name, $command, $read_preference);

			$result = [];

			if ($cursor instanceof Cursor)
			{
				$array = $cursor->toArray();

				if ($this->return_as == 'object')
				{
					foreach ($array as $document)
					{
						$result[] = $this->document_to_object($document);
					}
				}
				elseif ($this->return_as == 'array')
				{
					foreach ($array as $document)
					{
						$result[] = $this->document_to_array($document);
					}
				}
			}

			return $result;
		}
		catch (Exception $e)
		{
			$this->error("Failed to complete following command: {$e->getMessage()}", $method);
		}
	}

	/**
	 *  Command in MongoDB.
	 *
	 *  @see     https://docs.mongodb.com/manual/reference/command/
	 *
	 *  @uses    $this->mongo_db->command(['serverStatus' => TRUE, 'rangeDeleter' => TRUE]);
	 *  
	 *  @param   array   $command  <command>
	 *  @return  array
	 */
	public function command(array $command = []): array
	{
		return $this->_command($command, __METHOD__);
	}

	/**
	 *  Aggregation in MongoDB.
	 *
	 *  @see     https://docs.mongodb.com/manual/reference/operator/aggregation/
	 *
	 *  @uses    $this->mongo_db->aggregate('orders', [
	 *           	[
	 *           		'$project' => ['cusip' => 1, 'date' => 1, 'price' => 1, '_id' => 0 ]
	 *           	]
	 *           ], ['allowDiskUse' => TRUE]);
	 *
	 *  NOTE: if you are using MongoDB version 3.6 or later, be sure to specify 
	 *  the `cursors` option in the `$options`.
	 *  
	 *  @param   string  $collection  <collectionName>
	 *  @param   array   $pipeline    Aggregation query
	 *  @param   array   $options     Aggregation options
	 *  @return  array
	 */
	public function aggregate(string $collection = '', array $pipeline = [], array $options = []): array
	{
		if ($collection == '')
		{
			$this->error('Specify collection name to which query will be applied', __METHOD__);
		}

		if (empty($pipeline))
		{
			$this->error('Specify query for aggregation', __METHOD__);
		}

		$command = [
			'aggregate' => $collection,
			'pipeline'  => $pipeline
		] + $options;

		return $this->_command($command, __METHOD__);
	}




	///////////////////////////////////////////////////////////////////////////
	//
	//  DATE operations.
	//
	///////////////////////////////////////////////////////////////////////////

	/**
	 *  Create date by MongoDB\BSON\UTCDatetime.
	 *
	 *  @uses    ['date' => $this->mongo_db->date()];
	 *  @uses    ['datetime' => $this->mongo_db->date(time() * 1000)];
	 *  
	 *  @param   int     $time  [Timestamp in milliseconds]
	 *  @return  UTCDatetime
	 */
	public function date(int $time = 0): UTCDatetime
	{
		if (isset($time) && $time != 0)
		{
			return new UTCDatetime( (int) $time );
		}
		else
		{
			return new UTCDatetime( time() * 1000 );
		}
	}

	/**
	 *  Create date by MongoDB\BSON\Timestamp.
	 *
	 *  @uses    ['time' => $this->mongo_db->timestamp()];
	 *  @uses    ['timestamp' => $this->mongo_db->timestamp(time())];
	 *  
	 *  @param   int     $time  [Timestamp in seconds]
	 *  @param   int     $inc   [Integer denoting the incrementing ordinal for operations within a given second]
	 *  @return  Timestamp
	 */
	public function timestamp(int $time = 0, int $inc = 0): Timestamp
	{
		if (isset($time) && $time != 0)
		{
			return new Timestamp($inc, $time);
		}
		else
		{
			return new Timestamp($inc, time());
		}
	}




	///////////////////////////////////////////////////////////////////////////
	//
	//  OTHER DB operations.
	//
	///////////////////////////////////////////////////////////////////////////

	/**
	 *  Changes database. Works if parameter 'connection_string' in config is not presented.
	 *
	 *  @uses    $this->mongo_db->switch_db('MySuperProject');
	 *  
	 *  @param   string  $database  [New name for database]
	 *  @return  MongoDB\Driver\Manager
	 */
	public function switch_db(string $database = ''): Manager
	{
		if ($database == '')
		{
			$this->error('To switch MongoDB databases, a new database name must be specified', __METHOD__);
		}
		
		return $this->reconnect(['config' => array_replace_recursive($this->config, ['connection' => ['db_name' => $database]])]);
	}

	/**
	 *  Resets last completed query.
	 *  
	 *  If 'auto_reset_query' is FALSE, you can change or add 
	 *  previous queries to database for better flexibility.
	 *  
	 *  @return  $this
	 */
	public function reset_query(): self
	{
		$this->selects = [];
		$this->updates = [];
		$this->wheres  = [];
		$this->limit   = self::DEFAULT_LIMIT;
		$this->offset  = 0;
		$this->sorts   = [];

		return $this;
	}




	///////////////////////////////////////////////////////////////////////////
	//
	//  CONNECTION CREATE methods.
	//
	///////////////////////////////////////////////////////////////////////////

	/**
	 *  Set MongoDB configuration by priority:
	 *  1) Manual config data (has high priority).
	 *  2) Config data from file (has middle priority).
	 *  3) Default config data, that have been installed as default value for parameters in this class (lowest priority).
	 *  
	 *  @param   array   $manual_config  [Manual config data]
	 *  @return  $this
	 */
	private function config(array $manual_config = []): self
	{
		// Config data from file /config/mongo_db.php.
		$this->CodeIgniter->load->config(self::CONFIG_FILE_NAME);
		$file_config = $this->CodeIgniter->config->item(self::CONFIG_ARRAY_NAME);

		// Config group name: one of some variants of a config group name.
		if (!empty($manual_config) && isset($manual_config['config_group']) && $manual_config['config_group'] != '')
		{
			$this->config_group = $manual_config['config_group']; // 1st priority.
		}
		elseif (isset($file_config['active_config_group']) && $file_config['active_config_group'] != '')
		{
			$this->config_group = $file_config['active_config_group']; // 2nd priority.
		}

		// Config data: more priority data supplement or replace less priority.
		if (isset($file_config[$this->config_group]) && !empty($file_config[$this->config_group]))
		{
			$this->config = array_replace_recursive($this->config, $file_config[$this->config_group]); // 2nd priority.
		}

		if (!empty($manual_config) && isset($manual_config['config']) && !empty($manual_config['config']))
		{
			$this->config = array_replace_recursive($this->config, $manual_config['config']); // 1st priority.
		}

		// port & host to array
		if (is_string($this->config['connection']['host']))
		{
			$this->config['connection']['host'] = [$this->config['connection']['host']];
		}

		if (is_string($this->config['connection']['port']))
		{
			$this->config['connection']['port'] = [$this->config['connection']['port']];
		}

		if (empty($this->config))
		{
			$this->error('An error occurred while final configuration! Make sure config file exists and all parameters are valid. If during library connection or connection with connect() or reconnect() methods you pass any parameters make sure they are valid.');
		}

		return $this;
	}

	/**
	 *  Parse parameter 'connection_string' and config 
	 *  file with settings for database connection.
	 *  
	 *  @return  $this
	 */
	private function prepare(): self
	{
		// Or 'connection_string', or 'connection': 'connection_string' replaces all data in 'connection'.
		if (isset($this->config['connection_string']) && $this->config['connection_string'] != '')
		{
			$this->config['connection'] = $this->parse_connection_string($this->config['connection_string']);
		}

		$settings   = $this->config['settings'];
		$connection = $this->config['connection'];
		$driver     = $this->config['driver'];

		/**
		 *  Settings.
		 */
		// Authentication status. Default: TRUE.
		if (isset($settings['auth']) && is_bool($settings['auth']))
		{
			$this->auth = $settings['auth'];
		}

		// Debug mode. Default: FALSE.
		if (isset($settings['debug']) && is_bool($settings['debug']))
		{
			$this->debug = $settings['debug'];
		}

		// Returnable type of data, when use get() and similar functions. Default: 'array'.
		if (isset($settings['return_as']) && is_string($settings['return_as']) && $settings['return_as'] != '')
		{
			$this->return_as = $settings['return_as'];
		}

		// Auto query resetting. Default: TRUE.
		if (isset($settings['auto_reset_query']) && is_bool($settings['auto_reset_query']))
		{
			$this->auto_reset_query = $settings['auto_reset_query'];
		}

		/**
		 *  Connection.
		 */
		// Hosts (required).
		if (isset($connection['host']) && is_array($connection['host']) && !empty($connection['host']))
		{
			$this->hosts = $connection['host'];
		}
		else
		{
			$this->error('Connection host is a required parameter. Type it as string or array!');
		}

		// Ports.
		if (isset($connection['port']) && is_array($connection['port']) && !empty($connection['port']))
		{
			$this->ports = $connection['port'];
		}
		else
		{
			// This parameter can not be empty, because config constructor 
			// creates an association between each host and port.
		}

		// User name.
		if (isset($connection['user_name']) && is_string($connection['user_name']) && $connection['user_name'] != '')
		{
			$this->user_name = trim($connection['user_name']);
		}
		elseif ($this->auth === TRUE)
		{
			$this->error('User name is a required parameter. Type it!');
		}

		// User password.
		if (isset($connection['user_password']) && is_string($connection['user_password']) && $connection['user_password'] != '')
		{
			$this->user_password = trim($connection['user_password']);
		}
		elseif ($this->auth === TRUE)
		{
			$this->error('User password is a required parameter. Type it!');
		}

		// Database name.
		if (isset($connection['db_name']) && is_string($connection['db_name']) && $connection['db_name'] != '')
		{
			$this->db_name = trim($connection['db_name']);
		}
		elseif ($this->auth === TRUE)
		{
			$this->error('Database name is a required parameter. Type it!');
		}

		// Database options.
		if (isset($connection['db_options']) && is_array($connection['db_options']) && !empty($connection['db_options']))
		{
			$this->db_options = $connection['db_options'];
		}

		/**
		 *  Driver.
		 */
		if (is_array($driver) && !empty($driver))
		{
			$this->driver_options = $driver;
		}
	
		return $this;
	}

	/**
	 *  Parse parameter 'connection_string'.
	 *
	 *  @see  https://docs.mongodb.com/manual/reference/connection-string/
	 *  
	 *  @param   string  $connection  [Connection string to DB]
	 *  @return  array
	 */
	private function parse_connection_string(string $connection = ''): array
	{
		// Connection string.
		$connection = trim($connection);
		$connection = str_replace($this->protocol, '', $connection);

		// Initialize variables.
		$hosts         = [];
		$ports         = [];
		$user_name     = '';
		$user_password = '';
		$db_name       = '';
		$db_options    = [];

		// Extra options.
		$sockets          = [];
		$db_options_group = [];
		$host_group       = [];

		/**
		 *  Username and password.
		 */
		// Prepare $connection to $connection and $user_name if user name exist.
		if (strpos($connection, '@') !== FALSE)
		{
			list($user_name, $connection) = explode('@', $connection);

			// Prepare $user_name to $user_name and $user_password if password exist.
			if (strpos($user_name, ':') !== FALSE)
			{
				list($user_name, $user_password) = explode(':', $user_name);
			}
		}

		// Find and crop UNIX Domain Sockets.
		if (preg_match_all('#\/.*\.sock,?#iU', $connection, $matches))
		{
			$unix_domain_sockets = $matches[0];

			if (!empty($unix_domain_sockets))
			{
				foreach ($unix_domain_sockets as $id => $socket)
				{
					// Save socket.
					$sockets[$id] = trim($socket);

					// Replace socket address to socket ID.
					$connection = str_replace($socket, $id, $connection);
				}
			}
		}

		/**
		 *  Database name and options.
		 */
		// Prepare $connection to $connection and $db_name if database name exist.
		if (strpos($connection, '/') !== FALSE)
		{
			list($connection, $db_name) = explode('/', $connection);

			// Prepare $db_name to $db_name and $db_options if options exist.
			if (strpos($db_name, '?') !== FALSE)
			{
				list($db_name, $db_options) = explode('?', $db_name);

				// Check the availability of multiple options.
				if (strpos($db_options, '&') !== FALSE)
				{
					$db_options_group = explode('&', $db_options);
				}
				else
				{
					$db_options_group[] = $db_options;
				}

				$db_options = [];

				// Options list.
				foreach ($db_options_group as $option)
				{
					// Skip options without value.
					if (strpos($option, '=') === FALSE)
					{
						continue;
					}

					// Prepare option to $key and $value.
					list($key, $value) = explode('=', $option);

					// Save option.
					$db_options[$key] = $value;
				}
			}
		}

		/**
		 *  Hosts and ports list.
		 */
		// Hostname & port.
		if (strpos($connection, ',') !== FALSE)
		{
			$host_group = explode(',', $connection);
		}
		else
		{
			$host_group[] = $connection;
		}

		// Hosts list.
		foreach ($host_group as $host)
		{
			$host = trim($host);

			if ($host == '')
			{
				continue;
			}

			if (isset($sockets[$host]) && $sockets[$host] != '')
			{
				$hosts[] = $sockets[$host];
				$ports[] = '';
			}
			else
			{
				// Skip options without value.
				if (strpos($host, ':') === FALSE)
				{
					$ports[] = '';
				}
				else
				{
					// Prepare option to $host and $port.
					list($host, $port) = explode(':', $host);

					if ($host == '')
					{
						continue;
					}

					// Save option.
					$ports[] = trim($port);
				}

				$hosts[] = $host;
			}
		}

		return [
			'host'          => $hosts,
			'port'          => $ports,
			'user_name'     => $user_name,
			'user_password' => $user_password,
			'db_name'       => $db_name,
			'db_options'    => $db_options
		];
	}

	/**
	 *  Creates connection string for connection to database.
	 *  
	 *  @return  string
	 */
	private function create_connection_string(): string
	{
		/**
		 *  Connection protocol
		 *  String: 'mongodb://'.
		 */
		$result = $this->protocol;

		/**
		 *  Username and password.
		 *  String: 'user:password@'.
		 */
		if ($this->auth === TRUE && $this->user_name != '')
		{
			$result .= $this->user_name;

			if ($this->user_password != '')
			{
				$result .= ":{$this->user_password}";
			}

			$result .= '@';
		}

		/**
		 *  Hosts and ports list.
		 *  String: 'localhost:port[,localhostN:portN]'.
		 */
		if (!empty($this->hosts) && !empty($this->ports))
		{
			$hosts_length = count($this->hosts);
			$comma = '';

			for ($i = 0; $i < $hosts_length; $i++)
			{
				if (isset($this->hosts[$i]) && $this->hosts[$i] != '')
				{
					$result .= "{$comma}{$this->hosts[$i]}";

					if (isset($this->ports[$i]) && $this->ports[$i] != '')
					{
						$result .= ":{$this->ports[$i]}";
					}

					$comma = ',';
				}
			}
		}

		/**
		 *  Database name.
		 *  String: '/database'.
		 */
		if ($this->auth === TRUE && $this->db_name != '')
		{
			$result .= "/{$this->db_name}";
		}

		return $result;
	}




	///////////////////////////////////////////////////////////////////////////
	//
	//  OTHER methods.
	//
	///////////////////////////////////////////////////////////////////////////
	
	/**
	 *  It pull the first element of the array.
	 *  
	 *  @param   array    $array  Get query result.
	 *  @param   integer  $n      Array row number.
	 *  @return  array
	 */
	public function row_array(array $array = [], float $n = 0): array
	{
		$n = (integer) $n;
		
		$result = [];

		if (!empty($array) && array_key_exists($n, $array))
		{
			$result = $array[$n];
		}

		return $result;
	}

	/**
	 *  @param   string  $field  <field>
	 *  @return  $this
	 */
	private function create_where_field(string $field = ''): self
	{
		if ($field != '' && !array_key_exists($field, $this->wheres))
		{
			$this->wheres[$field] = [];
		}

		return $this;
	}

	/**
	 *  Add parameters to filter when selecting data.
	 *  
	 *  @param   string  $field  <field|operator>
	 *  @param   mixed   $data   [Data]
	 *  @return  $this
	 */
	private function push_where_field(string $field = '', $data): self
	{
		if ($field != '' && isset($data))
		{
			// Create field.
			$this->create_where_field($field);

			// Set data.
			if (is_array($data))
			{
				$this->wheres[$field] = array_replace_recursive($this->wheres[$field], $data);
			}
			else
			{
				$this->wheres[$field] = $data;
			}
		}

		return $this;
	}

	// ------------------------------------------------------------------------

	/**
	 *  Add new parameters to update data.
	 *  
	 *  @param   string  $operator  <operator>
	 *  @param   mixed   $data      [Data]
	 *  @return  $this
	 */
	private function push_update_method(string $operator = '', $data): self
	{
		if ($operator != '' && isset($data))
		{
			if (!array_key_exists($operator, $this->updates))
			{
				$this->updates[$operator] = [];
			}

			if (is_array($data))
			{
				$this->updates[$operator] = array_replace_recursive($this->updates[$operator], $data);
			}
			else
			{
				$this->updates[$operator] = $data;
			}
		}

		return $this;
	}

	// ------------------------------------------------------------------------

	/**
	 *  Add new parameters to select data.
	 *  
	 *  @param   string  $field  <field|column>
	 *  @param   mixed   $data   [Data]
	 *  @return  $this
	 */
	private function push_select_field(string $field = '', $data): self
	{
		if ($field != '' && isset($data))
		{
			if (!array_key_exists($field, $this->selects))
			{
				$this->selects[$field] = [];
			}

			if (is_array($data))
			{
				$this->selects[$field] = array_replace_recursive($this->selects[$field], $data);
			}
			else
			{
				$this->selects[$field] = $data;
			}
		}

		return $this;
	}

	// ------------------------------------------------------------------------

	/**
	 *  Add new parameters to sort data.
	 *  
	 *  @param   string  $field  <field>
	 *  @param   mixed   $data   [Data]
	 *  @return  $this
	 */
	private function push_sort_field(string $field = '', $data): self
	{
		if ($field != '' && isset($data))
		{
			if (!array_key_exists($field, $this->sorts))
			{
				$this->sorts[$field] = [];
			}

			if (is_array($data))
			{
				$this->sorts[$field] = array_replace_recursive($this->sorts[$field], $data);
			}
			else
			{
				$this->sorts[$field] = $data;
			}
		}

		return $this;
	}




	///////////////////////////////////////////////////////////////////////////
	//
	//  ERROR handler.
	//
	///////////////////////////////////////////////////////////////////////////

	/**
	 *  Error handler.
	 *  
	 *  @param   string   $text  [Error message]
	 *  @param   integer  $code  [Error code]
	 *  @return  $this
	 */
	private function error(string $text = '', string $method = '', int $code = 500): self
	{
		// Log errors only during debug.
		if ($this->debug === TRUE) 
		{
			$message = $text;

			// Show method where error occurred.
			if ($method != '')
			{
				$message = "{$method}(): $message";
			}

			show_error($message, $code);
		}
	}
}

/* End of file Mongo_db.php */
/* Location: ./application/libraries/Mongo_db.php */