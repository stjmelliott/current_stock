<?php

// $Id: sts_ifta_log_class.php 5642 2026-02-02 21:43:27Z dev $
// IFTA and KeepTruckin API

// no direct access
defined('_STS_INCLUDE') or die('Restricted access');

require_once( "sts_tractor_class.php" );
require_once( "sts_driver_class.php" );

class sts_ifta_log extends sts_table {
	public $setting_table;
	public $audit;
	protected $ifta_log;
	protected $ifta_diag_level;	// Text
	protected $diag_level;		// numeric version
	private $ifta_base_jurisdiction;
	protected $message = "";

	// Constructor does not need the table name
	public function __construct( $database, $debug = false ) {
		global $sts_error_level_label, $sts_crm_dir;

		$this->debug = $debug;
		$this->primary_key = "IFTA_LOG_CODE";
		$this->database = $database;
		$this->setting_table = sts_setting::getInstance($database, $debug);
		$this->ifta_log =				$this->setting_table->get( 'api', 'IFTA_LOG_FILE' );
		if( isset($this->ifta_log) && $this->ifta_log <> '' && 
			$this->ifta_log[0] <> '/' && $this->ifta_log[0] <> '\\' 
			&& $this->ifta_log[1] <> ':' )
			$this->ifta_log = $sts_crm_dir.$this->ifta_log;

		$this->ifta_diag_level =		$this->setting_table->get( 'api', 'IFTA_DIAG_LEVEL' );
		$this->diag_level =  array_search(strtolower($this->ifta_diag_level), $sts_error_level_label);
		if( $this->diag_level === false ) $this->diag_level = EXT_ERROR_ALL;

		$this->ifta_base_jurisdiction =	 $this->setting_table->get( 'api', 'IFTA_BASE_JURISDICTION' );
		
		//! link to audit log
		$this->audit = sts_ift_log_audit::getInstance($database, $debug);

		$myclass = get_class ();
		if( $debug ) echo "<p>Create $myclass</p>";
		parent::__construct( $database, IFTA_LOG_TABLE, $debug);
	}
	
	// Allow re-use of objects - singleton function
	public static function getInstance( $database, $debug = false ) {
		static $instance = null;
		$myclass = get_class ();
		if( $debug ) echo "<p>Get instance of $myclass</p>";
		if (null === $instance) {
			$instance = new $myclass( $database, $debug );
		}
		return $instance;
    }

	public function log_event( $message, $level = EXT_ERROR_ERROR ) {
		//if( $this->debug ) echo "<p>log_event: $this->ifta_log, $message, this->diag_level = $this->diag_level level=$level</p>";
		//file_put_contents($this->ifta_log, date('m/d/Y h:i:s A')." pid=".getmypid()." ".__METHOD__.": $this->ifta_log, $message, this->diag_level = $this->diag_level level=$level\n", FILE_APPEND);
		$this->message = $message;
		if( $this->diag_level >= $level ) {
			if( (file_exists($this->ifta_log) && is_writable($this->ifta_log)) ||
				is_writable(dirname($this->ifta_log)) ) 
				file_put_contents($this->ifta_log, date('m/d/Y h:i:s A')." pid=".getmypid().
					" msg=".$message."\n\n", (file_exists($this->ifta_log) ? FILE_APPEND : 0) );
		}
	}
	
	public function getMessage() {
		return $this->message;
	}

}

//! SCR# 993 - Audit log for IFTA log
class sts_ift_log_audit extends sts_table {
	private $map_name = [
		'IFTA_JURISDICTION'	=> 'state',
		'IFTA_DATE'			=> 'date',
		'ODOMETER_IN'		=> 'ODO_in',
		'ODOMETER_OUT'		=> 'ODO_out',
		'DIST_TRAVELED'		=> 'dist',
		'CD_ORIGIN'			=> 'source',
		'DIST_NON_TAXABLE'	=> 'non_tax',
		'FUEL_PURCHASED'	=> 'fuel',
		'CD_STOP_NAME'		=> 'stop',
		'CD_STOP_CITY'		=> 'city'
	];

	// Constructor does not need the table name
	public function __construct( $database, $debug = false ) {

		$this->debug = $debug;
		$this->primary_key = "IFTA_LOG_AUDIT_CODE";
		if( $this->debug ) echo "<p>Create sts_ift_log_audit</p>";
		parent::__construct( $database, IFTA_LOG_AUDIT_TABLE, $debug);
	}
	
	// Allow re-use of objects - singleton function
	public static function getInstance( $database, $debug = false ) {
		static $instance = null;
		$myclass = get_class ();
		if( $debug ) echo "<p>Get instance of $myclass</p>";
		if (null === $instance) {
			$instance = new $myclass( $database, $debug );
		}
		return $instance;
    }	

	private function log( $log_code, $tractor, $event, $comment = "" ) {
		if( $this->debug ) echo "<p>".__METHOD__.": $tractor, $event, $comment</p>";
		$result = false;
		if( ! empty($comment) ) {
			$values = [
				'IFTA_LOG_CODE' => $log_code,
				'IFTA_TRACTOR' => $tractor,
				'AUDIT_EVENT' => $event,
				'AUDIT_COMMENTS' => $comment
			];
			$result = $this->add( $values );
		}
		return $result;
	}
	
	private function map( $key, $value ) {
		$result = '';
		if( isset($this->map_name[$key]) )
			$result  = $this->map_name[$key].'='.$value;
		return $result;
	}
	
	private function build_comment( $new, $previous = false ) {
	//	echo "<pre>";
	//	var_dump($new);
	//	var_dump($previous);
	//	echo "</pre>";
		
		$comment = '';
		$fields = [];
		if( ! $previous ) {
			$changed = $new;
		} else {
			$keys1 =[];
			foreach($new as $key => $value) {
				if( !empty($value) && $value != 'NULL' )
					$keys1[] = $key;
			}
			
			$keys2 = [];
			foreach($previous as $key => $value) {
				if( !empty($value) && $value != NULL && $value != 'NULL' )
					$keys2[] = $key;
			}
			
	//	echo "<pre>";
	//	var_dump($keys1);
	//	var_dump($keys2);
	//	echo "</pre>";

			foreach($keys1 as $key) {
				if( ! in_array($key, $keys2) )
					$keys2[] = $key;
			}
			// $keys2 contains a list of all keys
			asort($keys2);
			
			$changed = [];
			
			foreach( $keys2 as $key ) {
				if( ! empty($new[$key]) &&
					((! empty($previous[$key]) && $new[$key] != $previous[$key]) ||
					(! isset($previous[$key]))) ) {
					$changed[$key] = $new[$key];
				}
			}
		}
		
	//	echo "<pre>";
	//	var_dump($changed);
	//	echo "</pre>";
		
		$changes = [];		
		foreach( $changed as $key => $value ) {
			$str = $this->map($key, $value);
			if( ! empty($str) )
				$changes[] = $str;
		}
		
		$comment = implode(' ', $changes);
		
		return $comment;
	}
	
	public function log_import( $log_code, $tractor, $new ) {
		return $this->log( $log_code, $tractor, 'import',
			$this->build_comment( $new ) );
	}

	public function log_manual( $log_code, $tractor, $new, $previous ) {
		return $this->log( $log_code, $tractor, 'manual',
			$this->build_comment( $new, $previous ) );
	}
	
	public function log_create( $log_code, $tractor, $new ) {
		return $this->log( $log_code, $tractor, 'manual',
			$this->build_comment( $new ) );
	}

	// Fetch one or more rows
	public function fetch_rows( $match = "", $fields = "*", $order = "", $limit = "", $groupby = "", $match2 = "" ) {
		if( $this->debug ) echo "<p>fetch_rows $match</p>";
		
		$result = $this->database->get_multiple_rows("SELECT $fields FROM
			(SELECT L.CD_ORIGIN, L.IFTA_TRACTOR, A.IFTA_LOG_AUDIT_CODE,
			COALESCE(A.CREATED_DATE, L.CREATED_DATE ) AS CREATED_DATE,
			A.AUDIT_EVENT,
			COALESCE(A.AUDIT_COMMENTS, 'No audit data') AS AUDIT_COMMENTS,
			COALESCE(A.CREATED_BY, L.CREATED_BY) AS CREATED_BY
			FROM EXP_IFTA_LOG L
			LEFT JOIN EXP_IFTA_LOG_AUDIT A
			ON L.IFTA_LOG_CODE = A.IFTA_LOG_CODE
			
			".($match <> "" ? "WHERE $match" : "")."
			".($order <> "" ? "order by $order" : "")."
			".($groupby <> "" ? "group by $groupby" : "")."
			".($limit <> "" ? "limit $limit" : "")."
			
			) EXP_IFTA_LOG_AUDIT");

		if( $this->debug ) {
			echo "<p>result for $this->table_name = </p>
			<pre>";
			var_dump($result);
			echo "</pre>";
		}
		return $result;
	}
	
}

class sts_carrier_log extends sts_table {
	public $setting_table;
	private $ifta_base_jurisdiction;
	protected $message = "";

	// Constructor does not need the table name
	public function __construct( $database, $debug = false ) {
		global $sts_error_level_label, $sts_crm_dir;

		$this->debug = $debug;
		$this->primary_key = "CARRIER_LOG_CODE";
		$this->database = $database;
		$this->setting_table = sts_setting::getInstance($database, $debug);

		$this->ifta_base_jurisdiction =	 $this->setting_table->get( 'api', 'IFTA_BASE_JURISDICTION' );

		$myclass = get_class ();
		if( $debug ) echo "<p>Create $myclass</p>";
		parent::__construct( $database, CARRIER_LOG_TABLE, $debug);
	}
	
	// Allow re-use of objects - singleton function
	public static function getInstance( $database, $debug = false ) {
		static $instance = null;
		$myclass = get_class ();
		if( $debug ) echo "<p>Get instance of $myclass</p>";
		if (null === $instance) {
			$instance = new $myclass( $database, $debug );
		}
		return $instance;
    }
}

class sts_ifta_log_calc extends sts_ifta_log {

	// Constructor does not need the table name
	public function __construct( $database, $debug = false ) {

		$myclass = get_class ();
		if( $debug ) echo "<p>Create $myclass</p>";
		parent::__construct( $database, $debug);
	}

	// Allow re-use of objects - singleton function
	public static function getInstance( $database, $debug = false ) {
		static $instance = null;
		$myclass = get_class ();
		if( $debug ) echo "<p>Get instance of $myclass</p>";
		if (null === $instance) {
			$instance = new $myclass( $database, $debug );
		}
		return $instance;
    }

	// Fetch one or more rows
	public function fetch_rows( $match = "", $fields = "*", $order = "", $limit = "", $groupby = "", $match2 = "" ) {
		if( $this->debug ) echo "<p>fetch_rows $match</p>";
		
		$result = $this->database->multi_query("
set @mpg = (SELECT round(sum(l.DIST_TRAVELED) / sum(l.FUEL_PURCHASED), 2) AS MPG
			from EXP_IFTA_LOG l
			where ".($match <> "" ? $match : "").");
			
SELECT x.*, 
			    round(@mpg,2) as MPG,
				round(FUEL_TAXABLE * COALESCE(IFTA_RATE, 0.0), 2) AS IFTA_TAX,
			 	round(FUEL_TAXABLE * COALESCE(IFTA_SURCHARGE,0.0), 2) AS IFTA_SC,
			    round(FUEL_TAXABLE * (COALESCE(IFTA_RATE, 0.0) + COALESCE(IFTA_SURCHARGE,0.0)), 2) AS TOTAL_TAX
			
			FROM
(select FUEL_TYPE, IFTA_JURISDICTION, DIST_TRAVELED,
				DIST_NON_TAXABLE, DIST_TAXABLE, FUEL_PURCHASED,
				IFTA_RATE,
				IFTA_SURCHARGE,
			    round(DIST_TAXABLE / @mpg) AS FUEL_USED,
			    round(DIST_TAXABLE / @mpg) - FUEL_PURCHASED AS FUEL_TAXABLE

			from
(SELECT FUEL_TYPE, IFTA_JURISDICTION, IFTA_RATE, IFTA_SURCHARGE,
sum(DIST_TRAVELED) as DIST_TRAVELED,
sum(DIST_NON_TAXABLE) as DIST_NON_TAXABLE, 
sum(DIST_TAXABLE) as DIST_TAXABLE,
sum(FUEL_PURCHASED) as FUEL_PURCHASED

FROM (SELECT l.IFTA_DATE, t.FUEL_TYPE, l.IFTA_JURISDICTION,
			sum(l.DIST_TRAVELED) as DIST_TRAVELED ,
			sum(l.DIST_NON_TAXABLE) as DIST_NON_TAXABLE, 
			sum(l.DIST_TRAVELED) - sum(l.DIST_NON_TAXABLE) AS DIST_TAXABLE,
			sum(l.FUEL_PURCHASED) as FUEL_PURCHASED,
			ifta_rate(l.IFTA_DATE, t.FUEL_TYPE, l.IFTA_JURISDICTION, 'USD', 0) as IFTA_RATE,
            ifta_rate(l.IFTA_DATE, t.FUEL_TYPE, l.IFTA_JURISDICTION, 'USD', 1) as IFTA_SURCHARGE
            
			from EXP_IFTA_LOG l, EXP_TRACTOR t
			where ".($match <> "" ? $match : "")."
			AND l.IFTA_TRACTOR = t.TRACTOR_CODE
			
			group by l.IFTA_DATE, t.FUEL_TYPE, l.IFTA_JURISDICTION) log

GROUP BY FUEL_TYPE, IFTA_JURISDICTION, IFTA_RATE, IFTA_SURCHARGE) r ) x
			
			".($order <> "" ? "order by $order" : "")."
			".($groupby <> "" ? "group by $groupby" : "")."
			".($limit <> "" ? "limit $limit" : "") );
			
		if( $this->debug ) {
			echo "<p>result for $this->table_name = </p>
			<pre>";
			var_dump($result);
			echo "</pre>";
		}
		return $result;
	}
}

//! This class works with the API at http://developer.keeptruckin.com

class sts_keeptruckin extends sts_ifta_log {
	private $timezone;
	private $api_key;
	private $trips_url = 'https://api.gomotive.com/v1/ifta/trips';
	public $errnum;
	public $errmsg;
	//! SCR# 569 - OAuth 2.0
	public $client_id;
	public $client_secret;
	public $redirect_uri = 'https://thefutureexperience.com/exp_handle_kt.php';
	private $token_uri = 'https://api.gomotive.com/oauth/token';
	public $token_type;
	public $access_token;
	public $refresh_token;
	private $oauth2_scopes = 'ifta_reports.trips companies.read vehicles.manage users.read inspection_reports.read hos_logs.hos_violation hos_logs.logs hos_logs.available_time';

	private $admin_users_url = 'https://api.gomotive.com/v1/users?status=active&role=admin';
	private $companies_url = 'https://api.gomotive.com/v1/companies';
	private $active_drivers_url = 'https://api.gomotive.com/v1/users?status=active&role=driver';
	private $vehicles_url = 'https://api.gomotive.com/v1/vehicles';
	private $lookup_driver_url = 'https://api.gomotive.com/v1/users/lookup?driver_company_id=';
	private $available_time_url = 'https://api.gomotive.com/v1/available_time';
	private $isactive;
	private $oos_type;
	private $vehicle_id;
	private $driver_id;
	
	// Constructor does not need the table name
	public function __construct( $database, $debug = false ) {
		$myclass = get_class ();
		if( $debug ) echo "<p>Create $myclass</p>";
		parent::__construct( $database, $debug);
		$this->timezone = $this->map_timezone();
		$this->api_key = $this->setting_table->get( 'api', 'KEEPTRUCKIN_KEY' );
		
		//! SCR# 569 - OAuth 2.0
		$this->client_id = $this->setting_table->get( 'api', 'KEEPTRUCKIN_CLIENT_ID' );
		$this->client_secret = $this->setting_table->get( 'api', 'KEEPTRUCKIN_SECRET' );
		$this->refresh_token = $this->setting_table->get( 'api', 'KEEPTRUCKIN_REFRESHTOKEN' );
	}
	
	// Allow re-use of objects - singleton function
	public static function getInstance( $database, $debug = false ) {
		static $instance = null;
		$myclass = get_class ();
		if( $debug ) echo "<p>Get instance of $myclass</p>";
		if (null === $instance) {
			$instance = new $myclass( $database, $debug );
		}
		return $instance;
    }
    
    public function is_enabled() {
	    return isset($this->api_key) && ! empty($this->api_key);
    }
    
    public function last_error() {
	    return "Error: ".(isset($this->errnum) ? $this->errnum." " : "").$this->errmsg;
    }

	// Based upon the $sts_crm_timezone variable, it sets the timezone in
	// the format needed for http://developer.keeptruckin.com/#time-zone
	private function map_timezone() {
		global $sts_crm_timezone;
		
		switch( $sts_crm_timezone ) {
			// Eastern
			case 'America/New_York':
				$kt_timezone = 'Eastern Time (US & Canada)';
				break;
			
			// Central
			case 'America/Chicago':
				$kt_timezone = 'Central Time (US & Canada)';
				break;
			
			// Mountain
			case 'America/Denver':
				$kt_timezone = 'Mountain Time (US & Canada)';
				break;
			
			// Mountain no DST
			case 'America/Phoenix':
				$kt_timezone = 'Arizona';
				break;
			
			// Pacific
			case 'America/Los_Angeles':
				$kt_timezone = 'Pacific Time (US & Canada)';
				break;
			
			// Alaska
			case 'America/Anchorage':
			default:
				$kt_timezone = 'Alaska';
				break;
			
		}
		return $kt_timezone;
	}

	//! Send a delete
	public function curl_del($url, $old_method = false, $userid = false)
	{
		if( $this->debug ) echo "<p>".__METHOD__.": url = $url</p>";
		$this->log_event( __METHOD__.": url = $url", EXT_ERROR_DEBUG);
	    $ch = curl_init();
	    curl_setopt($ch, CURLOPT_URL, $url);
	    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");

		if( $old_method )
			$headers = array(
				'X-Api-Key: '.$this->api_key,
				'X-Time-Zone: '.$this->timezone,
				'X-Metric-Units: false'
			);
		else
			$headers = array(
				'Authorization: '.$this->token_type." ".$this->access_token,
				'X-Time-Zone: '.$this->timezone,
				'X-Metric-Units: false'
			);

		if( $userid )
			$headers[] = 'X-User-Id: '.$userid;
		
		if( $this->debug ) {
			echo "<pre>".__METHOD__.": headers\n";
			var_dump($headers);
			echo "</pre>";
		}
		$this->log_event( __METHOD__.": headers=".
			print_r($headers, true) , EXT_ERROR_DEBUG);

	    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	    //curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
	    //curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	    $result = curl_exec($ch);
	    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if( $this->debug ) {
			echo "<pre>".__METHOD__.": code, result\n";
			var_dump($httpCode, $result);
			echo "</pre>";
		}
	    
	    $result = json_decode($result, true);
	    curl_close($ch);
	
		$this->log_event( __METHOD__.": code=$httpCode result=".
			print_r($result, true) , EXT_ERROR_DEBUG);
	    return $result;
	}

	//! SCR# 1065 - Send a PUT method. -------------------------------------
	public function put( $url, $fields, $auth = true, $userid = false ) {
		if( $this->debug ) echo "<p>".__METHOD__.": url = $url key = $this->api_key</p>";
	//	$this->log_event( __METHOD__.": url = $url", EXT_ERROR_DEBUG);
		$data = false;
		//! SCR# 569 - OAuth 2.0 - use fetch_token() to get new token
		if( $this->is_enabled() && $this->fetch_token() ) {
			$headers = array(
				'X-Time-Zone: '.$this->timezone,
				'Accept: application/json',
				'Content-type: application/json',
				'Authorization: '.$this->token_type.' '.$this->access_token,
			);
			
			if( $userid )
				$headers[] = 'X-User-Id: '.$userid;
			
			$this->log_event( __METHOD__.": url = ".$url, EXT_ERROR_DEBUG);
			$this->log_event( __METHOD__.": headers = ".print_r($headers, true), EXT_ERROR_DEBUG);
			$this->log_event( __METHOD__.": body = ".$fields, EXT_ERROR_DEBUG);
			
			if( $this->debug ) {
				echo "<pre>".__METHOD__.": headers, url, fields\n";
				var_dump($headers,$url, json_encode(json_decode($fields, true), JSON_PRETTY_PRINT));
				echo "</pre>";
			}
			
		    $ch = curl_init();
		    curl_setopt($ch, CURLOPT_URL, $url);
		//	curl_setopt($ch, CURLOPT_PUT, true);	// <--- doesn't work
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
			curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
			curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
			
		    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		    
			// Receive server response ...
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			
			$data = curl_exec($ch);
			
		//	$info = curl_getinfo($ch);
		//	$this->log_event( __METHOD__.": curl_getinfo = ".print_r($info, true), EXT_ERROR_DEBUG);
					
		    $http_response_header = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$this->log_event( __METHOD__.": result = ".print_r($data, true), EXT_ERROR_DEBUG);
			$this->log_event( __METHOD__.": http_response_header = ".print_r($http_response_header, true), EXT_ERROR_DEBUG);
			
			if( $this->debug ) {
				echo "<pre>".__METHOD__.": response_header, data\n";
				var_dump($http_response_header, json_encode(json_decode($data, true), JSON_PRETTY_PRINT) );
				echo "</pre>";
			}
			

			
			curl_close ($ch);
			
		} else {
			$this->log_event( __METHOD__.": FAILED, either not enabled or fetch_token() failed." );
		}
		
		return $data;
	}

	//! SCR# 1065 - Send a GET method. -------------------------------------
	public function get( $url, $userid = false ) {
		if( $this->debug ) echo "<p>".__METHOD__.": url = $url key = $this->api_key</p>";
	//	$this->log_event( __METHOD__.": url = $url", EXT_ERROR_DEBUG);
		$data = false;
		//! SCR# 569 - OAuth 2.0 - use fetch_token() to get new token
		if( $this->is_enabled() && $this->fetch_token() ) {
			$headers = array(
				'X-Time-Zone: '.$this->timezone,
				'Accept: application/json',
				'Content-type: application/json',
				'Authorization: '.$this->token_type.' '.$this->access_token,
			);
			
			if( $userid )
				$headers[] = 'X-User-Id: '.$userid;
			
			$this->log_event( __METHOD__.": url = ".$url, EXT_ERROR_DEBUG);
			$this->log_event( __METHOD__.": headers = ".print_r($headers, true), EXT_ERROR_DEBUG);
			
		    $ch = curl_init();
		    curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
			$body = '{}';
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
			curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
			
		    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_HEADER, 0);
			
		    // Timeout in seconds
		    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		    
			// Receive server response ...
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			
			$data = curl_exec($ch);
								
		    $http_response_header = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$this->log_event( __METHOD__.": result = ".print_r($data, true), EXT_ERROR_DEBUG);
			$this->log_event( __METHOD__.": http_response_header = ".print_r($http_response_header, true), EXT_ERROR_DEBUG);
			
			if( $this->debug ) {
				echo "<pre>".__METHOD__.": response_header, data\n";
				var_dump($http_response_header, json_encode(json_decode($data, true), JSON_PRETTY_PRINT) );
				echo "</pre>";
			}
			
			curl_close ($ch);
			
		} else {
			$this->log_event( __METHOD__.": FAILED, either not enabled or fetch_token() failed." );
		}
		
		return $data;
	}

	//! Send a POST method.
	// I don't think this is in use, but updated to use CURL
	public function post( $url, $auth = true, $userid = false ) {
		if( $this->debug ) echo "<p>".__METHOD__.": url = $url key = $this->api_key</p>";
		$this->log_event( __METHOD__.": url = $url", EXT_ERROR_DEBUG);
		$data = false;
		//! SCR# 569 - OAuth 2.0 - use fetch_token() to get new token
		if( $this->is_enabled() && $this->fetch_token() ) {
			$headers = array(
				'X-Time-Zone: '.$this->timezone,
				'Accept: application/json',
				'X-Metric-Units: false'
			);
			
			if( $auth )
			//	$headers[] = 'Authorization: '.$this->token_type.' '.$this->access_token;
				$headers[] = 'X-Api-Key: '.$this->api_key;

			if( $userid )
				$headers[] = 'X-User-Id: '.$userid;
			
			$this->log_event( __METHOD__.": headers = ".print_r($headers, true), EXT_ERROR_DEBUG);
			if( $this->debug ) {
				echo "<pre>".__METHOD__.": headers, url\n";
				var_dump($headers,$url);
				echo "</pre>";
			}
		    $ch = curl_init();
		    curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_POST, true);
			//curl_setopt($ch, CURLOPT_POSTFIELDS, 
			//	http_build_query($data));
			curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
			
		    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		    //curl_setopt($ch, CURLOPT_HEADER, false);
		    
			// Receive server response ...
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			
			$data = curl_exec($ch);
			
		//	$info = curl_getinfo($ch);
		//	$this->log_event( __METHOD__.": curl_getinfo = ".print_r($info, true), EXT_ERROR_DEBUG);
					
		    $http_response_header = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$this->log_event( __METHOD__.": result = ".print_r($data, true), EXT_ERROR_DEBUG);
			$this->log_event( __METHOD__.": http_response_header = ".print_r($http_response_header, true), EXT_ERROR_DEBUG);
			
			curl_close ($ch);
			
		} else {
			$this->log_event( __METHOD__.": FAILED, either not enabled or fetch_token() failed." );
		}
		
		return $data;
	}

	//! Fetch data from API, handle paging, combine results.
	public function fetch( $url, $old_method = false ) {
		if( $this->debug ) echo "<p>".__METHOD__.": url = $url old_method=".($old_method ? "true":"false")."</p>";
		$this->log_event( __METHOD__.": url = $url old_method=".($old_method ? "true":"false"), EXT_ERROR_DEBUG);
		$content = false;
		//! SCR# 569 - OAuth 2.0 - use fetch_token() to get new token
		if( $this->is_enabled() && ($old_method || $this->fetch_token()) ) {
			$per_page = 25;
			$page_no = 1;
			if( $old_method )
				$headers = array(
					'X-Api-Key: '.$this->api_key,
					'X-Time-Zone: '.$this->timezone
				);
			else
				$headers = array(
					'Authorization: '.$this->token_type.' '.$this->access_token,
					'X-Time-Zone: '.$this->timezone,
					'Accept: application/json'
				);
			
			if( $this->debug ) {
				echo "<pre>".__METHOD__.": headers\n";
				var_dump($headers);
				echo "</pre>";
			}

			$this->log_event( __METHOD__.": url = ".$url, EXT_ERROR_DEBUG);
			$this->log_event( __METHOD__.": headers = ".print_r($headers, true), EXT_ERROR_DEBUG);

			do {
				$page_url = $url.(strpos($url, '?') ? '&' : '?').
					'per_page='.$per_page.'&page_no='.$page_no;
					
			    $ch = curl_init();
			    curl_setopt($ch, CURLOPT_URL, $page_url);
				curl_setopt($ch, CURLOPT_POST, false);
			//	curl_setopt($ch, CURLOPT_POSTFIELDS, 
			//		http_build_query($data));
				curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
				
			    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			//    curl_setopt($ch, CURLOPT_HEADER, false);
			    
				// Receive server response ...
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				
				$data = curl_exec($ch);
				
			//	$info = curl_getinfo($ch);
			//	$this->log_event( __METHOD__.": curl_getinfo = ".print_r($info, true), EXT_ERROR_DEBUG);
						
			    $http_response_header = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				$this->log_event( __METHOD__.": result = ".print_r($data, true), EXT_ERROR_DEBUG);
				$this->log_event( __METHOD__.": http_response_header = ".print_r($http_response_header, true), EXT_ERROR_DEBUG);
				
				curl_close ($ch);
								
				if( $data ) {
					$info = json_decode($data, true);
					if( $this->debug ) {
						echo "<pre>".__METHOD__.": Result:\n";
						var_dump($page_url, $info, $this->errnum, $this->errmsg);
						echo "</pre>";
					}
					$keys = array_keys($info);
					$content_key = $keys[0];
					if( $content_key == "error_message" ) {
						$this->log_event( __METHOD__.": Error: ".$info["error_message"], EXT_ERROR_WARNING);
						$this->errmsg = $info["error_message"];
						$content = false;
					} else if(is_array($info) && is_array($info[$content_key]) ) {
						if( is_array($content) ) {
							$content = array_merge($content, $info[$content_key]);
						} else {
							$content = $info[$content_key];
						}
					} else { //! Exception, log error
						require_once( "sts_email_class.php" );
						$this->log_event( __METHOD__.": Exception - NOT an array: ". print_r($info, true));
						$email = sts_email::getInstance($this->database, $this->debug);
						$email->send_alert( __METHOD__.": Exception<br>".
							"content = <pre>".print_r($content, true)."</pre><br>".
							"info = <pre>".print_r($info, true)."</pre><br>".
							"URL = <pre>".print_r($url, true)."</pre>" );
					}
					
					// Check the pagination to see if there is another page
					$another_page = false;
					if( is_array($info) && isset($info["pagination"]) &&
						is_array($info["pagination"]) &&
						isset($info["pagination"]["per_page"]) &&
						isset($info["pagination"]["page_no"]) &&
						isset($info["pagination"]["total"]) ) {
						$per_page = $info["pagination"]["per_page"];
						$page_no = $info["pagination"]["page_no"];
						$total = $info["pagination"]["total"];
						if( $this->debug ) echo "<p>".__METHOD__.": per_page = $per_page, page_no = $page_no, total = $total</p>";
						if( $total > $per_page * $page_no ) {
							$page_no++;				// To get next page
							$another_page = true;	// Go round again
						}
					} else if(is_array($info) &&
						isset($info["per_page"]) &&
						isset($info["page_no"]) &&
						isset($info["total"])) {
						$per_page = $info["per_page"];
						$page_no = $info["page_no"];
						$total = $info["total"];
						if( $this->debug ) echo "<p>".__METHOD__.": per_page = $per_page, page_no = $page_no, total = $total</p>";
						if( $total > $per_page * $page_no ) {
							$page_no++;				// To get next page
							$another_page = true;	// Go round again
						}
						
					}
					
				} else {
					if( $this->debug ) {
						echo "<p>".__METHOD__.": Error: $this->errnum $this->errmsg</p>";
						$this->log_event( __METHOD__.": Error: $this->errnum $this->errmsg", EXT_ERROR_WARNING);
					}
					$content = false;
				}
			} while( $another_page );
		} else {
			if( $this->debug ) echo "<p>".__METHOD__.": not enabled or failed to get token</p>";
		}
		$this->log_event( __METHOD__.": returned ".(is_array($content) ? count($content) : 'no')." records", EXT_ERROR_DEBUG);
		return $content;
	}
	

	//! SCR# 569 - OAuth 2.0 - Get Authorization URL
	public function authorization_url() {
		global $sts_crm_root;
		
		$this->log_event( __METHOD__.": sts_crm_root = ".$sts_crm_root, EXT_ERROR_DEBUG );
		
		if( preg_match('#://(.*)\.exspeedite\.net#', $sts_crm_root, $matches) ) {
			$client = $matches[1];
		} else {
			$client = 'dev';
		}
		
		$result = 'https://api.gomotive.com/oauth/authorize?client_id='.$this->client_id.
			'&redirect_uri='.$this->redirect_uri.
			'&response_type=code&scope='.urlencode($this->oauth2_scopes).
			'&state='.$client;
	
		$this->log_event( __METHOD__.": return ".$result, EXT_ERROR_DEBUG);

		return $result;
	}


	//! SCR# 569 - OAuth 2.0 - Forget Authorization
	public function forget() {
		$this->log_event( __METHOD__.": forgotten.", EXT_ERROR_DEBUG);

		$this->setting_table->set( 'api', 'KEEPTRUCKIN_TOKENTYPE', '' );
		$this->setting_table->set( 'api', 'KEEPTRUCKIN_ACCESSTOKEN', '' );
		$this->setting_table->set( 'api', 'KEEPTRUCKIN_REFRESHTOKEN', '' );
		$this->setting_table->set( 'api', 'KEEPTRUCKIN_AUTHCODE', '' );
		$this->token_type = '';
		$this->access_token = '';
		$this->refresh_token = '';
	}
	
	//! SCR# 569 - OAuth 2.0 - Fetch Access token
	public function fetch_token( $authorization_code = false ) {
		$result = false;
		if( $this->debug ) echo "<p>".__METHOD__.": authorization_code = ".($authorization_code ? $authorization_code : 'false')."</p>";
		$this->log_event( __METHOD__.":  authorization_code = ".($authorization_code ? $authorization_code : 'false'), EXT_ERROR_DEBUG);
		
		if( $authorization_code ) {
			// Store a copy of the authorization code
			$this->setting_table->set( 'api', 'KEEPTRUCKIN_AUTHCODE', $authorization_code );
			
			$data = array(
				'grant_type' => 'authorization_code',
				'code' => $authorization_code,
				'redirect_uri' => $this->redirect_uri,
				'client_id' => $this->client_id,
				'client_secret' => $this->client_secret
			);
		} else {
			$this->refresh_token = $this->setting_table->get( 'api', 'KEEPTRUCKIN_REFRESHTOKEN' );

			$data = array(
				'grant_type' => 'refresh_token',
				'refresh_token' => $this->refresh_token,
				'redirect_uri' => $this->redirect_uri,
				'client_id' => $this->client_id,
				'client_secret' => $this->client_secret
			);
		}
		
		$this->log_event( __METHOD__.": data = ".print_r($data, true), EXT_ERROR_DEBUG);
		$this->log_event( __METHOD__.": data2 = ".print_r(http_build_query($data), true), EXT_ERROR_DEBUG);

	    $ch = curl_init();
	    curl_setopt($ch, CURLOPT_URL, $this->token_uri);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, 
			http_build_query($data));
		curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
		
		$header = ["Content-Type: application/x-www-form-urlencoded\r\n"
			];
		
	//    curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
	    curl_setopt($ch, CURLOPT_HEADER, false);
	    
		// Receive server response ...
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		
		$result = curl_exec($ch);
		
	//	$info = curl_getinfo($ch);
	//	$this->log_event( __METHOD__.": curl_getinfo = ".print_r($info, true), EXT_ERROR_DEBUG);
				
	    $http_response_header = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$this->log_event( __METHOD__.": result = ".print_r($result, true), EXT_ERROR_DEBUG);
		$this->log_event( __METHOD__.": http_response_header = ".print_r($http_response_header, true), EXT_ERROR_DEBUG);
		
		curl_close ($ch);
		
		if ($result === FALSE) {
			if( $this->debug ) {
				echo "<pre>".__METHOD__.": http_response_header\n";
				var_dump($http_response_header);
				echo "</pre>";
			}
			
			$this->log_event( __METHOD__.": result = false, header = ".print_r($http_response_header, true), EXT_ERROR_DEBUG);
			
			// set $this->errmsg
			$this->errmsg = 'Unknown KT error';
			if( is_array($http_response_header) && count($http_response_header) > 0 ) {
				foreach($http_response_header as $row) {
					if( strpos( $row, 'WWW-Authenticate:' ) === 0 ) {
						$this->errmsg = $row;
						break;
					}
				}
			}
			if( $this->debug ) echo "<p>".__METHOD__.": ERROR =  ".$this->errmsg."</p>";
		} else {
			$data = json_decode($result);
			if( $this->debug ) {
				echo "<pre>".__METHOD__.": data\n";
				var_dump($data);
				echo "</pre>";
			}

			$this->log_event( __METHOD__.": result = ".print_r($data, true), EXT_ERROR_DEBUG);

			if( isset($data) && isset($data->error) ) {
				$result = false;
				$this->errmsg = $data->error;
			} else			
			if( isset($data) && isset($data->token_type) && isset($data->access_token) &&
				isset($data->refresh_token) ) {
				$this->setting_table->set( 'api', 'KEEPTRUCKIN_TOKENTYPE', $data->token_type );
				$this->setting_table->set( 'api', 'KEEPTRUCKIN_ACCESSTOKEN', $data->access_token );
				$this->setting_table->set( 'api', 'KEEPTRUCKIN_REFRESHTOKEN', $data->refresh_token );
				$this->token_type = $data->token_type;
				$this->access_token = $data->access_token;
				$this->refresh_token = $data->refresh_token;
				$result = true;

				if( $this->debug ) echo "<p>".__METHOD__.": GOT TOKEN ".$this->token_type.' '.$this->access_token."</p>";
			}
		}
		
		return $result;
	}
	
	//! Shortcut to fetch all admin users
	public function admin_users( $old_method = false ) {
		return $this->fetch( $this->admin_users_url, $old_method );
	}
	
	//! Shortcut to fetch all active drivers
	public function active_drivers() {
		return $this->fetch( $this->active_drivers_url );
	}
	
	//! Shortcut to lookup driver with company id -----------------------
	public function lookup_driver( $company_id ) {
		return $this->get( $this->lookup_driver_url.$company_id );
	}
	
	//! Shortcut to fetch drivers with available time -----------------------
	public function available_time() {
		return $this->fetch( $this->available_time_url );
	}
	
	//! Shortcut to fetch one driver with available time -----------------------
	public function driver_available_time( $driver_id ) {
		return $this->fetch( $this->available_time_url.'?driver_ids='.$driver_id );
	}
	
	//! Shortcut to fetch all active vehicles
	public function vehicles() {
		return $this->fetch( $this->vehicles_url );
	}
	
	//! Shortcut to fetch one active vehicle
	private function vehicle( $vehicle_id ) {
		return $this->fetch( $this->vehicles_url.'/'.$vehicle_id );
	}
	
	//! Shortcut to update one vehicle -----------------------
	private function update_vehicle( $vehicle_id, $data ) {
		return $this->put( $this->vehicles_url.'/'.$vehicle_id, $data );
	}
	
	//! Shortcut to fetch companies data
	public function companies() {
		return $this->fetch( $this->companies_url );
	}
	
	//! Get company name and ID
	public function company_name() {
		$result = '';
		$c = $this->companies();
		if( is_array($c) && count($c) == 1 &&
			is_array($c[0]) && is_array($c[0]["company"]) &&
			! empty($c[0]["company"]["name"]) &&
			! empty($c[0]["company"]["company_id"]) )
			$result = $c[0]["company"]["name"].' ('.$c[0]["company"]["company_id"].')';
		
		return $result;
	}
	
	public function import_ifta( $year, $quarter, $vin = false ) {
		
		if( $this->debug ) echo "<p>".__METHOD__.": year = $year, quarter = $quarter, vin = ".($vin ? $vin : 'false')."</p>";
		$this->log_event( __METHOD__.": year = $year, quarter = $quarter, vin = ".($vin ? $vin : 'false'), EXT_ERROR_DEBUG);
		$start_date = date('Y-m-d', strtotime($year . '-' . (($quarter * 3) - 2) . '-1'));
		$end_date = date('Y-m-t', strtotime($year . '-' . (($quarter * 3)) . '-1'));
		if( $this->debug ) echo "<p>".__METHOD__.": start_date = $start_date, end_date = $end_date</p>";
		
		$imported = 0;
		$not_found = 0;
		$url = $this->trips_url.'?start_date='.$start_date.'&end_date='.$end_date;
		if( $this->debug ) {
			echo "<pre>URL:\n";
			var_dump($url);
			echo "</pre>";
		}
		
		$data = $this->fetch( $url );
		if( $this->debug ) {
			echo "<pre>Data:\n";
			var_dump($data);
			echo "</pre>";
		}
		
		$tractor_table = sts_tractor::getInstance($this->database, $this->debug);
		
		if( is_array($data) && count($data) > 0 ) {
			//! Clear out duplicates
			$this->delete_row( "YEAR(IFTA_DATE) = ".$year."
				AND QUARTER(IFTA_DATE) = ".$quarter."
				AND CD_ORIGIN = 'KeepTruckin'
				AND IS_EDITED = 0" );	//! SCR# 993 - Don't delete those that were edited
			
			foreach( $data as $row ) {
				$r = $row["ifta_trip"];
				$this->log_event( __METHOD__.": ".$r["date"]." ".$r["jurisdiction"]." ".
				$r["vehicle"]["vin"]." ".number_format($r["start_odometer"],2,'.','')." ".
				number_format($r["end_odometer"],2,'.','')." ".
				number_format($r["distance"],2,'.',''),
				EXT_ERROR_DEBUG);
				
				$check = $tractor_table->fetch_rows( "VIN_NUMBER = '".$r["vehicle"]["vin"]."'", "TRACTOR_CODE, UNIT_NUMBER, OWNERSHIP_TYPE" );
				
				if( is_array($check) && count($check) == 1 ) {
					//! Don't do IFTA for owner-operator, ignore...
					if( isset($check[0]["OWNERSHIP_TYPE"]) &&
						$check[0]["OWNERSHIP_TYPE"] <> 'owner-operator' ) {
						$tractor = $check[0]["TRACTOR_CODE"];
						
						$check2 = $this->fetch_rows( "IFTA_TRACTOR = '".$tractor."'
							AND IFTA_JURISDICTION = '".(string) $r["jurisdiction"]."'
							AND IFTA_DATE = '".date("Y-m-d", strtotime($r["date"]))."'
							AND CD_ORIGIN = 'KeepTruckin'
							AND IS_EDITED = 1",
							"IFTA_LOG_CODE" );
						
						if( is_array($check2) && count($check2) > 0 ) {
							echo "<p>Previous imported record EDITED, so not re-imported.</p>";
						} else {
							$fields = array(
								"IFTA_TRACTOR" => $tractor,
								"IFTA_JURISDICTION" => (string) $r["jurisdiction"],
								"IFTA_DATE" => date("Y-m-d", strtotime($r["date"])),
								"ODOMETER_IN" => round((string) $r["start_odometer"], 2),
								"ODOMETER_OUT" => round((string) $r["end_odometer"], 2),
								"DIST_TRAVELED" => round((string) $r["distance"], 2),
								"CD_ORIGIN" => (string) 'KeepTruckin'
							);
							$log_code = $this->add( $fields );
							$this->audit->log_import( $log_code, $tractor, $fields );
							$imported++;
						}
					}
				} else if( is_array($check) && count($check) > 1 ) {
					//! Duplicate VIN matches
					$vin_matches = array();
					foreach( $check as $row ) {
						$vin_matches[] = $row["UNIT_NUMBER"];
					}
					
					echo "<p>Multiple tractors with VIN <strong>".$r["vehicle"]["vin"]."</strong> found in Exspeedite. IFTA record not imported.<br>
					Units: ".implode(', ', $vin_matches)."</p>";
					$this->log_event( __METHOD__.": Multiple tractors with VIN ".$r["vehicle"]["vin"]." not found (Units: ".implode(', ', $vin_matches).")", EXT_ERROR_WARNING);
					$not_found++;
				} else {
					//! Tractor not found
					echo "<p>Tractor with VIN <strong>".$r["vehicle"]["vin"]."</strong> not found in Exspeedite. IFTA record not imported.</p>";
					
					$soundex = $tractor_table->fetch_rows( "soundex(VIN_NUMBER) = soundex('".$r["vehicle"]["vin"]."')", "TRACTOR_CODE, UNIT_NUMBER, OWNERSHIP_TYPE, VIN_NUMBER" );
					
					if( is_array($soundex) && count($soundex) > 0 ) {
						$vin_matches = array();
						foreach( $soundex as $row ) {
							$vin_matches[] = $row["VIN_NUMBER"].' = '.$row["UNIT_NUMBER"];
						}
						
						if( count($vin_matches) > 0 ) {
							echo '<p>Possible matches: '.implode(', ', $vin_matches).'</p>';
						} else {
							echo '<p>No close matches found.</p>';
						}
					}
					
					$this->log_event( __METHOD__.": Tractor with VIN ".$r["vehicle"]["vin"]." not found", EXT_ERROR_WARNING);
					$not_found++;
				}

			}
			$this->log_event( __METHOD__.": imported $imported records, $not_found not found", EXT_ERROR_DEBUG);
		} else {
			$not_found = false;
			$this->log_event( __METHOD__.": import failed, return false", EXT_ERROR_DEBUG);
		}
		
		return $not_found;

	}
	
	//! For a driver with no ID, try and find a match -------------
	public function try_to_find_driver_id( $driver ) {
		if( $this->is_enabled() ) {
			$driver_table = sts_driver::getInstance($this->database, $this->debug);
	
			$driver_id = false;
			
			$check = $driver_table->fetch_rows("DRIVER_CODE = ".$driver,
				"DRIVER_NUMBER");
			
			if( is_array($check) && count($check) == 1 &&
				isset($check[0]["DRIVER_NUMBER"]) ) {
				$driver_number = $check[0]["DRIVER_NUMBER"];
				$this->log_event( __METHOD__.": DRIVER_NUMBER $driver_number for driver $driver", EXT_ERROR_DEBUG);
				
				$data = $this->lookup_driver( $driver_number );
				
				
				if( ! empty($data) ) {
					$info = json_decode($data, true);
					
					if( is_array($info) ) {
						if( ! empty($info['error_message'])) {
							$driver_id = false;
						} else if( isset($info['user']) && is_array($info['user']) &&
							is_array($info['user'])) {
							$user = $info['user'];
							
							if( ! empty($user['id'])) {
								$driver_id = $user['id'];
							}
						}
					}
				}
				
				if( $driver_id ) {
					$this->log_event( __METHOD__.": MATCHED driver_id $driver_id for driver $driver", EXT_ERROR_DEBUG);

					$dummy = $driver_table->update($driver, array('KT_DRIVER_ID' => $driver_id));
				} else {
					$this->log_event( __METHOD__.": NO MATCH for driver $driver", EXT_ERROR_DEBUG);
				}
				
			}
		}
		
		return $driver_id;
		
	}
	
	//! For a tractor with no KT_VEHICLE_ID, try and find a match -------------
	private function try_to_find_vehicle_id( $tractor ) {
		if( $this->is_enabled() ) {
			$tractor_table = sts_tractor::getInstance($this->database, $this->debug);
			$vehicle_id = false;
			
			$check = $tractor_table->fetch_rows("TRACTOR_CODE = ".$tractor,
				"VIN_NUMBER");
			
			if( is_array($check) && count($check) == 1 &&
				isset($check[0]["VIN_NUMBER"]) ) {
				$vin = $check[0]["VIN_NUMBER"];
				
				$this->log_event( __METHOD__.": VIN $vin for tractor $tractor", EXT_ERROR_DEBUG);
				
				$data = $this->vehicles();
				
				foreach($data as $row) {
					if( is_array($row) && isset($row['vehicle']) &&
						is_array($row['vehicle']) && isset($row['vehicle']['vin']) &&
						isset($row['vehicle']['id']) &&
						$row['vehicle']['vin'] == $vin ) {
						$vehicle_id = $row['vehicle']['id'];
					}
				}
				
				if( $vehicle_id ) {
					$this->log_event( __METHOD__.": MATCHED vehicle_id $vehicle_id for tractor $tractor", EXT_ERROR_DEBUG);

					$dummy = $tractor_table->update($tractor, array('KT_VEHICLE_ID' => $vehicle_id));
				} else {
					$this->log_event( __METHOD__.": NO MATCH for tractor $tractor", EXT_ERROR_DEBUG);
				}
				
			}
		}
		
		return $vehicle_id;
	}
	
	//! Find a driver ID for an active driver, given a driver_code
	public function driver_id( $driver, $check_missing = true ) {
		$this->driver_id = $result = false;
		
		if( $this->is_enabled() ) {
			$driver_table = sts_driver::getInstance($this->database, $this->debug);

			$check = $driver_table->fetch_rows("DRIVER_CODE = ".$driver.
				" AND ISACTIVE = 'Active'",
				"COALESCE(KT_DRIVER_ID, 0) AS KT_DRIVER_ID, ISACTIVE");
			
			if( is_array($check) && count($check) == 1 &&
				isset($check[0]["KT_DRIVER_ID"]) ) {		// Found
				
				if( $check[0]["KT_DRIVER_ID"] > 0 ) {
					$this->driver_id = $result = $check[0]["KT_DRIVER_ID"];
				} else if( $check_missing) {
					$this->driver_id = $result = $this->try_to_find_driver_id( $driver );
				}
			}
		}
		
		return $result;
	}
	
	//! Get driver availability stats given a driver_code
	public function driver_available( $driver ) {
		$driver_id = $this->driver_id( $driver, false );
		$result = false;
		$to_hrs = 60 * 60;
		
		if( $driver_id ) {
			$check = $this->driver_available_time( $driver_id );
			
			if( is_array($check) && count($check) == 1 &&
				is_array($check[0]['user']) ) {
				$u = $check[0]['user'];
				$result = [];
				
				if( isset($u['status']) && $u['status'] == 'active' &&
					is_array($u['available_time']) ) {
					$at = $u['available_time'];
					
					if( isset($at['cycle']) ) $result['cycle'] = intval($at['cycle'] / $to_hrs);
					if( isset($at['shift']) ) $result['shift'] = intval($at['shift'] / $to_hrs);
					if( isset($at['drive']) ) $result['drive'] = intval($at['drive'] / $to_hrs);
				}
			}
		}
		
		return $result;
	}
	
	
	
	//! Find a vehicle ID for a tractor, given a tractor_code
	public function vehicle_id( $tractor ) {
		$this->vehicle_id = $result = false;
		$this->isactive = 'Active';	// Default to Active
		
		if( $this->is_enabled() ) {
			$tractor_table = sts_tractor::getInstance($this->database, $this->debug);
			
			$check = $tractor_table->fetch_rows("TRACTOR_CODE = ".$tractor,
				"COALESCE(KT_VEHICLE_ID, 0) AS KT_VEHICLE_ID, ISACTIVE,
				(SELECT ITEM FROM EXP_ITEM_LIST
					WHERE ITEM_CODE = OOS_TYPE
					AND ITEM_TYPE = 'OOS Type') as OOS_TYPE");
			
			if( is_array($check) && count($check) == 1 &&
				isset($check[0]["KT_VEHICLE_ID"]) ) {		// Found
				
				if( $check[0]["KT_VEHICLE_ID"] > 0 ) {
					$this->vehicle_id = $result = $check[0]["KT_VEHICLE_ID"];
				} else {
					$this->vehicle_id = $result = $this->try_to_find_vehicle_id( $tractor );
				}
				 
				if( isset($check[0]["ISACTIVE"]) )
					$this->isactive = $check[0]["ISACTIVE"];

				if( isset($check[0]["OOS_TYPE"]) )
					$this->oos_type = $check[0]["OOS_TYPE"];
			}
		}
		
		return $result;
	}
	
	//! SCR# 1065 - push_isactive ----------------------------------
	private function push_isactive( $vehicle_id, $data ) {
		$change = [];
		
		$this->log_event( __METHOD__.": isactive = ".$this->isactive.
			" OOS_TYPE = ".$this->oos_type, EXT_ERROR_DEBUG);
	//	$this->log_event( __METHOD__.": data = ".print_r($data, true), EXT_ERROR_DEBUG);
		if( $this->debug ) {
			echo "<pre>".__METHOD__.": isactive, OOS_TYPE, data\n";
			var_dump($this->isactive, $this->oos_type, $data);
			echo "</pre>";
		}

		switch( $this->isactive ) {
			case 'Active':
				if( is_array($data) && isset($data['status']) &&
					$data['status'] == 'deactivated')
					$change['status'] = 'active';

				if( is_array($data) && isset($data['availability_details']) &&
					is_array($data['availability_details']) ) {
					$availability_details = $data['availability_details'];
					
					if( is_array($availability_details) &&
						isset($availability_details['availability_status']) &&
						$availability_details['availability_status'] == 'out_of_service' )
						$change['availability_details'] = [
							'availability_status' => 'in_service'
						];
				}
				break;
			
			case 'Inactive':
				if( is_array($data) && isset($data['status']) &&
					$data['status'] == 'active')
					$change['status'] = 'deactivated';
				break;
			
			case 'OOS':
				if( is_array($data) && isset($data['status']) &&
					$data['status'] == 'deactivated')
					$change['status'] = 'active';

				if( is_array($data) && isset($data['availability_details']) &&
					is_array($data['availability_details']) ) {
					$availability_details = $data['availability_details'];
					$ad_changes = [];
					
					if( is_array($availability_details) &&
						isset($availability_details['availability_status']) ) {
						
						$ad_changes['availability_status'] = 'out_of_service';
							
						if( ! empty($this->oos_type) &&
							( ! isset($availability_details['additional_note']) ||
							$availability_details['additional_note'] != $this->oos_type ) )
							$ad_changes['additional_note'] = $this->oos_type;
					}
					
					if( ! empty($ad_changes) )
						$change['availability_details'] = $ad_changes;
				}
				break;
		}
	//	$this->log_event( __METHOD__.": change = ".print_r($change, true), EXT_ERROR_DEBUG);
		
		if( ! empty($change) ) {
			$fields1 = json_encode($change);
			$data2 = $this->update_vehicle( $vehicle_id, $fields1 );
			$data2 = json_decode($data2, true);
			$this->log_event( __METHOD__.": data2 = ".print_r($data2, true), EXT_ERROR_DEBUG);

			if( is_array($data2) && isset($data2['vehicle']) )
				$data2 = $data2['vehicle'];
		} else {
			$data2 = $data;	// No changes required
		}
		
		return $data2;
	}

	//! SCR# 1065 - get_vehicle_status ----------------------------------
	public function get_vehicle_status( $tractor ) {
		$status = $availability_status = 'unknown';
		$out_of_service_reason = '';
		
		$vehicle_id = $this->vehicle_id( $tractor );	// Lookup vehicle_id
		
		if( $vehicle_id != false ) {
			$this->log_event( __METHOD__.": vehicle_id $vehicle_id FOUND for tractor $tractor", EXT_ERROR_DEBUG);

			$data = $this->vehicle( $vehicle_id );
		//	echo "<pre>".__METHOD__.": data\n";
		//	var_dump($data);
		//	echo "</pre>";
			
			/* - testing update_vehicle
			$fields = [ 
				"status" => "active",
				"availability_details" => [
					"availability_status" => "out_of_service",	// in_service or out_of_service
					"out_of_service_reason" => "Repairs",
					"additional_note" => "unscheduled repair"
 				]
			];
			
			$fields1 = json_encode($fields);
			
			$data = $this->update_vehicle( $vehicle_id, $fields1 );
			*/
			
			$data = $this->push_isactive( $vehicle_id, $data );

			if( is_array($data) && isset($data['status']) ) {
				$status = $data['status'];
			}
			
			$vin = $make = $model = $year = $notes = '';
			if( is_array($data) && isset($data['vin']) ) {
				$vin = $data['vin'];
			}
			
			if( is_array($data) && isset($data['make']) ) {
				$make = $data['make'];
			}
			
			if( is_array($data) && isset($data['model']) ) {
				$model = $data['model'];
			}
			
			if( is_array($data) && isset($data['year']) ) {
				$year = $data['year'];
			}
			
			if( is_array($data) && isset($data['availability_details']) &&
				is_array($data['availability_details'])  ) {
				$ad = $data['availability_details'];
				
				if( is_array($ad) && isset($ad['availability_status']) ) {
					$availability_status = $ad['availability_status'];
				}

				if( is_array($ad) && isset($ad['out_of_service_reason']) ) {
					$out_of_service_reason = $ad['out_of_service_reason'];
				}

				if( is_array($ad) && isset($ad['additional_note']) ) {
					$notes = $ad['additional_note'];
				}
			}
			
		} else {
			$this->log_event( __METHOD__.": vehicle_id NOT FOUND for tractor $tractor", EXT_ERROR_DEBUG);
		}
		
		$result = [ 'status' => $status,
			'availability_status' => $availability_status,
			'out_of_service_reason' => $out_of_service_reason];
			
		if( ! empty($vin) ) $result['vin'] = $vin;
		if( ! empty($make) ) $result['make'] = $make;
		if( ! empty($model) ) $result['model'] = $model;
		if( ! empty($year) ) $result['year'] = $year;
		if( ! empty($notes) ) $result['notes'] = $notes;
		
		return $result;
	}

	public function driver_status( $availability ) {
		$status = '';
		if( is_array($availability) && isset($availability['cycle']) ) {
			$status = '<div class="form-group tighter">
				<div class="col-sm-4">Cycle:</div>
				<div class="col-sm-2 text-right">'.$availability['cycle'].'</div>
				<div class="col-sm-2">Hrs</div>
				</div>
				';
		}
		
		if( is_array($availability) && isset($availability['shift']) ) {
			$status .= '<div class="form-group tighter">
				<div class="col-sm-4">Shift:</div>
				<div class="col-sm-2 text-right">'.$availability['shift'].'</div>
				<div class="col-sm-2">Hrs</div>
				</div>
				';
		}
		
		if( is_array($availability) && isset($availability['drive']) ) {
			$status .= '<div class="form-group tighter">
				<div class="col-sm-4">Drive:</div>
				<div class="col-sm-2 text-right">'.$availability['drive'].'</div>
				<div class="col-sm-2">Hrs</div>
				</div>
				';
		}
		
		return empty($status) ? '' : '<div class="panel panel-success">
			  <div class="panel-heading">
			    <h3 class="panel-title"><img style="padding-bottom: 4px;" src="images/keeptruckin2.png" alt="keeptruckin" height="18"> Availability <span style="float: right;">'.
			    (empty($this->driver_id) ? '(Not in Motive)' : $this->driver_id).
			    '</span></h3>
			  </div>
			  <div class="panel-body">
			  '.$status.'
			  </div>
			</div>
			';
	}
		
	public function motive_status( $statuses ) {
		$status = $availability_status = $out_of_service_reason = '';
		if( is_array($statuses) && isset($statuses['status']) ) {
			$status = '<div class="form-group tighter">
				<div class="col-sm-4">Status:</div>
				<div class="col-sm-8'.
				($statuses['status'] == 'active' ? ' text-success' : ' text-danger')				
				.'">'.$statuses['status'].'</div>
				</div>
				';
		}
		
		if( is_array($statuses) && isset($statuses['availability_status']) ) {
			$availability_status = '<div class="form-group tighter">
				<div class="col-sm-4">Availability:</div>
				<div class="col-sm-8">'.$statuses['availability_status'].'</div>
				</div>
				';
		}

		//! disable for now
		if( false && is_array($statuses) && ! empty($statuses['out_of_service_reason']) ) {
			$out_of_service_reason = '<div class="form-group tighter">
				<div class="col-sm-4">Reason:</div>
				<div class="col-sm-8">'.$statuses['out_of_service_reason'].'</div>
				</div>
				';
		}
		
		if( is_array($statuses) && isset($statuses['notes']) ) {
			$out_of_service_reason .= '<div class="form-group tighter">
				<div class="col-sm-4">Notes:</div>
				<div class="col-sm-8">'.$statuses['notes'].'</div>
				</div>
				';
		}

		if( is_array($statuses) && isset($statuses['vin']) ) {
			$out_of_service_reason .= '<div class="form-group tighter">
				<div class="col-sm-4">Vin:</div>
				<div class="col-sm-8">'.$statuses['vin'].'</div>
				</div>
				';
		}

		if( is_array($statuses) && isset($statuses['make']) ) {
			$out_of_service_reason .= '<div class="form-group tighter">
				<div class="col-sm-4">Make:</div>
				<div class="col-sm-8">'.$statuses['make'].'</div>
				</div>
				';
		}

		if( is_array($statuses) && isset($statuses['model']) ) {
			$out_of_service_reason .= '<div class="form-group tighter">
				<div class="col-sm-4">Model:</div>
				<div class="col-sm-8">'.$statuses['model'].'</div>
				</div>
				';
		}

		if( is_array($statuses) && isset($statuses['year']) ) {
			$out_of_service_reason .= '<div class="form-group tighter">
				<div class="col-sm-4">Year:</div>
				<div class="col-sm-8">'.$statuses['year'].'</div>
				</div>
				';
		}

		return empty($status) ? '' : '<div class="panel panel-success">
			  <div class="panel-heading">
			    <h3 class="panel-title"><img style="padding-bottom: 4px;" src="images/keeptruckin2.png" alt="keeptruckin" height="18"> Status <span style="float: right;">'.
			    (empty($this->vehicle_id) ? '(Not in Motive)' : $this->vehicle_id).
			    '</span></h3>
			  </div>
			  <div class="panel-body">
			  '.$status.'
			  '.$availability_status.'
			  '.$out_of_service_reason.'
			  </div>
			</div>
			';
	}
	
	//! Find a tractor_code for a tractor, given a vehicle_id
	public function tractor_code( $vehicle_id, $ask_kt = false ) {
		$result = false;
		//$this->log_event( __METHOD__.": entry, id = $vehicle_id", EXT_ERROR_DEBUG);
		
		if( $this->is_enabled() ) {
			$tractor_table = sts_tractor::getInstance($this->database, $this->debug);

			$check = $tractor_table->fetch_rows("KT_VEHICLE_ID = ".$vehicle_id,
				"TRACTOR_CODE");
			
			if( is_array($check) && count($check) == 1 &&
				isset($check[0]["TRACTOR_CODE"]) ) {		// Found
				$result = $check[0]["TRACTOR_CODE"];
				//$this->log_event( __METHOD__.": found result = $result", EXT_ERROR_DEBUG);
			} else if( $ask_kt ) {
				// Try for a VIN match
				$this->log_event( __METHOD__.": not found try VIN match, id = ".$vehicle_id, EXT_ERROR_DEBUG);
				
				$url = 'https://api.keeptruckin.com/v1//vehicles/'.$vehicle_id;
				$data = $this->fetch( $url );
				
				//$this->log_event( __METHOD__.": data from KT: ".print_r($data, true), EXT_ERROR_DEBUG);
				if( is_array($data) && ! empty($data["vin"])) {
					$this->log_event( __METHOD__.": now look for VIN = ".$data["vin"], EXT_ERROR_DEBUG);
					$check = $tractor_table->fetch_rows("VIN_NUMBER = '".$data["vin"]."'",
						"TRACTOR_CODE");
					if( is_array($check) && count($check) == 1 &&
						isset($check[0]["TRACTOR_CODE"]) ) {		// Found
						$result = $check[0]["TRACTOR_CODE"];
						//$this->log_event( __METHOD__.": found via VIN result = $result", EXT_ERROR_DEBUG);
						$dummy = $tractor_table->update($result, array('KT_VEHICLE_ID' => $vehicle_id));
						$this->log_event( __METHOD__.": update tractor $result with id $vehicle_id, result $dummy", EXT_ERROR_DEBUG);
					} else { // New - add tractor
						$this->log_event( __METHOD__.": not found add tractor", EXT_ERROR_DEBUG);
						$result = $this->sync_tractor( $data );
					}
				} else {
					$this->log_event( __METHOD__.": VIN not returned from KT: ".print_r($data, true), EXT_ERROR_ERROR);
				}
				
			}
		}		
		
		return $result;
	}
	
	public function sync_tractor( $vehicle ) {
		$result = false;
		$tractor_table = sts_tractor::getInstance($this->database, $this->debug);
		
		$id			= $vehicle["id"];
		$unit		= $vehicle["number"];
		$active		= $vehicle["status"] == "active" ? 'Active' : 'Inactive';
		$ifta		= $vehicle["ifta"] ? 'true' : 'false';
		$vin		= $vehicle["vin"];
		$make		= $vehicle["make"];
		$model		= $vehicle["model"];
		$year		= $vehicle["year"];
		$plate		= $vehicle["license_plate_number"];
		$units		= $vehicle["metric_units"] ? 'metric' : 'imperial';
		$fuel_type	= $vehicle["fuel_type"];
		switch( $vehicle["fuel_type"] ) {	// Map fuel types
			case 'diesel':		$fuel_type = 'Special Diesel';	break;
			case 'gasoline':	$fuel_type = 'Gasoline';		break;
			case 'propane':		$fuel_type = 'Propane';			break;
			case 'lng':			$fuel_type = 'LNG';				break;
			case 'cng':			$fuel_type = 'CNG';				break;
			case 'ethanol':		$fuel_type = 'Ethanol';			break;
			case 'methanol':	$fuel_type = 'Methanol';		break;
			case 'e85':			$fuel_type = 'E-85';			break;
			case 'm85':			$fuel_type = 'M-85';			break;
			case 'a55':			$fuel_type = 'A55';				break;
			case 'other':		$fuel_type = 'Special Diesel';	break;
		}

		if( $this->debug ) {
			echo "<pre>vehicle\n";
			var_dump($unit, $active, $ifta, $vin, $make, $model, $year, $plate, $units, $fuel_type);
			echo "</pre>";
		}
		
		$check = $tractor_table->fetch_rows("VIN_NUMBER = '".$vin."'",
			"TRACTOR_CODE, MAKE, MODEL, TRACTOR_YEAR, PLATE, UNIT_NUMBER,
			COALESCE(KT_VEHICLE_ID, 0) AS KT_VEHICLE_ID");
		
		if( $this->debug ) {
			echo "<pre>exists\n";
			var_dump($check);
			echo "</pre>";
		}
		
		if( is_array($check) && count($check) == 0 ) {	// Add tractor
			$this->log_event( __METHOD__.": not found add tractor", EXT_ERROR_DEBUG);
			$result = $tractor_table->add(
				array("KT_VEHICLE_ID" => $id,
					"UNIT_NUMBER" => $unit,
					"ISACTIVE" => $active,
					"LOG_IFTA" => $ifta,
					"VIN_NUMBER" => $vin,
					"MAKE" => $make,
					"MODEL" => $model,
					"TRACTOR_YEAR" => $year,
					"PLATE" => $plate,
					"UNITS" => $units,
					"FUEL_TYPE" => $fuel_type,
					"OWNERSHIP_TYPE" => "company"
				));
		} else if( is_array($check) && count($check) == 1 &&
			isset($check[0]["TRACTOR_CODE"]) ) {		// Update record
			$this->log_event( __METHOD__.": found update tractor ".$check[0]["TRACTOR_CODE"], EXT_ERROR_DEBUG);
			$changes = array();
			if( isset($check[0]["KT_VEHICLE_ID"]) &&
				$check[0]["KT_VEHICLE_ID"] <> $id) $changes["KT_VEHICLE_ID"] = $id;
			if( isset($check[0]["UNIT_NUMBER"]) &&
				$check[0]["UNIT_NUMBER"] <> $unit) $changes["UNIT_NUMBER"] = $unit;
			if( empty($check[0]["MAKE"]) && ! empty($make)) $changes["MAKE"] = $make;
			if( empty($check[0]["MODEL"]) && ! empty($model)) $changes["MODEL"] = $model;
			if( empty($check[0]["TRACTOR_YEAR"]) && ! empty($year))
				$changes["TRACTOR_YEAR"] = $year;
			if( empty($check[0]["PLATE"]) && ! empty($plate)) $changes["PLATE"] = $plate;
			if( count($changes) > 0)
				$tractor_table->update($check[0]["TRACTOR_CODE"], $changes );
			$result = $check[0]["TRACTOR_CODE"];
		}
		return $result;
	}

	//! Find a driver_code for current driver, given a vehicle_id
	public function current_driver_code( $vehicle_id ) {
		$result = false;
		$this->log_event( __METHOD__.": entry, id = $vehicle_id", EXT_ERROR_DEBUG);
		
		if( $this->is_enabled() ) {
			$url = 'https://api.keeptruckin.com/v1/vehicles/'.$vehicle_id;
			
			try {
				$data = $this->fetch( $url );
			} catch (Exception $e) {
				$data = false;
			}

			if( is_array($data) && 
				isset($data["current_driver"]) &&
				isset($data["current_driver"]["id"]) ) {		// Found
				
				$id = $data["current_driver"]["id"];			// Driver ID
				
				$driver_table = sts_driver::getInstance($this->database, $this->debug);
	
				$check = $driver_table->fetch_rows("KT_DRIVER_ID = ".$id,
					"DRIVER_CODE");
				if( is_array($check) && count($check) == 1 &&
					isset($check[0]["DRIVER_CODE"]) ) {		// Found
					$result = $check[0]["DRIVER_CODE"];
					$this->log_event( __METHOD__.": found result = $result", EXT_ERROR_DEBUG);
				} else { // New - add driver
					$this->log_event( __METHOD__.": not found try sync_driver", EXT_ERROR_DEBUG);
					$result = $this->sync_driver( $data["current_driver"] );
				}
			} else {
				$this->log_event( __METHOD__.": Driver ID for $vehicle_id not returned from KT: ".print_r($data, true), EXT_ERROR_ERROR);
			}
		}
		
		return $result;
	}
	
	public function test9() {
		$this->log_event( __METHOD__.": Testing", EXT_ERROR_DEBUG);
	}

	public function sync_driver( $user ) {
		$result = false;
		$this->log_event( __METHOD__.": user = ".print_r($user, true), EXT_ERROR_DEBUG);
		$driver_table = sts_driver::getInstance($this->database, $this->debug);

		if( is_array($user) && ! empty($user["id"]) &&
			! empty($user["first_name"]) && ! empty($user["last_name"])) {
			$id			= $user["id"];
			$first_name	= $user["first_name"];
			$last_name	= $user["last_name"];
			$active		= 'Active';

			if( $this->debug ) {
				echo "<pre>driver\n";
				var_dump($id, $driver_num, $active, $email, $first_name, $last_name);
				echo "</pre>";
			}
			
			$check = $driver_table->fetch_rows(
				"FIRST_NAME = '".$first_name."' AND LAST_NAME = '".$last_name."'",
				"DRIVER_CODE, COALESCE(KT_DRIVER_ID, 0) AS KT_DRIVER_ID");
		
			if( $this->debug ) {
				echo "<pre>exists\n";
				var_dump($check);
				echo "</pre>";
			}
			
			if( is_array($check) && count($check) == 0 &&
				! empty($first_name) && ! empty($last_name) ) {	// Add driver
				$changes = array("KT_DRIVER_ID" => $id,
						"ISACTIVE" => $active,
						"FIRST_NAME" => $first_name,
						"LAST_NAME" => $last_name,
						"DRIVER_NOTES" => 'Imported from KeepTruckin '.date("m/d/Y H:i")
					);
				if( ! empty($user["driver_company_id"]) )
					$changes["DRIVER_NUMBER"] = $user["driver_company_id"];
				if( ! empty($user["email"]) )
					$changes["EMAIL_NOTIFY"] = $user["email"];	

				$result = $driver_table->add( $changes );
			} else if( is_array($check) && count($check) == 1 &&
				isset($check[0]["DRIVER_CODE"]) &&
				isset($check[0]["KT_DRIVER_ID"]) &&
				$check[0]["KT_DRIVER_ID"] <> $id ) {		// Update KT_DRIVER_ID
				$driver_table->update($check[0]["DRIVER_CODE"],
					array( "KT_DRIVER_ID" => $id ));
				$result = $check[0]["DRIVER_CODE"];
			}
		}

		$this->log_event( __METHOD__.": return result = ".($result == false ? 'false' : $result),
			EXT_ERROR_DEBUG);
		return $result;
	}
}

//! Layout Specifications - For use with sts_result

$sts_result_ifta_log_layout = array(
	'IFTA_LOG_CODE' => array( 'format' => 'hidden' ),
	//'COMPANY_CODE' => array( 'format' => 'hidden' ),
	//'IFTA_TRACTOR' => array( 'format' => 'hidden' ),
	'NULL' => array( 'label' => '', 'format' => 'text' ),
	'IFTA_DATE' => array( 'label' => 'Date', 'format' => 'text', 'cell' => 'imported', 'extras' => 'required' ),
	'CD_STOP_NAME' => array( 'label' => 'Stop', 'format' => 'text' ),
	'IFTA_JURISDICTION' => array( 'label' => 'State', 'format' => 'text', 'cell' => 'imported', 'extras' => 'required' ),
	'ODOMETER_IN' => array( 'label' => 'Odo In', 'format' => 'number', 'align' => 'right', 'cell' => 'imported' ),
	'ODOMETER_OUT' => array( 'label' => 'Odo Out', 'format' => 'number', 'align' => 'right', 'cell' => 'imported' ),
	'DIST_TRAVELED' => array( 'label' => 'Distance Travelled','format' => 'number', 'align' => 'right', 'cell' => 'imported', 'extras' => 'required', 'value' => 0 ),
	'DIST_NON_TAXABLE' => array( 'label' => 'Distance Non-Taxable', 'format' => 'number', 'align' => 'right', 'cell' => 'imported', 'value' => 0 ),
	'FUEL_PURCHASED' => array( 'label' => 'Gallons', 'format' => 'number', 'align' => 'right', 'cell' => 'imported', 'value' => 0 ),
	'CD_ORIGIN' => array( 'label' => 'Source', 'format' => 'text' ),
	'IS_EDITED' => array( 'label' => 'Edited', 'format' => 'bool', 'align' => 'center' ),
);

$sts_result_ifta_log_load_layout = array(
	'IFTA_LOG_CODE' => array( 'format' => 'hidden' ),
	//'COMPANY_CODE' => array( 'format' => 'hidden' ),
	//'IFTA_TRACTOR' => array( 'format' => 'hidden' ),
	//'NULL' => array( 'label' => '', 'format' => 'text' ),
	'IFTA_DATE' => array( 'label' => 'Date', 'format' => 'text', 'extras' => 'required' ),
	//'CD_STOP_NAME' => array( 'label' => 'Stop', 'format' => 'text' ),
	'IFTA_JURISDICTION' => array( 'label' => 'State', 'format' => 'text', 'extras' => 'required' ),
	'ODOMETER_IN' => array( 'label' => 'Odo In', 'format' => 'number', 'align' => 'right' ),
	'ODOMETER_OUT' => array( 'label' => 'Odo Out', 'format' => 'number', 'align' => 'right' ),
	'DIST_TRAVELED' => array( 'label' => 'Distance Travelled','format' => 'number', 'align' => 'right',  'extras' => 'required', 'value' => 0 ),
	//'DIST_NON_TAXABLE' => array( 'label' => 'Distance Non-Taxable', 'format' => 'number', 'align' => 'right', 'value' => 0 ),
	//'FUEL_PURCHASED' => array( 'label' => 'Gallons', 'format' => 'number', 'align' => 'right', 'value' => 0 ),
	'CD_ORIGIN' => array( 'label' => 'Source', 'format' => 'text' ),
	'IS_EDITED' => array( 'label' => 'Edited', 'format' => 'bool', 'align' => 'center' ),
);

$sts_result_ifta_log_audit_layout = array( //! $sts_result_ifta_log_audit_layout
	'IFTA_LOG_AUDIT_CODE' => array( 'format' => 'hidden' ),
	'CREATED_DATE' => array( 'label' => 'Date', 'format' => 'datetime',
		'extras' => 'readonly', 'align' => 'right' ),
	'CD_ORIGIN' => array( 'label' => 'Source', 'format' => 'text' ),
	'IFTA_TRACTOR' => array( 'label' => 'Tractor', 'format' => 'table',
		'table' => TRACTOR_TABLE, 'key' => 'TRACTOR_CODE', 'fields' => 'UNIT_NUMBER',
		'static' => true ),
	'AUDIT_EVENT' => array( 'label' => 'Event', 'format' => 'text' ),
	'AUDIT_COMMENTS' => array( 'label' => 'Comments', 'format' => 'text' ),
	'CREATED_BY' => array( 'label' => 'By', 'format' => 'table',
		'table' => USER_TABLE, 'key' => 'USER_CODE', 'fields' => 'USERNAME', 'static' => true )
		
		
);

$sts_result_carrier_log_load_layout = array(
	'CARRIER_LOG_CODE' => array( 'format' => 'hidden' ),
	//'COMPANY_CODE' => array( 'format' => 'hidden' ),
	//'IFTA_TRACTOR' => array( 'format' => 'hidden' ),
	//'NULL' => array( 'label' => '', 'format' => 'text' ),
	'IFTA_DATE' => array( 'label' => 'Date', 'format' => 'text', 'extras' => 'required' ),
	'IFTA_JURISDICTION' => array( 'label' => 'State', 'format' => 'text', 'extras' => 'required' ),
	'DIST_TRAVELED' => array( 'label' => 'Distance Travelled','format' => 'number', 'align' => 'right',  'extras' => 'required', 'value' => 0 ),
	'CD_ORIGIN' => array( 'label' => 'Source', 'format' => 'text' ),
);

$sts_result_ifta_log_calc_layout = array(
	'FUEL_TYPE' => array( 'label' => 'Fuel Type', 'format' => 'text', 'cell' => 'imported', 'length' => 100 ),
	'IFTA_JURISDICTION' => array( 'label' => 'Jurisdiction', 'format' => 'text', 'cell' => 'imported' ),
	'DIST_TRAVELED' => array( 'label' => 'Travelled','format' => 'number', 'align' => 'right', 'cell' => 'imported' ),
	'DIST_NON_TAXABLE' => array( 'label' => 'Non-Taxable', 'format' => 'number', 'align' => 'right', 'cell' => 'imported' ),
	'FUEL_PURCHASED' => array( 'label' => 'Purchased', 'format' => 'number', 'align' => 'right', 'cell' => 'imported' ),
	'FUEL_USED' => array( 'label' => 'Used', 'format' => 'number', 'align' => 'right' ),
	'FUEL_TAXABLE' => array( 'label' => 'Taxable', 'format' => 'number', 'align' => 'right' ),
	'IFTA_RATE' => array( 'label' => 'Rate', 'format' => 'number', 'align' => 'right' ),
	'IFTA_SURCHARGE' => array( 'label' => 'Surcharge', 'format' => 'number', 'align' => 'right' ),
	'IFTA_TAX' => array( 'label' => 'Tax', 'format' => 'number', 'align' => 'right' ),
	'IFTA_SC' => array( 'label' => '+&nbsp;SC', 'format' => 'number', 'align' => 'right' ),
	'TOTAL_TAX' => array( 'label' => '=&nbsp;Total', 'format' => 'number', 'align' => 'right' ),
);

//! Edit/Delete Button Specifications - For use with sts_result

$sts_result_ifta_log_edit = array(
	'title' => '<img src="images/iftatest.gif" alt="iftatest" height="40"> Edit IFTA Log for ',
	'sort' => 'IFTA_DATE asc',
	//'cancel' => 'exp_listtractor.php',
	'cancelbutton' => 'Tractors',
);

$sts_result_ifta_log_audit_edit = array(
	//'title' => '<img src="images/iftatest.gif" alt="iftatest" height="40"> IFTA Log Audit',
	'sort' => 'CREATED_DATE asc',
);

$sts_result_ifta_log_calc_edit = array(
	'title' => '<img src="images/iftatest.gif" alt="iftatest" height="40"> IFTA Report for ',
	'toprow' => '<tr>
		<th colspan="2" style="border: 0px;">&nbsp;</th>
		<th class="exspeedite-bg text-center h4" colspan="2">Distance</th>
		<th class="exspeedite-bg text-center h4" colspan="3">Fuel</th>
		<th class="exspeedite-bg text-center h4" colspan="2">Rates</th>
		<th class="exspeedite-bg text-center h4" colspan="3">Tax</th>
	</tr>
	',
	'sort' => 'FUEL_TYPE, IFTA_JURISDICTION',
	'cancelbutton' => 'Back',
	//'rowbuttons' => array(
		//array( 'url' => 'exp_edi_resend.php?PW=dapI&CODE=', 'key' => 'EDI_CODE', 'label' => 'FILENAME', 'tip' => 'Resend ', 'icon' => 'glyphicon glyphicon-repeat', 'showif' => 'edi', 'confirm' => 'yes' ),
	//)	
);

