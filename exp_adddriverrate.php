<?php 
// Set flag that this is a parent file
define( '_STS_INCLUDE', 1 );

// Setup Session
require_once( "include/sts_session_setup.php" );

require_once( "include/sts_config.php" );
$sts_debug =	(isset($_GET['debug'])  || isset($_POST['debug'])) && in_group( EXT_GROUP_DEBUG );
//$sts_debug = isset($_POST) && count($_POST) > 0 ;
require_once( "include/sts_session_class.php" );

$my_session = sts_session::getInstance( $exspeedite_db, $sts_debug );
//$my_session->access_check( $sts_table_access[DRIVER_RATE_TABLE] );	// Make sure we should be here

require_once( "include/sts_form_class.php" );
require_once( "include/sts_driverrate_mng_class.php" );
$driver_table = new sts_driverrate_mng($exspeedite_db, $sts_debug);

if( is_array($_GET) && isset($_GET['CHECK']) ) {
	$check = $driver_table->fetch_rows("RATE_CODE = '".$_GET['CHECK']."'", "RATE_ID");
	echo ( is_array($check) && count($check) > 0 ) ? 'MATCH' : 'NONE';
	die;	
}

$sts_subtitle = "Add Driver Rates";
require_once( "include/header_inc.php" );
require_once( "include/navbar_inc.php" );

if( ! taxable_enabled() ) {
	unset($sts_form_add_driverrate_fields['ISTAXABLE']);
	$match = preg_quote('<!-- TAXO1 -->').'(.*)'.preg_quote('<!-- TAXO2 -->');
	$sts_form_adddriverrate_form['layout'] = preg_replace('/'.$match.'/s', '',
		$sts_form_adddriverrate_form['layout'], 1);	
}

$driver_form = new sts_form( $sts_form_adddriverrate_form , $sts_form_add_driverrate_fields, $driver_table, $sts_debug);

if( isset($_POST) && count($_POST) > 0 ) {	// Process completed form
	$result = $driver_form->process_add_form();
	
	if( $sts_debug ) die; // So we can see the results
	if( $result ) 
		reload_page ( "exp_listdriverrates.php");
}

?>
<div class="container-full theme-showcase" role="main">
<div class="well  well-md">
<?php

if( isset($value) && is_array($value) && $result == false ) {	// If error occured
	echo "<p><strong>Error:</strong> ".$driver_table->error()."</p>";
	echo $driver_form->render( $value );
} 
else
{
	echo $driver_form->render();
}

?>
</div>
</div>

	<script language="JavaScript" type="text/javascript"><!--
		
		$(document).ready( function () {
			function update_percent() {
				if( $('select#CATEGORY option:selected').text() == 'Per Mile + Rate' ) {
					$('#percent').prop('hidden',false);
				} else {
					$('#percent').prop('hidden', 'hidden');
				}
			}
			
			$( "#adddriverrate" ).submit(function( event ) {
				$.ajax({
					async: false,
					url: 'exp_adddriverrate.php',
					data: {
						CHECK: $('input#RATE_CODE').val()
					},
					success: function(data) {
					//	console.log('X'+data.trim()+'X');
						if( data.trim() == 'MATCH') {
							alert('This Rate code exists. Please change it and try again.');
							event.preventDefault();
						}
					}
				});
			});


			$('select#CATEGORY').change(function () {
				update_percent();
			});
			
			update_percent();
			
		});
	//--></script>

<?php
require_once( "include/footer_inc.php" );
?>