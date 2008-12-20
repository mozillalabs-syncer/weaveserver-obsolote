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

	require_once 'weave_storage.php';
	require_once 'weave_authentication.php';
	require_once 'weave_basic_object.php';
	require_once 'weave_constants.php';
	
	
	function jsonize($obj) { return $obj->json(); }

	function report_problem($message, $code = 503)
	{
		$headers = array('400' => '400 Bad Request',
					'401' => '401 Unauthorized',
					'404' => '404 Not Found',
					'503' => '503 Service Unavailable');
		header('HTTP/1.1 ' . $headers{$code},true,$code);
		
		if ($code == 401)
		{
			header('WWW-Authenticate: Basic realm="Weave"');
		}
		
		echo json_encode($message);
		exit;
	}
	
	
	header("Content-type: application/json");
	
	$path = array_key_exists('PATH_INFO', $_SERVER) ? $_SERVER['PATH_INFO'] : '/';
	$path = substr($path, 1); #chop the lead slash
	list($username, $collection, $id) = explode('/', $path.'//');
	
	$auth_user = array_key_exists('PHP_AUTH_USER', $_SERVER) ? $_SERVER['PHP_AUTH_USER'] : null;
	$auth_pw = array_key_exists('PHP_AUTH_PW', $_SERVER) ? $_SERVER['PHP_AUTH_PW'] : null;

	#Basic path validation. No point in going on if these are missing
	if (!$username)
	{
		report_problem('3', 400);
	}
	
	#Auth the user
	try 
	{
		$authdb = get_auth_object();
		if (!$authdb->authenticate_user($auth_user, $auth_pw))
		{
			report_problem('Authentication failed', '401');
		}
	}
	catch(Exception $e)
	{
		report_problem($e->getMessage(), $e->getCode());
	}

	if (!$collection)
	{
		echo json_encode("1");
		exit;
	}
	

	#user passes, onto actually getting the data
	try
	{
		$db = get_storage_object($username, null, WEAVE_SHARE_DBH ? $authdb->get_connection() : null);	
	}
	catch(Exception $e)
	{
		report_problem($e->getMessage(), $e->getCode());
	}
	

	if ($_SERVER['REQUEST_METHOD'] == 'GET')
	{

		if ($auth_user != $username)
		{
			report_problem("5", 401);
		}
		
		
		if ($id) #retrieve a single record
		{
			try
			{
				$wbo = $db->retrieve_objects($collection, $id, 1); #get the full contents of one record
			}
			catch(Exception $e)
			{
				report_problem($e->getMessage(), $e->getCode());
			}
			
			if (count($wbo) > 0)
			{
				echo $wbo[0]->json();
			}
			else
			{
				report_problem("record not found", 404);
			}
		}
		else #retrieve a batch of records
		{
			$full = array_key_exists('full', $_GET) ? $_GET['full'] : null;
			try 
			{
				$ids = $db->retrieve_objects($collection, null, $full, 
							array_key_exists('parentid', $_GET) ? $_GET['parentid'] : null, 
							array_key_exists('modified', $_GET) ? $_GET['modified'] : null, 
							array_key_exists('sort', $_GET) ? $_GET['sort'] : null, 
							array_key_exists('limit', $_GET) ? $_GET['limit'] : null, 
							array_key_exists('offset', $_GET) ? $_GET['offset'] : null);
			}
			catch(Exception $e)
			{
				report_problem($e->getMessage(), $e->getCode());
			}
			
			if ($full)
			{
				#better to structure the output directly rather than push the json conversion down
				#into the retrieval object, in case there's some interim manipulation to be done.
				
				echo '[';
				echo join(array_map("jsonize",$ids),', ');
				echo ']';
			}
			else
			{
				echo json_encode($ids);		
			}
		
		}
	}
	else if ($_SERVER['REQUEST_METHOD'] == 'PUT') #add a single record to the server
	{

		if ($auth_user != $username)
		{
			report_problem("5", 401);
		}

		$putdata = fopen("php://input", "r");
		$json = '';
		while ($data = fread($putdata,2048)) {$json .= $data;};
		
		$wbo = new wbo();
		if (!$wbo->extract_json($json))
		{
			report_problem("6", 400);
		}
		
		#use the url if the json object doesn't have an id
		if (!$wbo->id() && $id) { $wbo->id($id); }
		
		$wbo->collection($collection);
		$wbo->modified(microtime(1) * 1000); #current microtime

		if ($wbo->validate())
		{
			try
			{
				#if there's no payload (as opposed to blank), then update the metadata
				if ($wbo->payload_exists())
				{
					$db->store_object($wbo);
				}
				else
				{
					$db->update_object($wbo);
				}
			}
			catch (Exception $e)
			{
				report_problem($e->getMessage(), $e->getCode());
			}
			echo json_encode((string)$wbo->modified());
		}
		else
		{
			report_problem("8", 400);
		}
	}
	else if ($_SERVER['REQUEST_METHOD'] == 'POST')
	{
	
		if ($auth_user != $username)
		{
			report_problem("5", 401);
		}

		#stupid php being helpful with input data...
		$putdata = fopen("php://input", "r");
		$jsonstring = '';
		while ($data = fread($putdata,2048)) {$jsonstring .= $data;}
		$json = json_decode($jsonstring, true);

		if (!$json)
		{
			report_problem("6", 400);
		}
		
		$success_ids = array();
		$failed_ids = array();
		
		$modified = microtime(1) * 1000;
		foreach ($json as $wbo_data)
		{
			$wbo = new wbo();
			if (!$wbo->extract_json($wbo_data))
			{
				report_problem("6", 400);
			}
			
			$wbo->collection($collection);
			$wbo->modified($modified);
			

			if ($wbo->validate())
			{
				try
				{
					#if there's no payload (as opposed to blank), then update the metadata
					if ($wbo->payload_exists())
					{
						$db->store_object($wbo);
					}
					else
					{
						$db->update_object($wbo);
					}
				}
				catch (Exception $e)
				{
					report_problem($e->getMessage(), $e->getCode());
				}
				$success_ids[] = $wbo->id();
			}
			else
			{
				$failed_ids[$wbo->id()] = $wbo->get_error();
			}
		}
		echo json_encode(array('modified' => (string)$modified, 'success' => $success_ids, 'failed' => $failed_ids));
	}
	else if ($_SERVER['REQUEST_METHOD'] == 'DELETE')
	{

		if ($auth_user != $username)
		{
			report_problem("5", 401);
		}

		if ($id)
		{
			try
			{
				$db->delete_object($collection, $id);
			}
			catch(Exception $e)
			{
				report_problem($e->getMessage(), $e->getCode());
			}
			echo json_encode("success");
		}
		else
		{
			try
			{
				$db->delete_collection($collection);
			}
			catch(Exception $e)
			{
				report_problem($e->getMessage(), $e->getCode());
			}
			echo json_encode("success");
		}
	}
	else
	{
		#bad protocol. There are protocols left? HEAD, I guess.
		report_problem("1", 400);
	}
		
?>