<?php 

// $Id: exp_sudo.php 5449 2025-03-10 23:59:48Z dev $
// Allow superadmin to switch to another user. No pw required.
// Usage: exp_sudo.php?user=duncan

// Set flag that this is a parent file
define( '_STS_INCLUDE', 1 );

// Setup Session
require_once( "include/sts_session_setup.php" );

require_once( "include/sts_config.php" );
$sts_debug = isset($_GET['debug']) && in_group( EXT_GROUP_DEBUG );
require_once( "include/sts_session_class.php" );

$my_session = sts_session::getInstance( $exspeedite_db, $sts_debug );
$my_session->access_check( EXT_GROUP_USER );	// Make sure we should be here

$sts_subtitle = "Change User";
require_once( "include/header_inc.php" );
require_once( "include/navbar_inc.php" );
require_once( "include/sts_user_class.php" );

$user_table = sts_user::getInstance( $exspeedite_db, $sts_debug );

if( $my_session->superadmin() && ! empty($_GET['user'])) {
	$my_session->sudo( $user_table, urldecode($_GET['user']) );
	sleep(2);
}
if( ! $sts_debug )
	reload_page( "index.php" );


require_once( "include/footer_inc.php" );
?>