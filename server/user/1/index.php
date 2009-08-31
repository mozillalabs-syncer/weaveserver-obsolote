<?php

# ***** BEGIN LICENSE BLOCK *****
# Version: MPL 1.1/GPL 2.0/LGPL 2.1
#
# The contents of this file are subject to the Mozilla Public License Version
# 1.1 (the "License"); you may not use this file except in compliance with
# the License. You may obtain a copy of the License at
# http://www.mozilla.org/MPL/
#
# Software distributed under the License is distributed on an "AS IS" basis,
# WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
# for the specific language governing rights and limitations under the
# License.
#
# The Original Code is Weave Basic Object Server
#
# The Initial Developer of the Original Code is
# Mozilla Labs.
# Portions created by the Initial Developer are Copyright (C) 2008
# the Initial Developer. All Rights Reserved.
#
# Contributor(s):
#	Toby Elliott (telliott@mozilla.com)
#
# Alternatively, the contents of this file may be used under the terms of
# either the GNU General Public License Version 2 or later (the "GPL"), or
# the GNU Lesser General Public License Version 2.1 or later (the "LGPL"),
# in which case the provisions of the GPL or the LGPL are applicable instead
# of those above. If you wish to allow use of your version of this file only
# under the terms of either the GPL or the LGPL, and not to allow others to
# use your version of this file under the terms of the MPL, indicate your
# decision by deleting the provisions above and replace them with the notice
# and other provisions required by the GPL or the LGPL. If you do not delete
# the provisions above, a recipient may use your version of this file under
# the terms of any one of the MPL, the GPL or the LGPL.
#
# ***** END LICENSE BLOCK *****

	require_once 'weave_user_constants.php';
	require_once 'weave_storage.php';
	require_once 'weave_user.php';

	function report_problem($message, $code = 503)
	{
		$headers = array('400' => '400 Bad Request',
					'401' => '401 Unauthorized',
					'404' => '404 Not Found',
					'503' => '503 Service Unavailable');
		header('HTTP/1.1 ' . $headers{$code},true,$code);
		echo json_encode($message);
		exit;
	}

	function verify_password_strength($password, $user)
	{
		if (!$password || $password == $user || strlen($password) < 8) #basic password checking
				return 0;
		return 1;

	}
	
	function verify_user($url_user, $authdb)
	{
		$auth_user = array_key_exists('PHP_AUTH_USER', $_SERVER) ? $_SERVER['PHP_AUTH_USER'] : null;
		$auth_pw = array_key_exists('PHP_AUTH_PW', $_SERVER) ? $_SERVER['PHP_AUTH_PW'] : null;
	
		if (is_null($auth_user) || is_null($auth_pw)) 
		{
			/* CGI/FCGI auth workarounds */
			$auth_str = null;
			if (array_key_exists('Authorization', $_SERVER))
				/* Standard fastcgi configuration */
				$auth_str = $_SERVER['Authorization'];
			else if (array_key_exists('AUTHORIZATION', $_SERVER))
				/* Alternate fastcgi configuration */
				$auth_str = $_SERVER['AUTHORIZATION'];
			else if (array_key_exists('HTTP_AUTHORIZATION', $_SERVER))
				/* IIS/ISAPI and newer (yet to be released) fastcgi */
				$auth_str = $_SERVER['HTTP_AUTHORIZATION'];
			else if (array_key_exists('REDIRECT_HTTP_AUTHORIZATION', $_SERVER))
				/* mod_rewrite - per-directory internal redirect */
				$auth_str = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
			if (!is_null($auth_str)) 
			{
				/* Basic base64 auth string */
				if (preg_match('/Basic\s+(.*)$/', $auth_str)) 
				{
					$auth_str = substr($auth_str, 6);
					$auth_str = base64_decode($auth_str, true);
					if ($auth_str != FALSE) {
						$tmp = explode(':', $auth_str);
						if (count($tmp) == 2) 
						{
							$auth_user = $tmp[0];
							$auth_pw = $tmp[1];
						}
					}
				}
			}
		}

		if (!$auth_user) #do this first to avoid the cryptic error message if auth is missing
			report_problem('Authentication failed', '401');

		$auth_user = strtolower($auth_user);
		
		if ($auth_user != $url_user)
			report_problem(5, 400);

		if (!$authdb->authenticate_user($auth_user, $auth_pw))
			report_problem('Authentication failed', '401');

		return 1;
	}
	
	$path = array_key_exists('PATH_INFO', $_SERVER) ? $_SERVER['PATH_INFO'] : '/';
	$path = substr($path, 1); #chop the lead slash
	list($url_user, $action) = explode('/', $path.'/');

	$url_user = strtolower($url_user);
	
	if (!$url_user)
		report_problem(3, 400);

	if (!$action)
		$action = 'none';

	try
	{
		$authdb = get_auth_object();
		
		if ($_SERVER['REQUEST_METHOD'] == 'GET')
		{
			switch($action)
			{
				case 'node':
					if (defined('WEAVE_REGISTER_STORAGE_LOCATION'))
						exit('https://' . WEAVE_REGISTER_STORAGE_LOCATION . '/');
					if ($location = $authdb->get_user_location($url_user))
						exit('https://' . $location . '/');					
					report_problem("No location", 404);
				case 'none':
					print $authdb->user_exists($url_user) ? 1: 0;
					exit;
				case 'email':
					verify_user($url_user, $authdb);					
					print $authdb->get_user_email($url_user);
					exit;
				default:
					report_problem(1, 400);
			}
		}
		else if ($_SERVER['REQUEST_METHOD'] == 'PUT') #create a new user
		{
			$putdata = fopen("php://input", "r");
			$jsonstring = '';
			while ($data = fread($putdata,2048)) {$jsonstring .= $data;}
			fclose($putdata);
			$json = json_decode($jsonstring, true);

			if ($json === null)
				report_problem(6, 400);

			if (!(defined('WEAVE_REGISTER_ADMIN_SECRET') 
					&& array_key_exists('HTTP_X_WEAVE_SECRET', $_SERVER)
					&& WEAVE_REGISTER_ADMIN_SECRET == $_SERVER['HTTP_X_WEAVE_SECRET']))
			{
				if (defined('WEAVE_REGISTER_USE_CAPTCHA') && WEAVE_REGISTER_USE_CAPTCHA)
				{
					require_once 'recaptcha.php';
					if (!$json['captcha-challenge'] || !$json['captcha-response'])
						report_problem(2, 400);
					
					$captcha_check = recaptcha_check_answer(RECAPTCHA_PRIVATE_KEY, $_SERVER['REMOTE_ADDR'], $json['captcha-challenge'], $json['captcha-response']);
					if (!$captcha_check->is_valid)
						report_problem(2, 400);
				}
			}


			if (!preg_match('/^[A-Z0-9._-]+$/i', $url_user)) 
				report_problem(3, 400);

			if ($authdb->user_exists($url_user))
				report_problem(4, 400);


			$password = $json['password'];
			$email = $json['email'];

			if (!verify_password_strength($password, $url_user))
			{
				report_problem(9, 400);
			}
			
			try
			{
				$storagedb = get_storage_write_object($url_user);	
				$storagedb->create_user($url_user, $password);
				$authdb->create_user($url_user, $password, $email);
			}
			catch(Exception $e)
			{
				report_problem($e->getMessage(), $e->getCode());
			}
			exit(json_encode($url_user));
		}
		else if ($_SERVER['REQUEST_METHOD'] == 'POST') #manipulate a user
		{
			verify_user($url_user, $authdb);
			
			#set an X-Weave-Alert header if the user needs to know something
			if ($alert = $authdb->get_user_alert())
				header("X-Weave-Alert: $alert", false);

			switch($action)
			{
				case 'password':
					$postdata = fopen("php://input", "r");
					$new_password = fread($postdata,2048);
					fclose($postdata);
					
					if (!verify_password_strength($new_password, $url_user))
						report_problem(9, 400);
					
					$authdb->update_password($url_user, $new_password);
					exit("1");
				case 'email':
					$putdata = fopen("php://input", "r");
					$new_email = fread($putdata,2048);
					fclose($postdata);

					$authdb->update_email($url_user, $new_email);
					exit($new_email);
				default:
					report_problem(1, 400);
			}			
		}
		else if ($_SERVER['REQUEST_METHOD'] == 'DELETE') #delete a user from the server. Need to delete their storage as well.
		{
			if (!(defined('WEAVE_REGISTER_ADMIN_SECRET') 
					&& array_key_exists('HTTP_X_WEAVE_SECRET', $_SERVER)
					&& WEAVE_REGISTER_ADMIN_SECRET == $_SERVER['HTTP_X_WEAVE_SECRET']))
			{
				verify_user($url_user, $authdb);
			}
			$storagedb = get_storage_write_object($url_user);	

			try
			{
				$authdb->delete_user($url_user);
				$storagedb->delete_user();
			}
			catch(Exception $e)
			{
				report_problem($e->getMessage(), $e->getCode());
			}
		}
	}
	catch(Exception $e)
	{
		report_problem($e->getMessage(), $e->getCode());
	}

	print "success";
	
?>
	