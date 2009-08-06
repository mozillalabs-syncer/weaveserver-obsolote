<?php
	if (file_exists($_SERVER['HTTP_HOST'] . '_constants.php'))
		require_once $_SERVER['HTTP_HOST'] . '_constants.php';
	else
		require_once 'default_constants.php';
?>