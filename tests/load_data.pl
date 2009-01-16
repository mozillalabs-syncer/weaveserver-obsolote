#!/usr/bin/perl

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

use strict;
use LWP;
use HTTP::Request;
use HTTP::Request::Common qw/PUT GET POST/;


my $PROTOCOL = 'http';
my $SERVER = 'localhost';
my $USERNAME = 'test_user';
my $PASSWORD = 'test123';
my $ADMIN_SECRET = 'bad secret';
my $PREFIX = 'weave/0.3';
my $ADMIN_PREFIX = 'weave/admin';

my $DO_ADMIN_TESTS = 1;
my $DELETE_USER = 1;
my $USE_RANDOM_USERNAME = 0;

my $ua = LWP::UserAgent->new;
$ua->agent("Weave Server Test/0.3");
my $req;

if ($DO_ADMIN_TESTS)
{

	if ($USE_RANDOM_USERNAME)
	{
		my $length = rand(10) + 6;
		$USERNAME = '';
		for (1..$length)
		{
			my $number = int(rand(36)) + 48;
			$number += 7 if $number > 57;
			$USERNAME .= chr($number);
		}
	}
	
	#create the user
	$req = POST "$PROTOCOL://$SERVER/$ADMIN_PREFIX", ['function' => 'create', 'user' => $USERNAME, 'pass' => $PASSWORD, 'secret' => $ADMIN_SECRET];
	$req->content_type('application/x-www-form-urlencoded');
	print "create user: " . $ua->request($req)->content() . "\n";

	#create the user again
	$req = POST "$PROTOCOL://$SERVER/$ADMIN_PREFIX", ['function' => 'create', 'user' => $USERNAME, 'pass' => $PASSWORD, 'secret' => $ADMIN_SECRET];
	$req->content_type('application/x-www-form-urlencoded');
	print "create user again (should fail): " . $ua->request($req)->content() . "\n";

	#check user existence
	$req = POST "$PROTOCOL://$SERVER/$ADMIN_PREFIX", ['function' => 'check', 'user' => $USERNAME, 'secret' => $ADMIN_SECRET];
	$req->content_type('application/x-www-form-urlencoded');
	print "check user existence: " . $ua->request($req)->content() . "\n";
	
	#change the password
	$PASSWORD .= '2';
	my $req = POST "$PROTOCOL://$SERVER/$ADMIN_PREFIX", ['function' => 'update', 'user' => $USERNAME, 'pass' => $PASSWORD, 'secret' => $ADMIN_SECRET];
	$req->content_type('application/x-www-form-urlencoded');
	print "change password: " . $ua->request($req)->content() . "\n";
	
	#change password (bad secret)
	my $req = POST "$PROTOCOL://$SERVER/$ADMIN_PREFIX", ['function' => 'update', 'user' => $USERNAME, 'pass' => $PASSWORD, 'secret' => 'wrong secret'];
	$req->content_type('application/x-www-form-urlencoded');
	print "change password(bad secret): " . $ua->request($req)->content() . "\n";
}	

#clear the user
$req = HTTP::Request->new(DELETE => "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/test");
$req->authorization_basic($USERNAME, $PASSWORD);
print "delete: " . $ua->request($req)->content() . "\n";
my $id = 0;

#upload 10 items individually
print "Adding 10 records:\n";
foreach (1..10)
{

	$id++;
	my $json = '{"id": "' . $id . '","parentid":"' . ($id%3). '","sortindex":' . $id. ',"depth":1,"modified":"' . (2454725.98283 + int(rand(60))) . '","payload":"a89sdmawo58aqlva.8vj2w9fmq2af8vamva98fgqamf"}';
	my $req = PUT "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/test/$id";
	$req->authorization_basic($USERNAME, $PASSWORD);
	$req->content($json);
	$req->content_type('application/x-www-form-urlencoded');
	
	print $id . ": " . $ua->request($req)->content() . "\n";
}

#upload 10 items in batch
my $batch = "";
foreach (1..10)
{

	$id++;
	$batch .= ', {"id": "' . $id . '","parentid":"' . ($id%3). '","sortindex":' . $id. ',"modified":"' . (2454725.98283 + int(rand(60))) . '","payload":"a89sdmawo58aqlva.8vj2w9fmq2af8vamva98fgqamf"}';
}

$batch =~ s/^,/[/;
$batch .= "]";

$req = POST "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/test";
$req->content($batch);
$req->authorization_basic($USERNAME, $PASSWORD);
$req->content_type('application/x-www-form-urlencoded');

print "batch upload: " . $ua->request($req)->content() . "\n";




#do a replace
my $json = '{"id": "2","parentid":"' . ($id%3). '","sortindex":2,"modified":"' . (2454725.98283 + int(rand(60))) . '","payload":"a89sdmawo58aqlva.8vj2w9fmq2af8vamva98fgqamf"}';
my $req = PUT "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/test/$id";
$req->authorization_basic($USERNAME, $PASSWORD);
$req->content($json);
$req->content_type('application/x-www-form-urlencoded');

print "replace: " . $ua->request($req)->content() . "\n";

#do a partial replace

my $json = '{"id": "3","depth":"2"}';
my $req = PUT "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/test/$id";
$req->authorization_basic($USERNAME, $PASSWORD);
$req->content($json);
$req->content_type('application/x-www-form-urlencoded');

print "replace: " . $ua->request($req)->content() . "\n";

#do a bad put (no id)

my $json = '{"id": "","parentid":"' . ($id%3). '","modified":"' . (2454725.98283 + int(rand(60))) . '","payload":"a89sdmawo58aqlva.8vj2w9fmq2af8vamva98fgqamf"}';
my $req = PUT "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/test/";
$req->authorization_basic($USERNAME, $PASSWORD);
$req->content($json);
$req->content_type('application/x-www-form-urlencoded');

print "bad PUT (no id): " . $ua->request($req)->content() . "\n";


#do a bad put (bad json)

$json = '{"id": ","parentid":"' . ($id%3). '","modified":"' . (2454725.98283 + int(rand(60))) . '","payload":"a89sdmawo58aqlva.8vj2w9fmq2af8vamva98fgqamf"}';
my $req = PUT "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/test/$id";
$req->authorization_basic($USERNAME, $PASSWORD);
$req->content($json);
$req->content_type('application/x-www-form-urlencoded');

print "bad PUT (bad json): " . $ua->request($req)->content() . "\n";


#do a bad put (no auth)

$json = '{"id": "2","parentid":"' . ($id%3). '","modified":"' . (2454725.98283 + int(rand(60))) . '","payload":"a89sdmawo58aqlva.8vj2w9fmq2af8vamva98fgqamf"}';
my $req = PUT "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/test/$id";
$req->content($json);
$req->content_type('application/x-www-form-urlencoded');

print "bad PUT (no auth): " . $ua->request($req)->content() . "\n";

#do a bad put (wrong pw)

$json = '{"id": "2","parentid":"' . ($id%3). '","modified":"' . (2454725.98283 + int(rand(60))) . '","payload":"a89sdmawo58aqlva.8vj2w9fmq2af8vamva98fgqamf"}';
my $req = PUT "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/test/$id";
$req->authorization_basic($USERNAME, 'badpassword');
$req->content($json);
$req->content_type('application/x-www-form-urlencoded');

print "bad PUT (wrong pw): " . $ua->request($req)->content() . "\n";

#do a bad put (payload not json encoded)

$json = '{"id": "2","parentid":"' . ($id%3). '","modified":"' . (2454725.98283 + int(rand(60))) . '","payload":["a", "b"]}';
my $req = PUT "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/test/$id";
$req->authorization_basic($USERNAME, $PASSWORD);
$req->content($json);
$req->content_type('application/x-www-form-urlencoded');

print "bad PUT (payload not json-encoded): " . $ua->request($req)->content() . "\n";


#bad post (bad json);
$batch =~ s/\]$//;
$req = POST "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/test";
$req->content($batch);
$req->authorization_basic($USERNAME, $PASSWORD);
$req->content_type('application/x-www-form-urlencoded');
print "bad batch upload (bad json): " . $ua->request($req)->content() . "\n";


#post with some bad records

$batch .= "]";
$batch =~ s/parentid":"2/parentid":"3333333333333333333333333333333333333333333333333333333333333333333333333333333333333333333333333333333333/g;
$req = POST "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/test";
$req->content($batch);
$req->authorization_basic($USERNAME, $PASSWORD);
$req->content_type('application/x-www-form-urlencoded');
print "mixed batch upload (bad parentids on some): " . $ua->request($req)->content() . "\n";


# should return ["1", "2" .. "20"]
$req = GET "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/test/";
$req->authorization_basic($USERNAME, $PASSWORD);
print "should return [\"1\", \"2\" .. \"20\"] (in some order): " . $ua->request($req)->content() . "\n";

# should return ["1", "2" .. "20"]
$req = GET "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/test/?sort=index";
$req->authorization_basic($USERNAME, $PASSWORD);
print "should return [\"1\", \"2\" .. \"20\"] (in order): " . $ua->request($req)->content() . "\n";

# should return ["1", "2" .. "20"]
$req = GET "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/test/?sort=depthindex";
$req->authorization_basic($USERNAME, $PASSWORD);
print "should return [\"1\", \"2\" .. \"20\"] (3 at end): " . $ua->request($req)->content() . "\n";

# should return the user id record for #3 (check the depth)
$req = GET "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/test/3";
$req->authorization_basic($USERNAME, $PASSWORD);
print "should return record 3 (replaced depth): " . $ua->request($req)->content() . "\n";

# should return the user id record for #4
$req = GET "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/test/4";
$req->authorization_basic($USERNAME, $PASSWORD);
print "should return record 4: " . $ua->request($req)->content() . "\n";

# should return about half the ids
$req = GET "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/test/?modified=2454755";
$req->authorization_basic($USERNAME, $PASSWORD);
print "modified after halftime: " . $ua->request($req)->content() . "\n";

# should return about one-third the ids
$req = GET "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/test/?parentid=1";
$req->authorization_basic($USERNAME, $PASSWORD);
print "parent ids (mod 3 = 1): " . $ua->request($req)->content() . "\n";

# mix our params
$req = GET "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/test/?parentid=1&modified=2454755";
$req->authorization_basic($USERNAME, $PASSWORD);
print "parentid and modified: " . $ua->request($req)->content() . "\n";

#as above, but full records
$req = GET "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/test/?parentid=1&modified=2454755&full=1";
$req->authorization_basic($USERNAME, $PASSWORD);
print "parentid and modified (full records): " . $ua->request($req)->content() . "\n";

#delete the first two with $parentid = 1
$req = HTTP::Request->new(DELETE => "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/test?parentid=1&limit=2");
$req->authorization_basic($USERNAME, $PASSWORD);
print "delete 2 items: " . $ua->request($req)->content() . "\n";

# should return about one-third the ids, less the two we deleted
$req = GET "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/test/?parentid=1";
$req->authorization_basic($USERNAME, $PASSWORD);
print "parent ids (mod 3 = 1): " . $ua->request($req)->content() . "\n";



if ($DELETE_USER)
{
	#clear the user again
	my $req = HTTP::Request->new(DELETE => "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/test");
	$req->authorization_basic($USERNAME, $PASSWORD);
	print "clear: " . $ua->request($req)->content() . "\n";
}

if ($DO_ADMIN_TESTS && $DELETE_USER)
{
	#delete the user
	my $req = POST "$PROTOCOL://$SERVER/$ADMIN_PREFIX", ['function' => 'delete', 'user' => $USERNAME, 'secret' => $ADMIN_SECRET];
	$req->content_type('application/x-www-form-urlencoded');
	print "delete user: " . $ua->request($req)->content() . "\n";
}
