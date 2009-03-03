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

	require_once 'weave_authentication.php';
	require_once 'weave_storage.php';
	require_once 'weave_constants.php';

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
	
	
	$path = array_key_exists('PATH_INFO', $_SERVER) ? $_SERVER['PATH_INFO'] : '/';
	$path = substr($path, 1); #chop the lead slash
	list($action, $info) = explode('/', $path.'/');
	

 	$username = array_key_exists('uid', $_POST) ? (ini_get('magic_quotes_gpc') ? stripslashes($_POST['uid']) : $_POST['uid']) : null;
	$password = array_key_exists('password', $_POST) ? (ini_get('magic_quotes_gpc') ? stripslashes($_POST['password']) : $_POST['password']) : null;


	try
	{
		$authdb = get_auth_object();
		$storagedb = get_storage_write_object($username, null, 1);
		
		switch($action)
		{
			case 'location':
				print json_encode($authdb->get_user_location($info) ? 1: 0);
				exit;
			case 'check':
				print json_encode($authdb->user_exists($info) ? 1: 0);
				exit;
			case 'chpwd':
				$new_password = array_key_exists('new', $_POST) ? (ini_get('magic_quotes_gpc') ? stripslashes($_POST['new']) : $_POST['new']) : null;
				if (!$authdb->authenticate_user($username, $password))
				{
					report_problem('Authentication failed', '401');
				}
				$authdb->update_password($username, $new);
				break;
			case 'new':
				if ($_SERVER['REQUEST_METHOD'] == 'GET')
				{
					if (WEAVE_REGISTER_USE_CAPTCHA)
					{
						require_once 'captcha.inc';
						print captcha_html();
					}
				}
				else
				{
					if (WEAVE_REGISTER_USE_CAPTCHA)
					{
						require_once 'captcha.inc';
 						$challenge = array_key_exists('recaptcha_challenge_field', $_POST) ? (ini_get('magic_quotes_gpc') ? stripslashes($_POST['recaptcha_challenge_field']) : $_POST['recaptcha_challenge_field']) : null;
						$response = array_key_exists('recaptcha_response_field', $_POST) ? (ini_get('magic_quotes_gpc') ? stripslashes($_POST['recaptcha_response_field']) : $_POST['recaptcha_response_field']) : null;
						if (!captcha_verify)
						{
							report_problem("2", 400);
						}
					}
					if (!preg_match('/^[A-Z0-9._-]+/i', $username)) 
					{
						report_problem("3", 400);
					}
					if ($authdb->user_exists($username))
					{
						report_problem("4", 400);
					}
					$email = array_key_exists('mail', $_POST) ? (ini_get('magic_quotes_gpc') ? stripslashes($_POST['mail']) : $_POST['mail']) : null;
					$storagedb->create_user($username, $password);
					$authdb->create_user($username, $password, $email);
				}
				break;
			default:
				report_problem("1", 400);
		}
	}
	catch(Exception $e)
	{
		report_problem($e->getMessage(), $e->getCode());
	}

	print "success";
	
?>
	