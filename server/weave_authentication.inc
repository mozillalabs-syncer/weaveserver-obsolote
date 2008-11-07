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
	
function get_auth_object($type = null)
{
	if (!$type)
	{
		$type = getenv('WEAVE_AUTH_ENGINE');
	}
	
	switch($type)
	{
		case 'mysql':
			return new WeaveAuthenticationMysql();
		case 'sqlite':
			return new WeaveAuthenticationSqlite();
		case 'htaccess':
		case 'none':
		case '':
			return new WeaveAuthenticationNone();
		default:
			throw new Exception("Unknown authentication type", 503);
	}				
}


interface WeaveAuthentication
{
	function __construct($dbh = null);

	function open_connection();

	function get_connection();
	
	function create_user($username, $password, $email = "");
	
	function update_password($username, $password);

	function update_email($username, $email = "");

	function user_exists($username);

	function authenticate_user($username, $password);

	function get_user_location($username);
	
	function delete_user($username);
}


#Dummy object for no-auth and .htaccess setups
class WeaveAuthenticationNone implements WeaveAuthentication
{
	function __construct($dbh = null)
	{
	}

	function open_connection()
	{
		return 1;
	}
	
	function get_connection()
	{
		return null;
	}
	
	function create_user($username, $password, $email = "")
	{
		return 1;
	}
	
	function get_user_location($username)
	{
		return 0;
	}

	function update_password($username, $password)
	{
		return 1;
	}

	function update_email($username, $email = "")
	{
		return 1;
	}

	function authenticate_user($username, $password)
	{
		return 1;
	}
	
	function delete_user($username)
	{
		return 1;
	}
	
	function user_exists($username)
	{
		return 0;
	}
}




#Mysql version of the above.
#Note that this object does not contain any database setup information. It assumes that the mysql
#instance is already fully configured

#
#create table users
#(
# username varchar(32),
# md5_pass varchar(32),
# email varchar(64),
#) engine=InnoDB;
#

class WeaveAuthenticationMysql implements WeaveAuthentication
{
	var $_dbh;
	
	function __construct($dbh = null) 
	{
		if (!$dbh)
		{
			$this->open_connection();
		}
		elseif ($dbh == 'no_connect')
		{
			# do nothing. No connection.
		}
		else
		{
			$this->_dbh = $no_connect;
		}
	}

	function open_connection() 
	{ 
		$hostname = getenv('WEAVE_MYSQL_AUTH_HOST');
		$dbname = getenv('WEAVE_MYSQL_AUTH_DB');
		$dbuser = getenv('WEAVE_MYSQL_AUTH_USER');
		$dbpass = getenv('WEAVE_MYSQL_AUTH_PASS');
		
		try
		{
			$this->_dbh = new PDO('mysql:host=' . $hostname . ';dbname=' . $dbname, $dbuser, $dbpass);
			$this->_dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		}
		catch( PDOException $exception )
		{
				error_log($exception->getMessage());
				throw new Exception("Database unavailable", 503);
		}
		return 1;
	}
	
	function get_connection()
	{
		return $this->_dbh;
	}

	function create_user($username, $password, $email = "")
	{ 
		if (!$username)
		{
			throw new Exception("3", 404);
		}
		if (!$password)
		{
			throw new Exception("7", 404);
		}

		try
		{
			$insert_stmt = 'insert into users (username, md5, email) values (:username, :md5, :email)';
			$sth = $this->_dbh->prepare($insert_stmt);
			$sth->bindParam(':username', $username);
			$sth->bindParam(':md5', md5($password));
			$sth->bindParam(':email', $email);
			$sth->execute();
		}
		catch( PDOException $exception )
		{
			error_log("create_user: " . $exception->getMessage());
			#check for primary key violation here...
			throw new Exception("Database unavailable", 503);
		}
		return 1;
	}

	function update_password($username, $password)
	{
		if (!$username)
		{
			throw new Exception("3", 404);
		}
		if (!$password)
		{
			throw new Exception("7", 404);
		}

		try
		{
			$insert_stmt = 'update users set md5 = :md5 where username = :username';
			$sth = $this->_dbh->prepare($insert_stmt);
			$sth->bindParam(':username', $username);
			$sth->bindParam(':md5', md5($password));
			if ($sth->execute() == 0)
			{
				throw new Exception("3", 404);
			}
		}
		catch( PDOException $exception )
		{
			error_log("update_password: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		return 1;
	
	}

	function update_email($username, $email = "")
	{
		if (!$username)
		{
			throw new Exception("3", 404);
		}

		try
		{
			$insert_stmt = 'update users set email = :email where username = :username';
			$sth = $this->_dbh->prepare($insert_stmt);
			$sth->bindParam(':username', $username);
			$sth->bindParam(':email', $email);
			if ($sth->execute() == 0)
			{
				throw new Exception("User not found", 404);
			}
		}
		catch( PDOException $exception )
		{
			error_log("update_email: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		return 1;
	
	}

	function user_exists($username) 
	{
		try
		{
			$select_stmt = 'select count(*) from users where username = :username';
			$sth = $this->_dbh->prepare($select_stmt);
			$sth->bindParam(':username', $username);
			$sth->execute();
		}
		catch( PDOException $exception )
		{
			error_log("user_exists: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}

		$result = $sth->fetchColumn();
		return $result;
	}

	function authenticate_user($username, $password) #auth user may be different from user, so need the username here
	{
		try
		{
			$select_stmt = 'select count(*) from users where username = :username and md5 = :md5';
			$sth = $this->_dbh->prepare($select_stmt);
			$sth->bindParam(':username', $username);
			$sth->bindParam(':md5', md5($password));
			$sth->execute();
		}
		catch( PDOException $exception )
		{
			error_log("authenticate_user: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}

		$result = $sth->fetchColumn();
		return $result ? 1 : 0;
	}
	
	function get_user_location($username)
	{
		return 0;
	}

	function delete_user($username) 
	{
		if (!$username)
		{
			throw new Exception("3", 404);
		}

		try
		{
			$delete_stmt = 'delete from wbo where username = :username';
			$sth = $this->_dbh->prepare($delete_stmt);
			$sth->bindParam(':username', $username);
			$sth->execute();

			$delete_stmt = 'delete from users where username = :username';
			$sth = $this->_dbh->prepare($delete_stmt);
			$sth->bindParam(':username', $username);
			$sth->execute();

		}
		catch( PDOException $exception )
		{
			error_log("delete_user: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		return 1;
	}

	function create_user_table()
	{
		try
		{
			$create_statement = "create table users (username varchar(32) primary key, md5 varchar(32), email varchar(64)) engine=InnoDB";
		
			$sth = $this->_dbh->prepare($create_statement);
			$sth->execute();
		}
		catch( PDOException $exception )
		{
			error_log("create_user_table:" . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		
	}
}




#Sqlite version of the object
class WeaveAuthenticationSqlite implements WeaveAuthentication
{
	var $_dbh;
	
	function __construct($dbh = null)
	{
		if (!$dbh)
		{
			$this->open_connection();
		}
		elseif ($dbh == 'no_connect')
		{
			# do nothing. No connection.
		}
		else
		{
			$this->_dbh = $dbh;
		}
	}
	
	function open_connection()
	{
		$db_name = getenv('WEAVE_SQLITE_AUTH_DIRECTORY') . '/_users';
		
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
		return $this->_dbh;
	}


	#Don't forget to tell the storage object to initialize the user db (preferably first)!
	function create_user($username, $password, $email = "")
	{ 
		if (!$username)
		{
			throw new Exception("3", 404);
		}
		if (!$password)
		{
			throw new Exception("7", 404);
		}

		try
		{
			$insert_stmt = 'insert into users (username, md5, email) values (:username, :md5, :email)';
			$sth = $this->_dbh->prepare($insert_stmt);
			$sth->bindParam(':username', $username);
			$sth->bindParam(':md5', md5($password));
			$sth->bindParam(':email', $email);
			$sth->execute();
		}
		catch( PDOException $exception )
		{
			error_log("create_user: " . $exception->getMessage());
			#need to add a subcatch here for user already existing
			throw new Exception("Database unavailable", 503);
		}
		
		
		return 1;
	}

	function update_password($username, $password)
	{
		if (!$username)
		{
			throw new Exception("3", 404);
		}
		if (!$password)
		{
			throw new Exception("7", 404);
		}

		try
		{
			$insert_stmt = 'update users set md5 = :md5 where username = :username';
			$sth = $this->_dbh->prepare($insert_stmt);
			$sth->bindParam(':username', $username);
			$sth->bindParam(':md5', md5($password));
			if ($sth->execute() == 0)
			{
				throw new Exception("User not found", 404);
			}			
		}
		catch( PDOException $exception )
		{
			error_log("update_password: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		return 1;
	
	}

	function update_email($username, $email = "")
	{
		if (!$username)
		{
			throw new Exception("3", 404);
		}

		try
		{
			$insert_stmt = 'update users set email = :email where username = :username';
			$sth = $this->_dbh->prepare($insert_stmt);
			$sth->bindParam(':username', $username);
			$sth->bindParam(':email', $email);
			if ($sth->execute() == 0)
			{
				throw new Exception("User not found", 404);
			}
		}
		catch( PDOException $exception )
		{
			error_log("update_email: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		return 1;
	
	}

	function authenticate_user($username, $password) 
	{
		try
		{
			$select_stmt = 'select count(*) from users where username = :username and md5 = :md5';
			$sth = $this->_dbh->prepare($select_stmt);
			$sth->bindParam(':username', $username);
			$sth->bindParam(':md5', md5($password));
			$sth->execute();
		}
		catch( PDOException $exception )
		{
			error_log("authenticate_user: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}

		$result = $sth->fetchColumn();
		return $result;
	}
	
	function get_user_location($username)
	{
		return 0;
	}

	function user_exists($username) 
	{
		try
		{
			$select_stmt = 'select count(*) from users where username = :username';
			$sth = $this->_dbh->prepare($select_stmt);
			$sth->bindParam(':username', $username);
			$sth->execute();
		}
		catch( PDOException $exception )
		{
			error_log("user_exists: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}

		$result = $sth->fetchColumn();
		return $result ? 1 : 0;
	}
	
	function delete_user($username)
	{
		if (!$username)
		{
			throw new Exception("3", 404);
		}

		try
		{
			$delete_stmt = 'delete from users where username = :username';
			$sth = $this->_dbh->prepare($delete_stmt);
			$sth->bindParam(':username', $username);
			$sth->execute();

		}
		catch( PDOException $exception )
		{
			error_log("delete_user: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		return 1;
	}

	function create_user_table()
	{
		try
		{
			$create_statement = "create table users (username text primary key, md5 text, email text)";
		
			$sth = $this->_dbh->prepare($create_statement);
			$sth->execute();
		}
		catch( PDOException $exception )
		{
			error_log("create_user_table:" . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		
	}
}



 ?>