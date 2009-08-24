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
	
require_once 'weave_user.php';
require_once 'weave_user_constants.php';


#Truncated version of the weave_storage object used by the api
#This one just needs the user create and delete functions

function get_storage_read_object($username, $dbh = null)
{
	switch(WEAVE_STORAGE_ENGINE)
	{
		case 'mysql':
			return new WeaveStorageMysql($username, $dbh);
		case 'sqlite':
			return new WeaveStorageSqlite($username, $dbh);
		case 'none':
			return new WeaveStorageNone($username, $dbh);
		default:
			throw new Exception("Unknown storage type", 503);
	}				
}


function get_storage_write_object($username, $dbh = null)
{
	switch(WEAVE_STORAGE_ENGINE)
	{
		case 'mysql':
			return new WeaveStorageMysql($username, $dbh ? $dbh : 'write');
		case 'sqlite':
			return new WeaveStorageSqlite($username, $dbh);
		case 'none':
			return new WeaveStorageNone($username, $dbh);
		default:
			throw new Exception("Unknown storage type", 503);
	}				
}


interface WeaveStorage
{
	function __construct($username, $dbh = null);

	function open_connection();

	function get_connection();
	
	function create_user();

	function delete_user();
}



class WeaveStorageNone implements WeaveStorage
{
	function __construct($username, $dbh = null)
	{
		return;
	}

	function open_connection()
	{
		return;
	}

	function get_connection()
	{
		return null;
	}
	
	function create_user()
	{
		return 1;
	}
	
	function delete_user()
	{
		return 1;
	}
}



class WeaveStorageMysql implements WeaveStorage
{
	private $_username;
	private $_type = 'read';
	private $_dbh;
	
	function __construct($username, $dbh = null) 
	{
		$this->_username = $username;
		if (!$dbh)
		{
			$this->open_connection();
		}
		elseif (is_object($dbh))
		{
			$this->_dbh = $dbh;
		}
		elseif (is_string($dbh) && $dbh == 'write')
		{
			$this->_type = 'write';
			$this->open_connection();
		}
		#otherwise we do nothing with the connection and wait for it to be directly opened
	}

	function open_connection() 
	{
		try
		{
			if ($this->_type == 'write')
			{
				if (!WEAVE_MYSQL_STORE_WRITE_HOST)
					return; # not doing user storage in the same space as auth
					
				$this->_dbh = new PDO('mysql:host=' . WEAVE_MYSQL_STORE_WRITE_HOST . ';dbname=' . WEAVE_MYSQL_STORE_WRITE_DB, 
									WEAVE_MYSQL_STORE_WRITE_USER, WEAVE_MYSQL_STORE_WRITE_PASS);
			}
			else
			{
				if (!WEAVE_MYSQL_STORE_READ_HOST)
					return; # not doing user storage in the same space as auth

				$this->_dbh = new PDO('mysql:host=' . WEAVE_MYSQL_STORE_READ_HOST . ';dbname=' . WEAVE_MYSQL_STORE_READ_DB, 
									WEAVE_MYSQL_STORE_READ_USER, WEAVE_MYSQL_STORE_READ_PASS);
			}
			$this->_dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		}
		catch( PDOException $exception )
		{
			error_log($exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
	}
	
	function get_connection()
	{
		return $this->_dbh;
	}

	function create_user()
	{
		return 1; #nothing needs doing on the storage side
	}
	
	function delete_user()
	{
		if (!$this->_dbh)
			return 1; #we aren't connected to the datastore locally
			
		try
		{
			$delete_stmt = 'delete from wbo where username = :username';
			$sth = $this->_dbh->prepare($delete_stmt);
			$sth->bindParam(':username', $this->_username);
			$sth->execute();

		}
		catch( PDOException $exception )
		{
			error_log("delete_user: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		return 1;

	}

}

#PDO wrapper to an underlying SQLite storage engine.
#Note that username is only needed for opening the file. All operations after that will be on that user.

class WeaveStorageSqlite implements WeaveStorage
{
	private $_username;
	private $_dbh;	
	
	function __construct($username, $dbh = null) 
	{
		$this->_username = $username;
		#don't connect yet
	}
	
	function open_connection()
	{
		$username_md5 = md5($this->_username);
		$db_name = WEAVE_SQLITE_STORE_DIRECTORY . '/' . $username_md5{0} . '/' . $username_md5{1} . '/' . $username_md5{2} . '/' . $username_md5;

		if (!file_exists($db_name))
		{
			throw new Exception("User not found", 404);
		}

		try
		{
			$this->_dbh = new PDO('sqlite:' . $db_name);
			$this->_dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		}
		catch( PDOException $exception )
		{
			throw new Exception("Database unavailable", 503);
		}
	}

	function get_connection()
	{
		if (!$this->_dbh)
			open_connection();
		return $this->_dbh;
	}


	#sets up the tables within the newly created db server 
	function create_user()
	{
		$username_md5 = md5($this->_username);
		
		
		#make sure our path exists
		$path = WEAVE_SQLITE_STORE_DIRECTORY . '/' . $username_md5{0};
		if (!is_dir($path)) { mkdir ($path); }
		$path .= '/' . $username_md5{1};
		if (!is_dir($path)) { mkdir ($path); }
		$path .= '/' . $username_md5{2};
		if (!is_dir($path)) { mkdir ($path); }

		#create our user's db file
		try
		{
			$dbh = new PDO('sqlite:' . $path . '/' . $username_md5);
			$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$create_statement = <<< end
create table wbo 
(
 id text,
 collection text,
 parentid text,
 predecessorid text,
 modified real,
 sortindex int,
 depth int,
 payload text,
 primary key (collection,id)
)
end;

			$index1 = 'create index idindex on wbo (id)';
			$index2 = 'create index parentindex on wbo (parentid)';
			$index3 = 'create index modifiedindex on wbo (modified DESC)';
		
		
			$sth = $dbh->prepare($create_statement);
			$sth->execute();
			$sth = $dbh->prepare($index1);
			$sth->execute();
			$sth = $dbh->prepare($index2);
			$sth->execute();
			$sth = $dbh->prepare($index3);
			$sth->execute();
		}
		catch( PDOException $exception )
		{
			error_log("initialize_user_db:" . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
	}

	function delete_user()
	{
		if (!$this->_dbh)
			$this->open_connection();
		$username_md5 = md5($this->_username);
		$db_name = WEAVE_SQLITE_STORE_DIRECTORY . '/' . $username_md5{0} . '/' . $username_md5{1} . '/' . $username_md5{2} . '/' . $username_md5;
		unlink($db_name);
	}

}


 ?>