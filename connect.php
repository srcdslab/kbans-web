<?php
	// ---------------------------------------------------
	//  Directories
	// ---------------------------------------------------
	define('ROOT', dirname(__FILE__) . "/");

	if (!file_exists(ROOT.'/config.php')) {
		die('Missing config.php.');
	}
	require_once(ROOT.'/config.php');

	/*
	 * Since PHP 8.1 mysqli reports a failed connection by throwing
	 * mysqli_sql_exception, not by returning false, so the `if (!$GLOBALS['DB'])`
	 * guards here were dead code -- the failure surfaced as an uncaught
	 * exception whose stack trace lists the mysqli_connect() arguments, database
	 * password included, whenever display_errors is on. Both connections are now
	 * opened through one helper that catches the failure, logs the detail and
	 * shows the visitor a generic message.
	 */
	function connectDatabase($label, $host, $user, $password, $name)
	{
		try {
			$connection = mysqli_connect($host, $user, $password, $name);
		} catch (mysqli_sql_exception $e) {
			error_log("$label database connection failed: " . $e->getMessage());
			http_response_code(503);
			die('The database is currently unavailable. Please try again later.');
		}

		if (!$connection) {
			error_log("$label database connection failed: " . mysqli_connect_error());
			http_response_code(503);
			die('The database is currently unavailable. Please try again later.');
		}

		/* Match the storage charset so player names and reasons outside latin1
		   are not mangled on the way in or out, and real_escape_string() works
		   against the right character set. */
		mysqli_set_charset($connection, 'utf8mb4');

		return $connection;
	}

	$GLOBALS['DB'] = connectDatabase('Kban', DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
	$GLOBALS['SBPP'] = connectDatabase('SBPP', SBPP_DB_HOST, SBPP_DB_USER, SBPP_DB_PASSWORD, SBPP_DB_NAME);

	$GLOBALS['SERVER_FORUM_NAME'] = SERVER_FORUM_NAME;
	$GLOBALS['SERVER_FORUM_URL'] = SERVER_FORUM_URL;
	$GLOBALS['SERVER_NAME'] = SERVER_NAME;
	$GLOBALS['SERVER_FASTDL'] = SERVER_FASTDL;
	$GLOBALS['STEAM_API_KEY'] = STEAM_API_KEY;
	$GLOBALS['STEAM_GROUP'] = STEAM_GROUP;
	$GLOBALS['DISCORD'] = DISCORD;
	$GLOBALS['SECRET_KEY'] = SECRET_KEY;

	/* TIME ZONE */
	date_default_timezone_set('UTC');
?>
