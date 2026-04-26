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
require_once( "include/sts_clientrate_mng_class.php" );

$client_table = new sts_clientrate_mng($exspeedite_db, $sts_debug);

if( is_array($_GET) && isset($_GET['CHECK']) ) {
	$check = $client_table->fetch_rows("RATE_CODE = '".$_GET['CHECK']."'", "CLIENT_RATE_ID");
	echo ( is_array($check) && count($check) > 0 ) ? 'MATCH' : 'NONE';
	die;	
}

$sts_subtitle = "Add Client Rates";
require_once( "include/header_inc.php" );
require_once( "include/navbar_inc.php" );

$client_form = new sts_form( $sts_form_addclientrate_form , $sts_form_add_clientrate_fields, $client_table, $sts_debug);

if( isset($_POST) && count($_POST) > 0 ) {	// Process completed form
	$result = $client_form->process_add_form();
	
	if( $sts_debug ) die; // So we can see the results
	if( $result ) 
		reload_page ( "exp_listclientrates.php");
}

?>
<div class="container-full theme-showcase" role="main">
<div class="well  well-md">
<?php

if( isset($value) && is_array($value) && $result == false ) {	// If error occured
	echo "<p><strong>Error:</strong> ".$client_table->error()."</p>";
	echo $client_form->render( $value );
} 
else
{
	echo $client_form->render();
}

?>
</div>
</div>

	<script language="JavaScript" type="text/javascript"><!--
		
		$(document).ready( function () {

			function check_hidden() {
				if( $('select#CATEGORY').find(":selected").text() == 'Shipper' ) {
					$('div#SHIPPER_CLIENT_CODE').prop('hidden',false).change();
				} else {
					$('div#SHIPPER_CLIENT_CODE').prop('hidden', 'hidden').change();						}

				if( $('select#CATEGORY').find(":selected").text() == 'Consignee' ) {
					$('div#CONS_CLIENT_CODE').prop('hidden',false).change();
				} else {
					$('div#CONS_CLIENT_CODE').prop('hidden', 'hidden').change();						}

				if( $('select#CATEGORY').find(":selected").text() == 'Billable Commodity' ) {
					$('div#COMMODITY').prop('hidden',false).change();
				} else {
					$('div#COMMODITY').prop('hidden', 'hidden').change();								}
			}
			
			$('select#CATEGORY').change(function () {
				check_hidden();
			});

			check_hidden();
			
			$( "#addclientrate" ).submit(function( event ) {
				$.ajax({
					async: false,
					url: 'exp_addclientsrates.php',
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

		});
	//--></script>


<?php
require_once( "include/footer_inc.php" );
?>