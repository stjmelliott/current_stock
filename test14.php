<?php 
// Set flag that this is a parent file
define( '_STS_INCLUDE', 1 );
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

error_reporting(E_ALL);


if( php_sapi_name() == 'cli' ) {
	parse_str(implode('&', array_slice($argv, 1)), $_GET);
	
	if( isset($_GET['subdomain']) && ! isset($_SERVER['SERVER_NAME']))
		$sts_subdomain = $_SERVER['SERVER_NAME'] = $_GET['subdomain'];
	$sts_debug = false;
} else {
	// Setup Session
	require_once( "include/sts_session_setup.php" );
}

$sts_debug = isset($_GET['debug']);

require_once( "include/sts_config.php" );
echo "<h1>OUTER Start</h1>";

if( isset($_GET['T3'])) {
	require_once( "include/sts_ifta_log_class.php" );
	
	$kt = sts_keeptruckin::getInstance( $exspeedite_db, $sts_debug );
	
	$id = $kt->driver_id( 188 );
	
	if( $id ) {
		$res = $kt->driver_available_time( $id );
		if( true ) {
			echo "<pre>";
			var_dump($res);
			echo "</pre>";
		}
	
		$c = 1;
		$hrs = 60 * 60;
		foreach($res as $row) {
			if( $row['user']['status'] == 'active' ) {
				echo $c++.' '.$row['user']['id'].' = '.
				$row['user']['first_name'].' '.$row['user']['last_name'].
				' available = '.intval($row['user']['recap']['seconds_available']/$hrs).
				' tomorrow = '.intval($row['user']['recap']['seconds_tomorrow']/$hrs).
				'<br>';
				
				if( is_array($row['user']['available_time']) ) {
					$a = $row['user']['available_time'];
					echo 'cycle = '.intval($a['cycle']/$hrs).
					' shift = '.intval($a['shift']/$hrs).
					' drive = '.intval($a['drive']/$hrs).
						'<br>';
	
				}
			}
		}
	} else {
		echo '<h2>ID not found</h2>';
	}
	
	
} else
if( isset($_GET['T2'])) {
	require_once( "include/sts_ifta_log_class.php" );
	
	$kt = sts_keeptruckin::getInstance( $exspeedite_db, $sts_debug );
	
	$res = $kt->vehicles();
	foreach($res as $row) {
		echo "<pre>";
		var_dump($row['vehicle']['vin']);
		echo "</pre>";
	}
	
	//echo "<pre>";
	//var_dump($res);
	//echo "</pre>";
	
	
} else
if( isset($_GET['TRACTOR'])) {
	require_once( "include/sts_ifta_log_class.php" );
	
	$kt = sts_keeptruckin::getInstance( $exspeedite_db, $sts_debug );
	
	$res = $kt->get_vehicle_status($_GET['TRACTOR']);
	echo "<pre>";
	var_dump($res);
	echo "</pre>";
	
	
} else
if( isset($_GET['OPCACHE'])) {
		echo "<pre>";
		var_dump(opcache_get_configuration());
		var_dump(opcache_get_status());
		echo "</pre>";
	
	
	$scripts = opcache_get_status()["scripts"];
	
	$hits = array_column($scripts, 'hits');
	array_multisort($hits, SORT_DESC, $scripts);
	
	echo '<h2>'.count($scripts).' Scripts Cached</h2>
	
	<table border="1"><thead>
	<tr><th>Script</th><th>Hits</th><th>Last Used</th></tr>
	</thead><tbody>
	';
	foreach( $scripts as $key => $row ) {
		echo '<tr><td>'.$key.'</td><td style="text-align: right;">'.$row['hits'].'</td><td>'.$row['last_used'].'</td></tr>
		';
	}
	echo '</tbody></table>
	';

} else
if( isset($_GET['MARGIN'])) {
	// $sts_debug = false;
	require_once( "include/sts_margin_report_class.php" );
	
	$mr = sts_margin_report::getInstance( $exspeedite_db, $sts_debug );
	
	$res = $mr->add_margin_report_data($_GET['MARGIN']);
	
	echo "<pre>";
	var_dump($res);
	echo "</pre>";
	
} else
if( isset($_GET['MARGINL'])) {
	$sts_debug = false;
	require_once( "include/sts_margin_report_class.php" );
	
	$mr = sts_margin_report::getInstance( $exspeedite_db, $sts_debug );
	
	$res = $mr->add_margin_report_data_load($_GET['MARGINL']);
	
	echo "<pre>";
	var_dump($res);
	echo "</pre>";
	
} else
if( isset($_GET['MARGINR'])) {
	$sts_debug = true;
	require_once( "include/sts_margin_report_class.php" );
	
	$mr = sts_margin_report::getInstance( $exspeedite_db, $sts_debug );
	
	$res = $mr->margin_report( -1, false, -1, -1, -1, '2025-01-01', '2025-12-31', 'USD' );
	
	echo "<pre>";
	var_dump($res);
	echo "</pre>";
	
} else

if( isset($_GET['ASCII'])) {
$str = 'aAÂ';
$str2 = preg_replace('/[[:^ascii:]]/', '', $str); // should be aA
	echo "<pre>";
	var_dump($str);
	var_dump($str2);
	echo "</pre>";
	die;
	
} else
if( isset($_GET['FMCSA'])) {
	$sts_debug = isset($_GET['debug']) && in_group( EXT_GROUP_DEBUG );
	echo "<h1>SW Start</h1>";
	require_once( "include/sts_sw_carrier_class.php" );
	
	$sw = sts_sw_carrier::getInstance( $exspeedite_db, $sts_debug );

	$sw->fmcsa_lookup_carrier($_GET['FMCSA']);
	 
} else
if( isset($_GET['SW2'])) {
	$sts_debug = isset($_GET['debug']) && in_group( EXT_GROUP_DEBUG );
	echo "<h1>SW Start</h1>";
	require_once( "include/sts_sw_carrier_class.php" );
	
	$sw = sts_sw_carrier::getInstance( $exspeedite_db, $sts_debug );

   // header('Content-Type: application/json');
   $sw->sw_update_carrier( 1060 );
	
} else
if( isset($_GET['SW'])) {
	$sts_debug = isset($_GET['debug']) && in_group( EXT_GROUP_DEBUG );
	echo "<h1>SW Start</h1>";
	require_once( "include/sts_sw_carrier_class.php" );
	
	$sw = sts_sw_carrier::getInstance( $exspeedite_db, $sts_debug );

   // header('Content-Type: application/json');
    echo json_encode($sw->saferwatchCarrierLookup("", "2379200", false), JSON_PRETTY_PRINT);
	
} else
if( php_sapi_name() != 'cli' ) {
	$sts_debug = isset($_GET['debug']) && in_group( EXT_GROUP_DEBUG );
	require_once( "include/sts_session_class.php" );
	
	$my_session = sts_session::getInstance( $exspeedite_db, $sts_debug );
	$my_session->access_check( EXT_GROUP_ADMIN );	// Make sure we should be here
	
	$sts_subtitle = "Test14 - Inspection Report";
	require_once( "include/header_inc.php" );
	require_once( "include/sts_optimize_class.php" );
}
else
if( isset($_GET['IMG'])) {
	$image_path = 'images/BANDMlogo.PNG';
	$ext = pathinfo($image_path, PATHINFO_EXTENSION);
	$contents = file_get_contents($image_path);
	$base64   = trim(chunk_split(base64_encode($contents)));
	$value = "data:image/".$ext.";base64,".$base64;
	echo '<img src="'.$value.'">';
	
} else
if( isset($_GET['D1'])) {
	echo "<h2>Google Distancematrix</h2>";
	
	require_once( "sts_setting_class.php" );
	$setting_table = sts_setting::getInstance( $exspeedite_db, $sts_debug );
	
	$google_api_key = $setting_table->get( 'api', 'GOOGLE_API_KEY' );
	
	echo "<pre>API key\n";
	var_dump($google_api_key);
	echo "</pre>";
	
	$orig = '98230';
	//$dest = '44125';
	$dest = '283009 LOGISTICS DRIVE, Rocky View County, AB, T1Z 0A9';

	$url = 'https://maps.googleapis.com/maps/api/distancematrix/json?key='.
		$google_api_key.'&origins='.urlencode($orig).
		'&destinations='.urlencode($dest).'&units=imperial';
	echo "<p>get_distance_google url = $url</p>";
	
	$data = @file_get_contents( $url );

	$result = json_decode($data, true);

	echo "<p>get_distance_google result = </p>
	<pre>";
	var_dump($result);
	echo "</pre>";

}

if( php_sapi_name() != 'cli' ) {
	require_once( "include/footer_inc.php" );
}
?>