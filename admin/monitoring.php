<?php

#create table usersummary(username varbinary(32) primary key, rows int, datasize int, checked datetime, last_update decimal(12,2));
#create table db_size(recorded datetime, cluster varbinary(32), datasize bigint);

#elapsed time before we delete history and form data (30 days)
$deletetime = time() - (60 * 60 * 24 * 30);


#get and parse the config file
if ($argc < 2)
	exit("Please provide an input file");

$input = fopen($argv[1], 'r');
if (!$input)
	echo "cannot open json node config file";

$json = '';	
while ($data = fread($input,2048)) {$json .= $data;}
$config = json_decode($json, true);

if (!$config)
	exit("cannot read config json");

$cluster_conf = $config[$argv[2]];

echo $cluster_conf['monitor_host'] . "\n";

#Connect to the monitoring db
try
{
	$dbhw = new PDO('mysql:host=' . $cluster_conf['monitor_host'] . ';dbname=' . $cluster_conf['monitor_db'], 
					$cluster_conf['monitor_username'], $cluster_conf['monitor_password']);
	$dbhw->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}
catch( PDOException $exception )
{
	echo "Monitor database unavailable: " . $exception->getMessage();
	exit;
}

#Connect to the ldap server. It's much faster to get all the names out of ldap than the DB.
$ldap = ldap_connect($cluster_conf['ldap_host'] );
if (!$ldap)
	throw new Exception("Cannot contact LDAP server", 503);

ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);

if (!ldap_bind($ldap, "uid=adminuser,ou=logins,dc=mozilla", $cluster_conf['ldap_password'])) 
	throw new Exception("Invalid LDAP Admin", 503);



foreach ($cluster_conf['tables'] as $node => $db_table)
{
	echo "processing node $node";

	
	#get the usernames
	$search = ldap_search($ldap, "dc=mozilla", "(primaryNode=weave:" . $node . ")", array('dn'), 1, 0);
	$results = ldap_get_entries($ldap, $search);
	$usernames = array();
	
	foreach ($results as $line)
	{
		if(preg_match('/^uid=(.*?),/', $line['dn'], $match))
			$usernames[] = $match[1];
	}
	sort($usernames);
	
	#connect to the db with the users data. Since we're going node by node, we can share the 
	#connection with all users in the node.
	try
	{
		$dbh = new PDO('mysql:host=' . $cluster_conf['host'] . ';dbname=' . $cluster_conf['db'], 
						$cluster_conf['username'], $cluster_conf['password']);
		$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	}
	catch( PDOException $exception )
	{
		echo "User database " . $cluster_conf['db'] . " unavailable:" . $exception->getMessage();
		exit;
	}

	#step 1: Get all users last actives from the stats db
	$user_ts = array();
	$user_last_ts = $dbhw->prepare("select username, last_active from usersummary where node = ?");
	$user_last_ts->execute(array($node));
	while ($result = $user_last_ts->fetch(PDO::FETCH_NUM))
	{
		$user_ts[$result[0]] = $result[1];
	}
	$user_last_ts->closeCursor();
	
	

	echo " (" . count($usernames) . ")\n";	
	
	#step 2: for each user, get last update. If greater than the timestamp above, fetch their row and datasize values
	$last_update = $dbh->prepare("select max(modified) from " . $db_table . " where username = ?");
	$rows = $dbh->prepare("select count(*) as ct, sum(payload_size)/1024 as size from " . $db_table . " where username = ?");

	$data = $dbhw->prepare("replace into usersummary values (?,?,?,?,NOW(),?)");
	
	foreach ($usernames as $user)
	{
		echo "\tprocessing $user\n";
		$last_update->execute(array($user));
		$last = $last_update->fetchColumn();
		$last_update->closeCursor();
		
		if ($last_update && !array_key_exists($user, $user_ts) || $last != $user_ts[$user])
		{
			$rows->execute(array($user));
			list ($count, $datasize) = $rows->fetch();
			$rows->closeCursor();

			if (!$count)
			{
				continue;
			}

			$data->execute(array($user, $node, $count, $datasize, $last));
		}	
		
		$user_ts[$user] = null; #php has no way to delete an array element. wtf?
	}
	
	
	#step 3: pull out all users in the stats db who are not in the main db (moved or deleted)
	$delete = $dbhw->prepare("delete from usersummary where username = ?");
	
	foreach ($user_ts as $user => $count)
	{
		if ($count === null)
			continue;
		$delete->execute(array($user));
	}
	
	
	#step 4: get an active user count and a total db size count
	
	$time = time("U");
	$total_users = $dbhw->prepare("select count(*) from usersummary where node = ?");
	$active_users = $dbhw->prepare("select count(*) from usersummary where node = ? and last_active > " . ($time - (60*60*40)));
	$active_size = $dbhw->prepare("insert into active_users values (NOW(), ?, ?, ?)");
	
	$total_users->execute(array($node));
	list($total) = $total_users->fetch(PDO::FETCH_NUM);
	$total_users->closeCursor();
	
	$active_users->execute(array($node));
	list($actives) = $active_users->fetch(PDO::FETCH_NUM);
	$active_users->closeCursor();
	
	$active_size->execute(array($node, $total, $actives));
	
	
	$total_size = $dbhw->prepare("select sum(datasize) from usersummary where node = ?");
	$total_data = $dbhw->prepare("insert into db_size values (NOW(), ?, ?)");
	
	$total_size->execute(array($node));
	list($size) = $total_size->fetch(PDO::FETCH_NUM);
	$total_size->closeCursor();
	$total_data->execute(array($node, $size));
	
}

#step 6: Write the data files
if ($argv[3])
{
	$data_out = fopen($argv[3] . '/users.txt', 'w');
	if (!$data_out)
		echo "cannot open users.txt";
	
	$results = array();
	$historical_size = $dbhw->prepare("select date(recorded), sum(actives) from active_users group by date(recorded) order by recorded desc");
	$historical_size->execute();
	
	while (list ($date, $datasize) = $historical_size->fetch())
	{
		$result[$date][0] = $datasize;
	}
	$historical_size->closeCursor();
	
	
	$historical_size = $dbhw->prepare("select date(recorded), sum(total) from active_users group by date(recorded) order by recorded desc");
	$historical_size->execute();
	while (list ($date, $datasize) = $historical_size->fetch())
	{
		$result[$date][1] = $datasize;
	}
	
	foreach ($result as $date => $values)
		fwrite($data_out, "$date " . ($values[0] ? $values[0] : "0") . " " . ($values[1] ? $values[1] : "0") . "\n");
	fclose ($data_out);
	
	$data_out = fopen($argv[3] . '/payload.txt', 'w');
	if (!$data_out)
		echo "cannot open payload.txt";
	
	
	$historical_size = $dbhw->prepare("select date(recorded), sum(datasize) from db_size group by date(recorded) order by recorded desc");
	$historical_size->execute();
	while (list ($date, $datasize) = $historical_size->fetch())
	{
		fwrite($data_out, "$date $datasize\n");
	}
	fclose ($data_out);
}

$dbh = null;

?>