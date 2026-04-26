<?php 
// Set flag that this is a parent file
define( '_STS_INCLUDE', 1 );

// Setup Session
require_once( "include/sts_session_setup.php" );

require_once( "include/sts_config.php" );
$sts_debug = isset($_GET['debug']) && in_group( EXT_GROUP_DEBUG );
require_once( "include/sts_session_class.php" );
set_time_limit(0);
ini_set('memory_limit', '1024M');

$my_session = sts_session::getInstance( $exspeedite_db, $sts_debug );

// Use Google Charts, see https://developers.google.com/chart
define( '_STS_GOOGLE_CHARTS', 1 );

if( ! isset($_GET) || ! isset($_GET["EXPORT"])) {
	$sts_subtitle = "Exspeedite Dashboard";
	require_once( "include/header_inc.php" );
	require_once( "include/navbar_inc.php" );
}

?>
<div class="container-full" role="main">
<?php
require_once( "include/sts_dashboard_class.php" );
require_once( "include/sts_result_class.php" );

$dash = new sts_dashboard($exspeedite_db, $sts_debug);

$profile = $dash->profile();


if( is_array($profile) ) {
		
	echo '<h2>'.$profile["NAME"].': multi-company = '.$profile["MULTI_COMPANY"].
		', companies = '.$profile["COMPANIES"].', offices = '.$profile["OFFICES"].
		', users = '.$profile["USERS"].'</h2>';

	$stats90 = $dash->stats_90();
	//	echo "<pre>";
	//	var_dump($stats30);
	//	echo "</pre>";
	//	die;
	
	$shipments = $dash->shipments_guage();
	$loads = $dash->loads_guage();
	
	if( defined('_STS_GOOGLE_CHARTS') && count($stats90) > 0 ) {
		
		echo '<div class="row">
		 <div class="col-md-4">
			<div id="guage1" style="min-width: 310px; height: 300px; margin: 0 auto"></div>
		';
		echo "<script language=\"JavaScript\" type=\"text/javascript\"><!--
			
			$(document).ready( function () {
				google.charts.load('current', {'packages':['gauge']});
				google.charts.setOnLoadCallback(drawChart);
				
				function drawChart() {
				
					var data = google.visualization.arrayToDataTable([
					  ['Label', 'Value'],
					  ['Wk Logins', ".$profile['WEEK_LOGINS']."],
					]);
					
					var max_users = ".$profile['USERS'].";
					
					var options = {
					  width: 400, height: 300,
					  max: parseInt(max_users),
					  redFrom: parseInt(max_users * 0.90), redTo: parseInt(max_users),
					  yellowFrom: parseInt(max_users * 0.75), yellowTo: parseInt(max_users * 0.90),
					  minorTicks: 1
					};
					
					var chart = new google.visualization.Gauge(document.getElementById('guage1'));
					
					chart.draw(data, options);
				};
			});
		//--></script>
		";
		
		echo '</div>
			<div class="col-md-4">
			<div id="guage2" style="min-width: 310px; height: 300px; margin: 0 auto"></div>
		';
		echo "<script language=\"JavaScript\" type=\"text/javascript\"><!--
			
			$(document).ready( function () {
				google.charts.load('current', {'packages':['gauge']});
				google.charts.setOnLoadCallback(drawChart);
				
				function drawChart() {
				
					var data = google.visualization.arrayToDataTable([
					  ['Label', 'Value'],
					  ['Wk Shipments', ".$shipments['SHIPMENTS']."],
					]);
					
					var max_shipments = ".$shipments['MAX_SHIPMENTS'].";
					
					var options = {
					  width: 400, height: 300,
					  max: parseInt(max_shipments),
					  redFrom: parseInt(max_shipments * 0.90), redTo: parseInt(max_shipments),
					  yellowFrom: parseInt(max_shipments * 0.75), yellowTo: parseInt(max_shipments * 0.90),
					  minorTicks: 1
					};
					
					var chart = new google.visualization.Gauge(document.getElementById('guage2'));
					
					chart.draw(data, options);
				};
			});
		//--></script>
		";
		
		echo '</div>
			<div class="col-md-4">
			<div id="guage3" style="min-width: 310px; height: 300px; margin: 0 auto"></div>
		';
		echo "<script language=\"JavaScript\" type=\"text/javascript\"><!--
			
			$(document).ready( function () {
				google.charts.load('current', {'packages':['gauge']});
				google.charts.setOnLoadCallback(drawChart);
				
				function drawChart() {
				
					var data = google.visualization.arrayToDataTable([
					  ['Label', 'Value'],
					  ['Wk Loads', ".$loads['LOADS']."],
					]);
					
					var max_shipments = ".$loads['MAX_LOADS'].";
					
					var options = {
					  width: 400, height: 300,
					  max: parseInt(max_shipments),
					  redFrom: parseInt(max_shipments * 0.90), redTo: parseInt(max_shipments),
					  yellowFrom: parseInt(max_shipments * 0.75), yellowTo: parseInt(max_shipments * 0.90),
					  minorTicks: 1
					};
					
					var chart = new google.visualization.Gauge(document.getElementById('guage3'));
					
					chart.draw(data, options);
				};
			});
		//--></script>
		";
						
		echo '</div>
		</div>
		<div class="well well-sm">
		<div id="chart1" style="min-width: 310px; height: 400px; margin: 0 auto"></div>
		</div>';
		echo "<script language=\"JavaScript\" type=\"text/javascript\"><!--
			
			$(document).ready( function () {
				google.charts.setOnLoadCallback(drawChart);
				function drawChart() {
				var data1 = google.visualization.arrayToDataTable([
					['Date', 'Logins'],
					";
				$max = 0;
				foreach( $stats90 as $row ) {
					echo "['".addslashes($row["DT"])."', ".$row["LOGINS"]." ],
					";
					if( $row["LOGINS"] > $max ) $max = $row["LOGINS"];
				}
					
				echo "
				]);
				
				var options1 = {
					title: 'Logins For Last 90 days',
					height: 400,
					chartArea:{left:80,top:40,width:'80%',height:'60%'},
					legend: { position: 'none' },
					//hAxis: { 
					//	slantedText: true, 
					//	slantedTextAngle: 60 // here you can even use 180 
					//},
					vAxis: {
						minValue: 0,
						title: 'Logins',
					},
					
					hAxis: {
						slantedText:true,
						slantedTextAngle:70,
					}
				};
				
				var chart1 = new google.visualization.ColumnChart($('#chart1')[0]);
				chart1.draw(data1, options1);
				}
	
	
			});
		//--></script>
		";

		echo '<div class="well well-sm">
		<div id="chart2" style="min-width: 310px; height: 400px; margin: 0 auto"></div>
		</div>';
		echo "<script language=\"JavaScript\" type=\"text/javascript\"><!--
			
			$(document).ready( function () {
				google.charts.setOnLoadCallback(drawChart);
				function drawChart() {
				var data2 = google.visualization.arrayToDataTable([
					['Date', 'Shipments', 'Loads'],
					";
				$max = 0;
				foreach( $stats90 as $row ) {
					echo "['".addslashes($row["DT"])."', ".$row["SHIPMENTS"].", ".$row["LOADS"]." ],
					";
					if( $row["SHIPMENTS"] > $max ) $max = $row["SHIPMENTS"];
					if( $row["LOADS"] > $max ) $max = $row["LOADS"];
				}
					
				echo "
				]);
				
				var options2 = {
					title: 'Shipments Entered / Loads Dispatched For Last 90 days',
					height: 400,
					chartArea:{left:80,top:40,width:'80%',height:'60%'},
					legend: { position: 'right' },
					//hAxis: { 
					//	slantedText: true, 
					//	slantedTextAngle: 60 // here you can even use 180 
					//},
					vAxis: {
						minValue: 0,
						title: 'Shipments/Loads',
					},
					
					hAxis: {
						slantedText:true,
						slantedTextAngle:70,
					}
				};
				
				var chart2 = new google.visualization.ColumnChart($('#chart2')[0]);
				chart2.draw(data2, options2);
				}
	
	
			});
		//--></script>
		";

	/*	echo '<div class="well well-sm">
		<div id="chart3" style="min-width: 310px; height: 400px; margin: 0 auto"></div>
		</div>';
		echo "<script language=\"JavaScript\" type=\"text/javascript\"><!--
			
			$(document).ready( function () {
				google.charts.setOnLoadCallback(drawChart);
				function drawChart() {
				var data3 = google.visualization.arrayToDataTable([
					['Date', 'Loads'],
					";
				$max = 0;
				foreach( $stats30 as $row ) {
					echo "['".addslashes($row["DT"])."', ".$row["LOADS"]." ],
					";
					if( $row["LOADS"] > $max ) $max = $row["LOADS"];
				}
					
				echo "
				]);
				
				var options3 = {
					title: 'Loads For Last 30 days',
					height: 400,
					chartArea:{left:80,top:40,width:'90%',height:'60%'},
					legend: { position: 'none' },
					//hAxis: { 
					//	slantedText: true, 
					//	slantedTextAngle: 60 // here you can even use 180 
					//},
					vAxis: {
						minValue: 0,
						title: 'Loads',
						ticks: [";
						for( $c =0; $c <= $max + 1; $c++ )
							echo $c.', ';
						echo "]
					},
					
					hAxis: {
						slantedText:true,
						slantedTextAngle:70,
					}
				};
				
				var chart3 = new google.visualization.ColumnChart($('#chart3')[0]);
				chart3.draw(data3, options3);
				}
	
	
			});
		//--></script>
		";
		*/
	}
	

	//$rslt = new sts_result( $dash, false, $sts_debug );
	//echo $rslt->render( $sts_result_stats_layout, $sts_result_stats_edit );

}

?>
</div>
<?php

require_once( "include/footer_inc.php" );
?>
