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
	
require_once 'weave_constants.php';
require_once 'weave_basic_object.php';



#Returns the storage object. Takes three arguments
#username: username to be operated on
#type: explicit type of storage. Usually set by the WEAVE_STORAGE_ENGINE environment variable
#dbh: an already existing database handle to use. If a non-object, will not create
#a db connection (must be done explicitly)

function get_storage_read_object($username, $dbh = null)
{
	switch(WEAVE_STORAGE_ENGINE)
	{
		case 'mysql':
			return new WeaveStorageMysql($username, $dbh);
		case 'sqlite':
			return new WeaveStorageSqlite($username, $dbh);
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
		default:
			throw new Exception("Unknown storage type", 503);
	}				
}


#PDO wrapper to an underlying SQLite storage engine.
#Note that username is only needed for opening the file. All operations after that will be on that user.

interface WeaveStorage
{
	function __construct($username, $dbh = null);

	function open_connection();

	function get_connection();
	
	function begin_transaction();

	function commit_transaction();

	function get_max_timestamp($collection);
	
	function get_collection_list();
	
	function get_collection_list_with_timestamps();

	function store_object(&$wbo);
	
	function delete_object($collection, $id);
	
	function delete_objects($collection, $id = null, $parentid = null, $newer = null, $older = null, $limit = null, $offset = null);
	
	function retrieve_object($collection, $id);
	
	function retrieve_objects($collection, $id = null, $full = null, $direct_output = null, $parentid = null, $newer = null, $older = null, $limit = null, $offset = null);

	function get_storage_total();

	function get_user_quota();

	function create_user();

	function delete_user();

	function heartbeat();
}




#Mysql version of the above.
#Note that this object does not contain any database setup information. It assumes that the mysql
#instance is already fully configured

#create table wbo
#(
# username varbinary(32),
# collection varbinary(64),
# id varbinary(64),
# parentid varbinary(64),
# sortindex int default null,
# depth tinyint default null, 
# modified bigint,
# payload text,
# primary key(username, collection, id),
# index parentindex(username, collection, parentid),
# index modified(username, collection, modified)
#) engine=InnoDB;

class WeaveStorageMysql implements WeaveStorage
{
	private $_username;
	private $_type = 'read';
	private $_dbh;
	private $_db_name = 'wbo';
	
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

		if (defined('WEAVE_MYSQL_STORE_TABLE_NAME'))
			$this->_db_name = WEAVE_MYSQL_STORE_TABLE_NAME;
	}

	function open_connection() 
	{		
		try
		{
			if ($this->_type == 'write')
			{
				$this->_dbh = new PDO('mysql:host=' . WEAVE_MYSQL_STORE_WRITE_HOST . ';dbname=' . WEAVE_MYSQL_STORE_WRITE_DB, 
									WEAVE_MYSQL_STORE_WRITE_USER, WEAVE_MYSQL_STORE_WRITE_PASS);
			}
			else
			{
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

	function begin_transaction()
	{
		try
		{
			$this->_dbh->beginTransaction();
		}
		catch( PDOException $exception )
		{
			error_log("begin_transaction: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		return 1;
	}

	function commit_transaction()
	{
		$this->_dbh->commit();
		return 1;
	}
	
	function get_max_timestamp($collection)
	{
		if (!$collection)
		{
			return 0;
		}
		
		try
		{
			$select_stmt = 'select max(modified) from ' . $this->_db_name . ' where username = :username and collection = :collection';
			$sth = $this->_dbh->prepare($select_stmt);
			$sth->bindParam(':username', $this->_username);
			$sth->bindParam(':collection', $collection);
			$sth->execute();
		}
		catch( PDOException $exception )
		{
			error_log("get_max_timestamp: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		
		$result = $sth->fetchColumn();
		return round((float)$result, 2);		
	}

	function get_collection_list()
	{		
		try
		{
			$select_stmt = 'select distinct(collection) from ' . $this->_db_name . ' where username = :username';
			$sth = $this->_dbh->prepare($select_stmt);
			$sth->bindParam(':username', $this->_username);
			$sth->execute();
		}
		catch( PDOException $exception )
		{
			error_log("get_collection_list: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		
		
		$collections = array();
		while ($result = $sth->fetchColumn())
		{
			$collections[] = $result;
		}
		
		return $collections;		
	}

	function get_collection_list_with_timestamps()
	{
		try
		{
			$select_stmt = 'select collection, max(modified) as timestamp from ' . $this->_db_name . ' where username = :username group by collection';
			$sth = $this->_dbh->prepare($select_stmt);
			$sth->bindParam(':username', $this->_username);
			$sth->execute();
		}
		catch( PDOException $exception )
		{
			error_log("get_collection_list: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		
		
		$collections = array();
		while ($result = $sth->fetch(PDO::FETCH_NUM))
		{
			$collections[$result[0]] = $result[1];
		}
		
		return $collections;		
	}
	
	function store_object(&$wbo) 
	{
		
		try
		{
			$insert_stmt = 'replace into ' . $this->_db_name . ' (username, id, collection, parentid, depth, sortindex, modified, payload) 
					values (:username, :id, :collection, :parentid, :depth, :sortindex, :modified, :payload)';
			$sth = $this->_dbh->prepare($insert_stmt);
			$sth->bindParam(':username', $this->_username);
			$sth->bindParam(':id', $wbo->id());
			$sth->bindParam(':collection', $wbo->collection());
			$sth->bindParam(':parentid', $wbo->parentid());
			$sth->bindParam(':depth', $wbo->depth());
			$sth->bindParam(':sortindex', $wbo->sortindex());
			$sth->bindParam(':modified', $wbo->modified());
			$sth->bindParam(':payload', $wbo->payload());
			$sth->execute();
		}
		catch( PDOException $exception )
		{
			error_log("store_object: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		return 1;
	
	}
	
	function update_object(&$wbo)
	{
		$update = 'update ' . $this->_db_name . ' set ';
		$params = array();
		$update_list = array();
		
		#make sure we have an id and collection. No point in continuing otherwise
		if (!$wbo->id() || !$wbo->collection())
		{
			error_log('Trying to update without a valid id or collection!');
			return 0;
		}
		
		if ($wbo->parentid_exists())
		{
			$update_list[] = "parent_id = ?";
			$params[] = $wbo->parentid();
		}
		
		if ($wbo->depth_exists())
		{
			$update_list[] = "depth = ?";
			$params[] = $wbo->depth();
		}
		
		if ($wbo->sortindex_exists())
		{
			$update_list[] = "sortindex = ?";
			$params[] = $wbo->sortindex();
		}
		
		#Under standard weave semantics, update will not be called if there's no payload. 
		#However, this is included for functional completion
		if ($wbo->payload_exists())
		{
			$update_list[] = "payload = ?";
			$params[] = $wbo->payload();
		}
		
		# Don't modify the timestamp on a depth-only change. It's purely for sorting trees.
		if ($wbo->parentid_exists() || $wbo->payload_exists()) 
		{
			#better make sure we have a modified date. Should have been handled earlier
			if (!$wbo->modified_exists())
			{
				error_log("Called update_object with no defined timestamp. Please check");
				$wbo->modified(microtime(1));
			}
			$update_list[] = "modified = ?";
			$params[] = $wbo->modified();

		}
		
		if (count($params) == 0)
		{
			return 0;
		}
		
		$update .= join($update_list, ",");

		$update .= " where username = ? and collection = ? and id = ?";
		$params[] = $this->_username;
		$params[] = $wbo->collection();
		$params[] = $wbo->id();
		try
		{
			$sth = $this->_dbh->prepare($update);
			$sth->execute($params);
		}
		catch( PDOException $exception )
		{
			error_log("update_object: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		return 1;		
	}
	
	function delete_object($collection, $id)
	{
		try
		{
			$delete_stmt = 'delete from ' . $this->_db_name . ' where username = :username and collection = :collection and id = :id';
			$sth = $this->_dbh->prepare($delete_stmt);
			$sth->bindParam(':username', $this->_username);
			$sth->bindParam(':collection', $collection);
			$sth->bindParam(':id', $id);
			$sth->execute();
		}
		catch( PDOException $exception )
		{
			error_log("delete_object: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		return 1;
	}
	
	function delete_objects($collection, $id = null, $parentid = null, $newer = null, $older = null, 
								$sort = null, $limit = null, $offset = null)
	{
		$params = array();
		
		$select_stmt = 'delete from ' . $this->_db_name . ' where username = ? and collection = ?';
		$params[] = $this->_username;
		$params[] = $collection;
		
		
		if ($id)
		{
			$select_stmt .= " and id = ?";
			$params[] = $id;
		}
		
		if ($parentid)
		{
			$select_stmt .= " and parentid = ?";
			$params[] = $parentid;
		}
		
		if ($newer)
		{
			$select_stmt .= " and newer > ?";
			$params[] = $newer;
		}
	
		if ($older)
		{
			$select_stmt .= " and modified < ?";
			$params[] = $older;
		}
	
		if ($sort == 'index')
		{
			$select_stmt .= " order by sortindex";
		}
		else if ($sort == 'newest')
		{
			$select_stmt .= " order by modified desc";
		}
		else if ($sort == 'oldest')
		{
			$select_stmt .= " order by modified";
		}
		else if ($sort == 'depthindex')
		{
			$select_stmt .= " order by depth,sortindex";
		}
		
		if ($limit)
		{
			$select_stmt .= " limit " . intval($limit);
			if ($offset)
			{
				$select_stmt .= " offset " . intval($offset);
			}
		}

		try
		{
			$sth = $this->_dbh->prepare($select_stmt);
			$sth->execute($params);
		}
		catch( PDOException $exception )
		{
			error_log("delete_objects: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		return 1;
	}

	function retrieve_object($collection, $id)
	{
		try
		{
			$select_stmt = 'select * from ' . $this->_db_name . ' where username = :username and collection = :collection and id = :id';
			$sth = $this->_dbh->prepare($select_stmt);
			$sth->bindParam(':username', $this->_username);
			$sth->bindParam(':collection', $collection);
			$sth->bindParam(':id', $id);
			$sth->execute();
		}
		catch( PDOException $exception )
		{
			error_log("retrieve_object: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		
		$result = $sth->fetch(PDO::FETCH_ASSOC);
		
		$wbo = new wbo();
		$wbo->populate($result{'id'}, $result{'collection'}, $result{'parentid'}, $result{'modified'}, $result{'depth'}, $result{'sortindex'}, $result{'payload'});
		return $wbo;
	}
	
	function retrieve_objects($collection, $id = null, $full = null, $direct_output = null, $parentid = null, $newer = null, 
								$older = null, $sort = null, $limit = null, $offset = null, $ids = null)
	{
		$full_list = $full ? '*' : 'id';
		
		$select_stmt = "select $full_list from " . $this->_db_name . ' where username = ? and collection = ?';
		$params[] = $this->_username;
		$params[] = $collection;
		
		
		if ($id)
		{
			$select_stmt .= " and id = ?";
			$params[] = $id;
		}
		
		if ($ids && count($ids) > 0)
		{
			$qmarks = array();
			$select_stmt .= " and id in (";
			foreach ($ids as $temp)
			{
				$params[] = $temp;
				$qmarks[] = '?';
			}
			$select_stmt .= implode(",", $qmarks);
			$select_stmt .= ')';
		}
		
		if ($parentid)
		{
			$select_stmt .= " and parentid = ?";
			$params[] = $parentid;
		}
		
		if ($newer)
		{
			$select_stmt .= " and modified > ?";
			$params[] = $newer;
		}
	
		if ($older)
		{
			$select_stmt .= " and modified < ?";
			$params[] = $older;
		}
	
		if ($sort == 'index')
		{
			$select_stmt .= " order by sortindex";
		}
		else if ($sort == 'newest')
		{
			$select_stmt .= " order by modified desc";
		}
		else if ($sort == 'oldest')
		{
			$select_stmt .= " order by modified";
		}
		else if ($sort == 'depthindex')
		{
			$select_stmt .= " order by depth,sortindex";
		}
		
		if ($limit)
		{
			$select_stmt .= " limit " . intval($limit);
			if ($offset)
			{
				$select_stmt .= " offset " . intval($offset);
			}
		}
		
		try
		{
			$sth = $this->_dbh->prepare($select_stmt);
			$sth->execute($params);
		}
		catch( PDOException $exception )
		{
			error_log("retrieve_collection: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		if ($direct_output)
			return $direct_output->output($sth);

		$ids = array();
		while ($result = $sth->fetch(PDO::FETCH_ASSOC))
		{
			if ($full)
			{
				$wbo = new wbo();
				$wbo->populate($result{'id'}, $result{'collection'}, $result{'parentid'}, $result{'modified'}, $result{'depth'}, $result{'sortindex'}, $result{'payload'});
				$ids[] = $wbo;
			}
			else
				$ids[] = $result{'id'};
		}		
		return $ids;
	}
	
	function get_storage_total()
	{
		try
		{
			$select_stmt = 'select round(sum(length(payload))/1024) from ' . $this->_db_name . ' where username = :username';
			$sth = $this->_dbh->prepare($select_stmt);
			$sth->bindParam(':username', $this->_username);
			$sth->execute();
		}
		catch( PDOException $exception )
		{
			error_log("get_storage_total: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		
		return (int)$sth->fetchColumn();		
	}
	
	function get_user_quota()
	{
		if (defined('WEAVE_QUOTA'))
			return WEAVE_QUOTA;
		return null;
	}

	function create_user()
	{
		return 1; #nothing needs doing on the storage side
	}
	
	function delete_user()
	{
		try
		{
			$delete_stmt = 'delete from ' . $this->_db_name . ' where username = :username';
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

	function heartbeat()
	{
		try
		{
			$sth = $this->_dbh->prepare('select 1');
			$sth->execute();
		}
		catch( PDOException $exception )
		{
			throw new Exception("Database unavailable");
		}
		$result = $sth->fetchColumn();
		return $result;
	}

}




#Sqlite version of the object
class WeaveStorageSqlite implements WeaveStorage
{
	private $_username;
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
		#otherwise we do nothing with the connection
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
		return $this->_dbh;
	}

	function begin_transaction()
	{
		try
		{
			$this->_dbh->beginTransaction();
		}
		catch( PDOException $exception )
		{
			error_log("begin_transaction: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		return 1;
	}

	function commit_transaction()
	{
		$this->_dbh->commit();
		return 1;
	}
		
	function get_max_timestamp($collection)
	{
		if (!$collection)
		{
			return 0;
		}
		
		try
		{
			$select_stmt = 'select max(modified) from wbo where collection = :collection';
			$sth = $this->_dbh->prepare($select_stmt);
			$sth->bindParam(':collection', $collection);
			$sth->execute();
		}
		catch( PDOException $exception )
		{
			error_log("get_max_timestamp: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		
		$result = $sth->fetchColumn();
		return round((float)$result, 2);		
	}

	function get_collection_list()
	{		
		try
		{
			$select_stmt = 'select distinct(collection) from wbo';
			$sth = $this->_dbh->prepare($select_stmt);
			$sth->execute();
		}
		catch( PDOException $exception )
		{
			error_log("get_collection_list: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		
		
		$collections = array();
		while ($result = $sth->fetchColumn())
		{
			$collections[] = $result;
		}
		
		return $collections;		
	}


	function get_collection_list_with_timestamps()
	{
		try
		{
			$select_stmt = 'select collection, max(modified) as timestamp from wbo group by collection';
			$sth = $this->_dbh->prepare($select_stmt);
			$sth->execute();
		}
		catch( PDOException $exception )
		{
			error_log("get_collection_list: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		
		
		$collections = array();
		while ($result = $sth->fetch(PDO::FETCH_NUM))
		{
			$collections[$result[0]] = $result[1];
		}
		
		return $collections;		
	}

	function store_object(&$wbo)
	{
		
		try
		{
			$insert_stmt = 'replace into wbo (id, collection, parentid, depth, sortindex, modified, payload) 
					values (:id, :collection, :parentid, :depth, :sortindex, :modified, :payload)';
			$sth = $this->_dbh->prepare($insert_stmt);
			$sth->bindParam(':id', $wbo->id());
			$sth->bindParam(':collection', $wbo->collection());
			$sth->bindParam(':parentid', $wbo->parentid());
			$sth->bindParam(':depth', $wbo->depth());
			$sth->bindParam(':sortindex', $wbo->sortindex());
			$sth->bindParam(':modified', $wbo->modified());
			$sth->bindParam(':payload', $wbo->payload());
			$sth->execute();

		}
		catch( PDOException $exception )
		{
			error_log("store_object: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		return 1;
	}
	
	
	function update_object(&$wbo)
	{
		$update = "update wbo set ";
		$params = array();
		$update_list = array();
		
		#make sure we have an id and collection. No point in continuing otherwise
		if (!$wbo->id() || !$wbo->collection())
		{
			error_log('Trying to update without a valid id or collection!');
			return 0;
		}

		if ($wbo->parentid_exists())
		{
			$update_list[] = " parent_id = ?";
			$params[] = $wbo->parentid();
		}
		
		if ($wbo->depth_exists())
		{
			$update_list[] = " depth = ?";
			$params[] = $wbo->depth();
		}
		
		if ($wbo->sortindex_exists())
		{
			$update_list[] = " sortindex = ?";
			$params[] = $wbo->sortindex();
		}

		if ($wbo->payload_exists())
		{
			$update_list[] = "payload = ?";
			$params[] = $wbo->payload();
		}

		# Don't modify the timestamp on a depth-only change
		if ($wbo->parentid_exists() || $wbo->payload_exists()) 
		{
			#better make sure we have a modified date. Should have been handled earlier
			if (!$wbo->modified_exists())
			{
				error_log("Called update_object with no defined timestamp. Please check");
				$wbo->modified(microtime(1));
			}
			$update_list[] = " modified = ?";
			$params[] = $wbo->modified();

		}
		
		
		if (count($params) == 0)
		{
			return 0;
		}
		
		$update .= join($update_list, ",");

		$update .= " where collection = ? and id = ?";
		$params[] = $wbo->collection();
		$params[] = $wbo->id();
		
		try
		{
			$sth = $this->_dbh->prepare($update);
			$sth->execute($params);
		}
		catch( PDOException $exception )
		{
			error_log("update_object: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		return 1;		
	}
	
	function delete_object($collection, $id)
	{
		try
		{
			$delete_stmt = 'delete from wbo where collection = :collection and id = :id';
			$sth = $this->_dbh->prepare($delete_stmt);
			$sth->bindParam(':collection', $collection);
			$sth->bindParam(':id', $id);
			$sth->execute();
		}
		catch( PDOException $exception )
		{
			error_log("delete_object: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		return 1;
	}
	
	function delete_objects($collection, $id = null, $parentid = null, $newer = null, $older = null, 
								$sort = null, $limit = null, $offset = null)
	{
		$params = array();
		$select_stmt = '';
		
		if ($limit || $offset || $sort)
		{
			#sqlite can't do sort or limit deletes without special compiled versions
			#so, we need to grab the set, then delete it manually.
		
			$params = $this->retrieve_objects($collection, $id, 0, 0, $parentid, $newer, $older, $sort, $limit, $offset);
			if (!count($params))
			{
				return 1; #nothing to delete
			}
			$paramqs = array();
			$select_stmt = "delete from wbo where collection = ? and id in (" . join(", ", array_pad($paramqs, count($params), '?')) . ")";
			array_unshift($params, $collection);
		}
		else
		{
		
			$select_stmt = "delete from wbo where collection = ?";
			$params[] = $collection;
			
			
			if ($id)
			{
				$select_stmt .= " and id = ?";
				$params[] = $id;
			}
			
			if ($parentid)
			{
				$select_stmt .= " and parentid = ?";
				$params[] = $parentid;
			}
			
			if ($newer)
			{
				$select_stmt .= " and modified > ?";
				$params[] = $newer;
			}
		
			if ($older)
			{
				$select_stmt .= " and modified < ?";
				$params[] = $older;
			}
		
			if ($sort == 'index')
			{
				$select_stmt .= " order by sortindex";
			}
			else if ($sort == 'newest')
			{
				$select_stmt .= " order by modified desc";
			}
			else if ($sort == 'oldest')
			{
				$select_stmt .= " order by modified";
			}
			else if ($sort == 'depthindex')
			{
				$select_stmt .= " order by depth,sortindex";
			}
		
			if ($limit)
			{
				$select_stmt .= " limit " . intval($limit);
				if ($offset)
				{
					$select_stmt .= " offset " . intval($offset);
				}
			}
		}
		
		try
		{
			$sth = $this->_dbh->prepare($select_stmt);
			$sth->execute($params);
		}
		catch( PDOException $exception )
		{
			error_log("delete_objects: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		return 1;
	}
	
	function retrieve_object($collection, $id)
	{
		try
		{
			$select_stmt = 'select * from wbo where collection = :collection and id = :id';
			$sth = $this->_dbh->prepare($select_stmt);
			$sth->bindParam(':collection', $collection);
			$sth->bindParam(':id', $id);
			$sth->execute();
		}
		catch( PDOException $exception )
		{
			error_log("retrieve_object: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}

		$result = $sth->fetch(PDO::FETCH_ASSOC);
		
		$wbo = new wbo();
		$wbo->populate($result{'id'}, $result{'collection'}, $result{'parentid'}, $result{'modified'}, $result{'depth'}, $result{'sortindex'}, $result{'payload'});
		return $wbo;
	}
	
	function retrieve_objects($collection, $id = null, $full = null, $direct_output = null, $parentid = null, $newer = null, $older = null, $sort = null, $limit = null, $offset = null)
	{
		$full_list = $full ? '*' : 'id';
			
		
		$select_stmt = "select $full_list from wbo where collection = ?";
		$params[] = $collection;
		
		
		if ($id)
		{
			$select_stmt .= " and id = ?";
			$params[] = $id;
		}
		
		if ($ids && count($ids) > 0)
		{
			$qmarks = array();
			$select_stmt .= " and id in (";
			foreach ($ids as $temp)
			{
				$params[] = $temp;
				$qmarks[] = '?';
			}
			$select_stmt .= implode(",", $qmarks);
			$select_stmt .= ')';
		}
		
		if ($parentid)
		{
			$select_stmt .= " and parentid = ?";
			$params[] = $parentid;
		}
		
		if ($newer)
		{
			$select_stmt .= " and modified > ?";
			$params[] = $newer;
		}
	
		if ($older)
		{
			$select_stmt .= " and modified < ?";
			$params[] = $older;
		}
	
		if ($sort == 'index')
		{
			$select_stmt .= " order by sortindex";
		}
		else if ($sort == 'newest')
		{
			$select_stmt .= " order by modified desc";
		}
		else if ($sort == 'oldest')
		{
			$select_stmt .= " order by modified";
		}
		else if ($sort == 'depthindex')
		{
			$select_stmt .= " order by depth,sortindex";
		}
		
		if ($limit)
		{
			$select_stmt .= " limit " . intval($limit);
			if ($offset)
			{
				$select_stmt .= " offset " . intval($offset);
			}
		}
		
		try
		{
			$sth = $this->_dbh->prepare($select_stmt);
			$sth->execute($params);
		}
		catch( PDOException $exception )
		{
			error_log("retrieve_collection: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}

		if ($direct_output)
			return $direct_output->output($sth);

		$ids = array();
		while ($result = $sth->fetch(PDO::FETCH_ASSOC))
		{
			if ($full)
			{
				$wbo = new wbo();
				$wbo->populate($result{'id'}, $result{'collection'}, $result{'parentid'}, $result{'modified'}, $result{'depth'}, $result{'sortindex'}, $result{'payload'});
				$ids[] = $wbo;
			}
			else
				$ids[] = $result{'id'};
		}		
		return $ids;
	}

	function get_storage_total()
	{
		try
		{
			$select_stmt = 'select round(sum(length(payload))/1024) from wbo';
			$sth = $this->_dbh->prepare($select_stmt);
			$sth->execute();
		}
		catch( PDOException $exception )
		{
			error_log("get_storage_total: " . $exception->getMessage());
			throw new Exception("Database unavailable", 503);
		}
		
		return (int)$sth->fetchColumn();		
	}

	function get_user_quota()
	{
		return WEAVE_QUOTA;
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
		$username_md5 = md5($this->_username);
		$db_name = WEAVE_SQLITE_STORE_DIRECTORY . '/' . $username_md5{0} . '/' . $username_md5{1} . '/' . $username_md5{2} . '/' . $username_md5;
		unlink($db_name);
	}

	function heartbeat()
	{
		try
		{
			$sth = $this->_dbh->prepare('select 1');
			$sth->execute();
		}
		catch( PDOException $exception )
		{
			throw new Exception("Database unavailable");
		}
		$result = $sth->fetchColumn();
		return $result;
	}
}


 ?>