<?php if (!defined('BASEPATH')) exit('No direct script access allowed');
/**
 *  ------------------------------------------------------------------------
 *  
 *  Config file for Open Source CodeIgniter MongoDB Library (ALL-IN-ONE).
 *  
 *  @author   Dmitriy Verkhoumov <verkhoumov@yandex.ru> <https://github.com/verkhoumov>
 *  @since    14.10.2016
 *  @license  MIT License
 *  
 *  @version  1.0.3
 *  @link     https://github.com/verkhoumov/codeigniter-mongodb-library
 *
 *  ------------------------------------------------------------------------
 *
 *  NOTE: I express my gratitude to Alexander (@link https://github.com/Alexter-progs) for the translation into English.
 *
 *  ------------------------------------------------------------------------
 *  
 *  EXAMPLE:
 *  
 *  // Connection settings for #example group.
 *	$config['mongo_db']['example'] = [
 *		'settings' => [
 *			// @var  boolean  Will made the authorization?
 *			// Default: TRUE
 *			'auth' => FALSE,
 *
 *			// @var  boolean  Show debug info when working with DB?
 *			// Default: TRUE
 *			'debug' => TRUE,
 *
 *			// @var  string  Type of query result: array or object.
 *			// Default: array
 *			'return_as' => 'object',
 *
 *			// @var  boolean  Automatic reset of constructed query after it's execution.
 *			// Default: TRUE
 *			'auto_reset_query' => TRUE
 *		],
 *
 *		// @var   string  Connection string
 *		//
 *		// @uses  'mongodb://db1.example.net,db2.example.net:2500/?replicaSet=test&connectTimeoutMS=300000'
 *		//
 *		// @see   https://docs.mongodb.com/manual/reference/connection-string/
 *		'connection_string' => '',
 *
 *		# NOTE: You can use either 'connection_string' or 'connection'.
 *		
 *		'connection' => [
 *			// @var   string|array
 *			//
 *			// @uses  'db1.example.net'
 *			// @uses  '/tmp/mongodb-27017.sock'
 *			// @uses  ['db1.example.net', 'db2.example.net', '/tmp/mongodb-27017.sock']
 *			'host' => 'db1.example.net',
 *
 *			// @var   string|array
 *			//
 *			// @uses  '2500'
 *			// @uses  ['2500', '8000', '']
 *			'port' => '',
 *
 *			// The settings used when the parameter 'auth'
 *			// set to TRUE.
 *			//
 *			// @var  string  User name and password.
 *			'user_name'     => '',
 *			'user_password' => '',
 *
 *			// If the 'auth' is set to TRUE, then 'db_name' is a must.
 *			//
 *			// @var  string  DB name.
 *			'db_name' => 'test',
 *
 *			// @var  array  Options to connect to the database.
 *			'db_options' => []
 *		],
 *
 *		// @var  array  MongoDB driver options.
 *		// @see  http://php.net/manual/en/mongodb-driver-manager.construct.php#mongodb-driver-manager.construct-driveroptions
 *		'driver' => []
 *	];
 *
 *  ------------------------------------------------------------------------
 *
 *  NOTE: If desired, you can type all or only some of the parameters when connecting the library, use connect() or reconnect() methods.
 *  
 *  @example   $this->load->library('mongo_db', [
 *             		'config_group' => '', // For example 'default'.
 *             		
 *             		'config' => [
 *             			'setting' => [
 *             				'auth'             => '',
 *             				'debug'            => '',
 *             				'return_as'        => '',
 *             				'auto_reset_query' => ''
 *             			],
 *             			
 *             			'connection_string' => '',
 *             			
 *             			'connection' => [
 *             				'host'          => '',
 *             				'port'          => '',
 *             				'user_name'     => '',
 *             				'user_password' => '',
 *             				'db_name'       => '',
 *             				'db_options'    => []
 *             			],
 *             			
 *             			'driver' => []
 *             		]
 *             ])
 *
 *  ------------------------------------------------------------------------
 */

/**
 *  Group name for active connection.
 *  If empty, will be activated group #default.
 */
$config['mongo_db']['active_config_group'] = 'default';

/**
 *  Connection settings for #default group.
 */
$config['mongo_db']['default'] = [
	'settings' => [
		'auth'             => TRUE,
		'debug'            => TRUE,
		'return_as'        => 'array',
		'auto_reset_query' => TRUE
	],

	'connection_string' => '',

	'connection' => [
		'host'          => '',
		'port'          => '',
		'user_name'     => '',
		'user_password' => '',
		'db_name'       => '',
		'db_options'    => []
	],

	'driver' => []
];

/* End of file mongo_db.php */
/* Location: ./application/config/mongo_db.php */