<?php

// $Id:$
// Dashboard queries

// no direct access
defined('_STS_INCLUDE') or die('Restricted access');

require_once( "sts_table_class.php" );

class sts_dashboard {
	public $debug;
	public $database;
	public $primary_key = "DT";


	public function __construct( $database, $debug = false ) {
		$this->debug = $debug;
		$this->database = $database;
	}
	
	public function get_table_name() {
		return '';
	}
	
	public function column_exists( $column ) {
		return true;
	}
	
	public function column_type( $column ) {
		if( $column == 'DT' )
			$result = 'date';
		else
			$result = 'number';
		
		return $result;
	}
	
	public function fetch_rows( $match = "", $fields = "*", $order = "", $limit = "", $groupby = "", $match2 = "" ) {
		return $this->stats_30();
	}
	
	public function active_users() {
		$result = false;
		
		$check = $this->database->get_one_row("
			SELECT COUNT(*) NUM FROM EXP_USER
			WHERE ISACTIVE = 'Active'");
		
		if( is_array($check) && isset($check['NUM']))
			$result = $check['NUM'];
			
		return $result;
	}

	public function monthly_logins() {
		$result = $this->database->get_multiple_rows("
			SELECT DATE(CREATED_DATE) AS DT, COUNT(DISTINCT USER_CODE) NUM
			FROM EXP_USER_LOG
			WHERE DATE(CREATED_DATE) > DATE_SUB(NOW(), INTERVAL 1 MONTH)
			AND LOG_EVENT = 'login'
			GROUP BY DATE(CREATED_DATE)
			ORDER BY CREATED_DATE DESC");
			
		return $result;
	}
	
	public function hourly_logins() {
		$result = $this->database->get_multiple_rows("
			SELECT HOUR(CREATED_DATE) AS HR, COUNT(DISTINCT USER_CODE) NUM
			FROM EXP_USER_LOG
			WHERE CREATED_DATE > DATE_SUB(NOW(), INTERVAL 24 HOUR)
			AND LOG_EVENT = 'login'
			GROUP BY HOUR(CREATED_DATE)
			ORDER BY CREATED_DATE DESC");
			
		return $result;
	}
	
	public function monthly_shipments() {
		$result = $this->database->get_multiple_rows("
			SELECT DATE(CREATED_DATE) AS DT, COUNT(DISTINCT SHIPMENT_CODE) NUM,
			SUM((SELECT TOTAL FROM EXP_CLIENT_BILLING
				WHERE SHIPMENT_ID = SHIPMENT_CODE)) AS TOTAL
			FROM EXP_SHIPMENT
			WHERE DATE(CREATED_DATE) > DATE_SUB(NOW(), INTERVAL 1 MONTH)
			AND BILLING_STATUS = 65
			GROUP BY DATE(CREATED_DATE)
			ORDER BY CREATED_DATE DESC");
			
		return $result;
	}
	
	public function monthly_loads() {
		$result = $this->database->get_multiple_rows("
			SELECT DATE(CREATED_DATE) AS DT, COUNT(DISTINCT LOAD_CODE) NUM
			FROM EXP_LOAD
			WHERE DATE(CREATED_DATE) > DATE_SUB(NOW(), INTERVAL 1 MONTH)
			AND CURRENT_STATUS = 34
			GROUP BY DATE(CREATED_DATE)
			ORDER BY CREATED_DATE DESC");
				
		return $result;
	}

	public function stats_90() {
		$result = $this->database->get_multiple_rows("
			SELECT A.DT, COALESCE(LOGINS, 0) as LOGINS,
				COALESCE(SHIPMENTS, 0) AS SHIPMENTS,
				COALESCE(LOADS, 0) AS LOADS
			FROM 
			(SELECT DATE(CREATED_DATE) AS DT, COALESCE(COUNT(DISTINCT USER_CODE), 0) LOGINS
			FROM EXP_USER_LOG
			WHERE DATE(CREATED_DATE) > DATE_SUB(NOW(), INTERVAL 3 MONTH)
			AND LOG_EVENT = 'login'
			AND YEAR(CREATED_DATE) = YEAR(CURRENT_DATE)
			GROUP BY DATE(CREATED_DATE)
			ORDER BY CREATED_DATE DESC) A

			LEFT JOIN
			
			(SELECT DT, COALESCE(COUNT(DISTINCT SHIPMENT_CODE), 0) SHIPMENTS
			FROM (SELECT SHIPMENT_CODE, DATE(SHIPMENT_ENTERED(SHIPMENT_CODE)) AS DT
			FROM EXP_SHIPMENT
			WHERE DATE(CREATED_DATE) > DATE_SUB(NOW(), INTERVAL 3 MONTH)
			AND CURRENT_STATUS != 4) S
			GROUP BY DT
			ORDER BY 1 DESC) B

			ON A.DT = B.DT
			
			LEFT JOIN

			(SELECT DT, COALESCE(COUNT(DISTINCT LOAD_CODE), 0) LOADS
			FROM (SELECT LOAD_CODE, DATE(LOAD_DISPATCHED(LOAD_CODE)) AS DT
			FROM EXP_LOAD
			WHERE DATE(CREATED_DATE) > DATE_SUB(NOW(), INTERVAL 3 MONTH)
			AND CURRENT_STATUS != 13) L
			GROUP BY DT
			ORDER BY 1 DESC) C

			ON A.DT = C.DT
			ORDER BY A.DT DESC");

		//	echo "<pre>QQQ\n";
		//	var_dump($result);
		//	echo "</pre>";
			
				
		return $result;
	}
	
	public function profile() {
		$result = $this->database->get_one_row("
			SELECT (SELECT COUNT(*)
			FROM EXP_COMPANY) AS COMPANIES,
			(SELECT COUNT(*)
			FROM EXP_OFFICE
			WHERE ISACTIVE) AS OFFICES,
			(SELECT THE_VALUE
			FROM EXP_SETTING
			WHERE CATEGORY = 'company'
			AND SETTING = 'NAME') AS NAME,
			(SELECT THE_VALUE
			FROM EXP_SETTING
			WHERE CATEGORY = 'option'
			AND SETTING = 'MULTI_COMPANY') AS MULTI_COMPANY,
			(SELECT COUNT(*) NUM FROM EXP_USER
			WHERE ISACTIVE = 'Active') AS USERS,
			(SELECT COALESCE(COUNT(DISTINCT USER_CODE), 0) LOGINS
			FROM EXP_USER_LOG
			WHERE DATE(CREATED_DATE) > DATE_SUB(NOW(), INTERVAL 1 week)
			AND LOG_EVENT = 'login'
			AND YEAR(CREATED_DATE) = YEAR(CURRENT_DATE)) AS WEEK_LOGINS
			");
		
		return $result;
	}

	public function shipments_guage() {
		$result = [];
		
		$max = $this->database->get_one_row("
		SELECT COALESCE(MAX(SHIPMENTS), 0) AS MAX_SHIPMENTS
		FROM (
		SELECT WK, COALESCE(COUNT(DISTINCT SHIPMENT_CODE), 0) SHIPMENTS
					FROM (SELECT SHIPMENT_CODE, DATE(SHIPMENT_ENTERED(SHIPMENT_CODE)) AS DT,
						WEEK(SHIPMENT_ENTERED(SHIPMENT_CODE)) AS WK
					FROM EXP_SHIPMENT
					WHERE DATE(CREATED_DATE) > DATE_SUB(NOW(), INTERVAL 6 MONTH)
					AND CURRENT_STATUS != 4) S
		            group by WK
		            order by 1 desc) W");
		            
		if( is_array($max) && count($max) == 1 && isset($max['MAX_SHIPMENTS']))
			$result['MAX_SHIPMENTS'] = $max['MAX_SHIPMENTS'];
				
		$curr = $this->database->get_one_row("
		SELECT COALESCE(COUNT(DISTINCT SHIPMENT_CODE), 0) SHIPMENTS
			FROM (SELECT SHIPMENT_CODE, DATE(SHIPMENT_ENTERED(SHIPMENT_CODE)) AS DT
			FROM EXP_SHIPMENT
			WHERE DATE(CREATED_DATE) > DATE_SUB(NOW(), INTERVAL 1 week)
			AND CURRENT_STATUS != 4) S");
		            
		if( is_array($curr) && count($curr) == 1 && isset($curr['SHIPMENTS']))
			$result['SHIPMENTS'] = $curr['SHIPMENTS'];
				
		return $result;
	}

	public function loads_guage() {
		$result = [];
		
		$max = $this->database->get_one_row("
		SELECT COALESCE(MAX(LOADS), 0) AS MAX_LOADS
		FROM (
		SELECT WK, COALESCE(COUNT(DISTINCT LOAD_CODE), 0) LOADS
			FROM (SELECT LOAD_CODE, DATE(LOAD_DISPATCHED(LOAD_CODE)) AS DT,
				WEEK(LOAD_DISPATCHED(LOAD_CODE)) AS WK
			FROM EXP_LOAD
			WHERE DATE(CREATED_DATE) > DATE_SUB(NOW(), INTERVAL 6 month)
			AND CURRENT_STATUS != 13) L

		            group by WK
		            order by 1 desc) W");
		            
		if( is_array($max) && count($max) == 1 && isset($max['MAX_LOADS']))
			$result['MAX_LOADS'] = $max['MAX_LOADS'];
				
		$curr = $this->database->get_one_row("
		SELECT COALESCE(COUNT(DISTINCT LOAD_CODE), 0) LOADS
			FROM (SELECT LOAD_CODE, DATE(LOAD_DISPATCHED(LOAD_CODE)) AS DT
			FROM EXP_LOAD
			WHERE DATE(CREATED_DATE) > DATE_SUB(NOW(), INTERVAL 1 week)
			AND CURRENT_STATUS != 13) L");
		            
		if( is_array($curr) && count($curr) == 1 && isset($curr['LOADS']))
			$result['LOADS'] = $curr['LOADS'];
				
		return $result;
	}

}

//! Layout Specifications - For use with sts_result
$sts_result_stats_layout = [ //! $sts_result_stats_layout
	'DT' => array( 'label' => 'Date', 'format' => 'date' ),
	'LOGINS' => array( 'label' => 'Logins', 'format' => 'num0', 'align' => 'right' ),
	'SHIPMENTS' => array( 'label' => 'Shipments', 'format' => 'num0', 'align' => 'right' ),
	'LOADS' => array( 'label' => 'Loads', 'format' => 'num0', 'align' => 'right' ),
];

//! Edit/Delete Button Specifications - For use with sts_result
$sts_result_stats_edit = [
	'title' => '30 Days Stats',
];

?>