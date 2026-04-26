<?php 
// Set flag that this is a parent file
define( '_STS_INCLUDE', 1 );

// Setup Session
require_once( "include/sts_session_setup.php" );

require_once( "include/sts_config.php" );
$sts_debug = (isset($_GET['debug'])  || isset($_POST['debug'])) && in_group( EXT_GROUP_DEBUG );
require_once( "include/sts_session_class.php" );

$my_session = sts_session::getInstance( $exspeedite_db, $sts_debug );
$my_session->access_check( EXT_GROUP_ADMIN );	// Make sure we should be here

require_once( "include/sts_form_class.php" );
require_once( "include/sts_clientrate_mng_class.php" );

$cr_table = sts_clientrate_mng::getInstance($exspeedite_db, $sts_debug);

if( is_array($_GET) && isset($_GET['CHECK']) && isset($_GET['CODE']) ) {
	$check = $cr_table->fetch_rows("RATE_CODE = '".$_GET['CHECK']."'
	AND CLIENT_RATE_ID != ".$_GET['CODE'], "CLIENT_RATE_ID");
	echo ( is_array($check) && count($check) > 0 ) ? 'MATCH' : 'NONE';
	die;	
}

$sts_subtitle = "Edit Client Rates";
require_once( "include/header_inc.php" );
require_once( "include/navbar_inc.php" );

$cr_form = new sts_form($sts_form_editclientrate_form, $sts_form_edit_clientrate_fields, $cr_table, $sts_debug);

if( isset($_POST) && count($_POST) > 0 ) {		// Process completed form
	$result = $cr_form->process_edit_form();

	if( $result ) {
		if( $sts_debug ) die; // So we can see the results
		reload_page ( "exp_listclientrates.php" );
	}
}

?>
<div class="container-full theme-showcase" role="main">

<div class="well  well-lg">
<?php

if( isset($value) && is_array($value) && $result == false ) {	// If error occured
	echo "<p><strong>Error:</strong> ".$em_table->error()."</p>";
	echo $cr_form->render( $value );
} else if( isset($_GET['CODE']) ) {
	$result = $cr_table->fetch_rows($cr_table->primary_key." = ".$_GET['CODE']);
	echo $cr_form->render( $result[0] );
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
			
			
			
			$( "#editclientrate" ).submit(function( event ) {
				$.ajax({
					async: false,
					url: 'exp_editclientrate.php',
					data: {
						CODE: $('input#CLIENT_RATE_ID').val(),
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

