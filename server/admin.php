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
					'405' => '405 Method Not Allowed',
					'503' => '503 Service Unavailable');
		header('HTTP/1.1 ' . $headers{$code},true,$code);
		echo json_encode($message);
		exit;
	}
	

	if (! $_SERVER['REQUEST_METHOD'] == 'POST')
	{
		report_problem("Illegal Method", 405);
	}

	if (WEAVE_USER_ADMIN_SECRET != (ini_get('magic_quotes_gpc') ? stripslashes($_POST['secret']) : $_POST['secret']))
	{
		report_problem("Secret missing or incorrect", 400);
	}
	

	$username = array_key_exists('user', $_POST) ? (ini_get('magic_quotes_gpc') ? stripslashes($_POST['user']) : $_POST['user']) : null;
	$password = array_key_exists('pass', $_POST) ? (ini_get('magic_quotes_gpc') ? stripslashes($_POST['pass']) : $_POST['pass']) : null;
	$email = array_key_exists('email', $_POST) ? (ini_get('magic_quotes_gpc') ? stripslashes($_POST['email']) : $_POST['email']) : null;


	try
	{
		$authdb = get_auth_object();
		
		#can't initiate the storage object yet, since may not have created the user
		$storagedb = get_storage_object($username, null, 1);
		
		switch($_POST['function'])
		{
			case 'check':
				print json_encode($authdb->user_exists($username) ? 1: 0);
				exit;
			case 'create':
				if (!preg_match('/^[A-Z0-9._-]+/i', $username)) 
				{
					report_problem("Invalid characters in username", 400);
				}
				if ($authdb->user_exists($username))
				{
					report_problem("User already exists", 400);
				}
				$storagedb->create_user($username, $password);
				$authdb->create_user($username, $password, $email);
				break;
			case 'update':
				$authdb->update_password($username, $password);
				break;
			case 'delete':
				if (!preg_match('/^[A-Z0-9._-]+/i', $username))
				{
					report_problem("Invalid characters in username", 400);
				}
				$storagedb->open_connection(); #need a connection for mysql
				$storagedb->delete_user();
				$authdb->delete_user($username);
				break;
			default:
				report_problem("Unknown function", 400);
		}
	}
	catch(Exception $e)
	{
		report_problem($e->getMessage(), $e->getCode());
	}

	print "success";
	
?>
	