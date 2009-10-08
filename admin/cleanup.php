<?php

#create table usersummary(username varbinary(32) primary key, rows int, datasize int, checked datetime, last_update decimal(12,2));
#create table db_size(recorded datetime, cluster varbinary(32), datasize bigint);

#elapsed time before we delete history and form data (30 days)
$deletetime = time() - (60 * 60 * 24 * 30);

#assuming we run weekly, anyone who hasn't been active in a month and a day no longer has data to be cleaned up
$abandontime = $deletetime - (60 * 60 * 24 * 7);

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
	$search = ldap_search($ldap, "dc=mozilla", "(primaryNode=weave:" . $node . ".services.mozilla.com)", array('dn'), 1, 0);
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
	$delete_statement = $dbh->prepare("delete from " . $db_table . " where username = ? and modified < ? and (collection = 'history' or collection = 'forms' or payload is NULL)");

	
	foreach ($usernames as $user)
	{
		echo "\tprocessing $user - ";
		
		if (array_key_exists($user, $user_ts) && $user_ts[$user] < $abandontime)
			continue;

		$delete_statement->execute(array($user, $deletetime));
		echo $delete_statement->rowCount();

		echo "\n";
	}
	
$dbh = null;

?>