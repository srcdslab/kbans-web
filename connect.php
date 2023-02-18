<?php
	// ---------------------------------------------------
	//  Directories
	// ---------------------------------------------------
	define('ROOT', dirname(__FILE__) . "/");

	if (!file_exists(ROOT.'/config.php')) {
		die('Missing config.php.');
	}
	require_once(ROOT.'/config.php');

	$GLOBALS['DB'] = mysqli_connect(DB_HOST,
						DB_USER,
						DB_PASSWORD,
						DB_NAME
						);

	// check connection
	if($GLOBALS['DB']->errno){
		echo 'Connection error: '. $GLOBALS['DB']->error;
	}

    $GLOBALS['SBPP'] = mysqli_connect( SBPP_DB_HOST,
								SBPP_DB_USER,
                                SBPP_DB_PASSWORD,
                                SBPP_DB_NAME);

	if($GLOBALS['SBPP']->errno){
		echo 'Connection error: '. $GLOBALS['SBPP']->error;
	}

	$GLOBALS['SERVER_FORUM_NAME'] = SERVER_FORUM_NAME;
	$GLOBALS['SERVER_FORUM_URL'] = SERVER_FORUM_URL;
	$GLOBALS['SERVER_NAME'] = SERVER_NAME;
	$GLOBALS['SERVER_FASTDL'] = SERVER_FASTDL;
	$GLOBALS['STEAM_API_KEY'] = STEAM_API_KEY;
	$GLOBALS['STEAM_GROUP'] = STEAM_GROUP;

	/* TIME ZONE */
	date_default_timezone_set('UTC');
?>
