<?php

// Include configuration
require_once('config.php');

// Include libraries
// project source: https://github.com/firebase/php-jwt
// See LICENSE file: vendor/firebase/php-jwt/Firebase/PHP-JWT/LICENSE
require_once('vendor/firebase/php-jwt/Firebase/PHP-JWT/Authentication/JWT.php');

// Include class files owned by this project
require_once('app.class.php');
require_once('TribeHRPanelClient.class.php');

// Try to initialize the database
try {
	$databaseConnection = new PDO('sqlite:' . __DIR__ . '/db/addressMapper.sqlite3');
} catch (PDOException $e) {
	echo 'Database initialization error: '. $e->getMessage();
	die();
}
