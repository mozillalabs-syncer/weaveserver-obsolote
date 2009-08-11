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
use Data::Dumper;
use Time::HiRes qw/gettimeofday tv_interval/;

my $PROTOCOL = 'http';
my $SERVER = 'localhost';
my $USERNAME = 'test_user';
my $PASSWORD = 'test12345';
my $ADMIN_SECRET = 'bad secret';
my $PREFIX = '0.5';
my $ADMIN_PREFIX = 'user/1';

my $VERBOSE = defined $ARGV[0] ? $ARGV[0] : 1;
my $DO_ADMIN_TESTS = defined $ARGV[1] ? $ARGV[1] : 1;
my $DELETE_USER = defined $ARGV[2] ? $ARGV[2] : 1;
my $USE_RANDOM_USERNAME = defined $ARGV[3] ? $ARGV[3] : 1;
my $LOOPS = $ARGV[4] || 1;
my $CHILDREN = $ARGV[5];
my $TIMING = $ARGV[6];

my $filelen = 0;

if (-f $USE_RANDOM_USERNAME)
{
	open(USERS, $USE_RANDOM_USERNAME);
	1 while <USERS>;
	$filelen = $.;
	close USERS;
}

if ($TIMING)
{
	open OUT, ">>$TIMING" or die "can't open $TIMING: $!";
	print OUT time() . "\t$CHILDREN\t$LOOPS\n";
	close OUT;
}

if ($CHILDREN)
{
	my $kids;
	foreach (1..$CHILDREN)
	{
		if (!defined(my $child_pid = fork()))
		{
			warn "unable to fork: $!";
		}
		elsif ($child_pid)
		{
			$kids++
		}
		else
		{
			user_work();
			exit;
		}
	}
	while ($kids > 0)
	{
		wait;
		$kids--;
	}
}
else
{
	user_work();
}

sub user_work
{
	my $ua = LWP::UserAgent->new;
	$ua->agent("Weave Server Test/0.3");
	my $req;
	my $result;
	
	my $payload = "";
	for (1..(int(rand(256) + 20)))
	{
		my $number = int(rand(36)) + 48;
		$number += 7 if $number > 57;
		$payload .= chr($number);
	}

	my $time1;
	my @timearray;		

	foreach (1..$LOOPS)
	{
		if ($USE_RANDOM_USERNAME == 1)
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
		elsif (-f $USE_RANDOM_USERNAME && $filelen > 0)
		{
			my $pos = int(rand($filelen));
			open (USERS, $USE_RANDOM_USERNAME) or die "can't open $USE_RANDOM_USERNAME: $!";
			my $i = 0;
			while ($i++ < $pos) {my $line = <USERS>}
			$USERNAME = <USERS>;
			close USERS;
			chomp $USERNAME;
			next unless $USERNAME;
		}
	
		if ($DO_ADMIN_TESTS)
		{
			#create the user
			$req = PUT "$PROTOCOL://$SERVER/$ADMIN_PREFIX/$USERNAME";
			$req->content_type('application/x-www-form-urlencoded');

			my $json = '{"password": "' . $PASSWORD . '","secret":"' . $ADMIN_SECRET. '","email":"test@test.com"}';
			$req->content($json);
				
			$time1 = [gettimeofday()] if $TIMING;
			$result = $ua->request($req)->content();
			$timearray[0] += tv_interval($time1, [gettimeofday()]) if $TIMING;
			
			print "create user: $result\n" if $VERBOSE;
		
			#create the user again
			$time1 = [gettimeofday()] if $TIMING;
			$result = $ua->request($req)->content();
			$timearray[1] += tv_interval($time1, [gettimeofday()]) if $TIMING;
			print "create user again (should fail): $result\n" if $VERBOSE;
		
			#check user existence
			$req = GET "$PROTOCOL://$SERVER/$ADMIN_PREFIX/$USERNAME";
			$result = $ua->request($req)->content();
			print "check user existence: $result\n" if $VERBOSE;
			
			#change the password
			$req = PUT "$PROTOCOL://$SERVER/$ADMIN_PREFIX/$USERNAME/password";
			$req->authorization_basic($USERNAME, $PASSWORD);
			$req->content_type('application/x-www-form-urlencoded');
			$PASSWORD .= '2';
			$req->content($PASSWORD);
				
			$time1 = [gettimeofday()] if $TIMING;
			$result = $ua->request($req)->content();
			$timearray[0] += tv_interval($time1, [gettimeofday()]) if $TIMING;
			
			#change email 
			$req = PUT "$PROTOCOL://$SERVER/$ADMIN_PREFIX/$USERNAME/email";
			$req->authorization_basic($USERNAME, $PASSWORD);
			$req->content_type('application/x-www-form-urlencoded');
			$req->content('changetest@test.com');
				
			$time1 = [gettimeofday()] if $TIMING;
			$result = $ua->request($req)->content();
			$timearray[0] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		}	
		
		if ($DELETE_USER)
		{
			#clear the user
			$req = HTTP::Request->new(DELETE => "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test");
			$req->authorization_basic($USERNAME, $PASSWORD);
			$time1 = [gettimeofday()] if $TIMING;
			$result = $ua->request($req)->content();
			$timearray[4] += tv_interval($time1, [gettimeofday()]) if $TIMING;
			print "delete: $result\n" if $VERBOSE;
		}
		
		my $id = 0;
		
		#upload 10 items individually
		print "Adding 10 records:\n" if $VERBOSE;
		foreach (1..10)
		{
		
			$id++;
			my $json = '{"id": "' . $id . '","parentid":"' . ($id%3). '","sortindex":' . $id. ',"depth":1,"payload":"' . $payload . $id . '"}';
			my $req = PUT "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test/$id";
			$req->authorization_basic($USERNAME, $PASSWORD);
			$req->content($json);
			$req->content_type('application/x-www-form-urlencoded');
			$time1 = [gettimeofday()] if $TIMING;
			$result = $ua->request($req)->content();
			$timearray[5] += tv_interval($time1, [gettimeofday()]) if $TIMING;
			
			print $id . ": $result\n" if $VERBOSE;
		}
		my $halftime = $result;
		#upload 10 items in batch
		my $batch = "";
		foreach (1..10)
		{
		
			$id++;
			$batch .= ', {"id": "' . $id . '","parentid":"' . ($id%3) . '","sortindex":' . $id. ',"payload":"' . $payload . $id . '"}';
		}
		
		$batch =~ s/^,/[/;
		$batch .= "]";
		
		
		$req = POST "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test";
		$req->content($batch);
		$req->authorization_basic($USERNAME, $PASSWORD);
		$req->content_type('application/x-www-form-urlencoded');
		{
			local $SIG{'__WARN__'} = sub{}; # stupid warn in LWP can't be suppressed any other way...
			$time1 = [gettimeofday()] if $TIMING;
			$result = $ua->request($req)->content();
			$timearray[6] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		}
		print "batch upload: $result\n" if $VERBOSE;
		
		
		#do a replace
		my $json = '{"id": "2","parentid":"' . ($id%3). '","sortindex":2,"payload":"' . $payload . $id . '"}';
		my $req = PUT "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test/$id";
		$req->authorization_basic($USERNAME, $PASSWORD);
		$req->content($json);
		$req->content_type('application/x-www-form-urlencoded');
		$time1 = [gettimeofday()] if $TIMING;
		$result = $ua->request($req)->content();
		$timearray[7] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		
		print "replace: $result\n" if $VERBOSE;
		
		#do a partial replace
		
		my $json = '{"id": "3","depth":"2"}';
		my $req = PUT "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test/$id";
		$req->authorization_basic($USERNAME, $PASSWORD);
		$req->content($json);
		$req->content_type('application/x-www-form-urlencoded');
		
		$time1 = [gettimeofday()] if $TIMING;
		my $timestamp = $ua->request($req)->content();
		$timearray[8] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		print "replace: $timestamp\n" if $VERBOSE;
		
		#do a replace (timestamp too old)
		$timestamp = $timestamp - 0.1;
		my $json = '{"id": "2","parentid":"' . ($id%3). '","sortindex":2,"payload":"' . $payload . $id . '"}';
		my $req = PUT "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test/$id", "X-If-Unmodified-Since" => $timestamp;
		$req->authorization_basic($USERNAME, $PASSWORD);
		$req->content($json);
		$req->content_type('application/x-www-form-urlencoded');
		$time1 = [gettimeofday()] if $TIMING;
		$result = $ua->request($req)->content();
		$timearray[9] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		
		print "replace (older, should fail): $result\n" if $VERBOSE;
		
		
		
		#do a bad put (no id)
		
		my $json = '{"id": "","parentid":"' . ($id%3). '","payload":"' . $payload . $id . '"}';
		my $req = PUT "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test/";
		$req->authorization_basic($USERNAME, $PASSWORD);
		$req->content($json);
		$req->content_type('application/x-www-form-urlencoded');
		$time1 = [gettimeofday()] if $TIMING;
		$result = $ua->request($req)->content();
		$timearray[10] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		
		print "bad PUT (no id): $result\n" if $VERBOSE;
		
		
		#do a bad put (bad json)
		
		$json = '{"id": ","parentid":"' . ($id%3). '","modified":"' . ($halftime + int(rand(60))) . '","payload":"' . $payload . $id . '"}';
		my $req = PUT "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test/$id";
		$req->authorization_basic($USERNAME, $PASSWORD);
		$req->content($json);
		$req->content_type('application/x-www-form-urlencoded');
		$time1 = [gettimeofday()] if $TIMING;
		$result = $ua->request($req)->content();
		$timearray[11] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		
		print "bad PUT (bad json): $result\n" if $VERBOSE;
		
		
		#do a bad put (no auth)
		
		$json = '{"id": "2","parentid":"' . ($id%3). '","modified":"' . ($halftime + int(rand(60))) . '","payload":"' . $payload . $id . '"}';
		my $req = PUT "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test/$id";
		$req->content($json);
		$req->content_type('application/x-www-form-urlencoded');
		$time1 = [gettimeofday()] if $TIMING;
		$result = $ua->request($req)->content();
		$timearray[12] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		
		print "bad PUT (no auth): $result\n" if $VERBOSE;
		
		#do a bad put (wrong pw)
		
		$json = '{"id": "2","parentid":"' . ($id%3). '","modified":"' . ($halftime + int(rand(60))) . '","payload":"' . $payload . $id . '"}';
		my $req = PUT "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test/$id";
		$req->authorization_basic($USERNAME, 'badpassword');
		$req->content($json);
		$req->content_type('application/x-www-form-urlencoded');
		$time1 = [gettimeofday()] if $TIMING;
		$result = $ua->request($req)->content();
		$timearray[13] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		
		print "bad PUT (wrong pw): $result\n" if $VERBOSE;
		
		#do a bad put (payload not json encoded)
		
		$json = '{"id": "2","parentid":"' . ($id%3). '","modified":"' . ($halftime + int(rand(60))) . '","payload":["a", "b"]}';
		my $req = PUT "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test/$id";
		$req->authorization_basic($USERNAME, $PASSWORD);
		$req->content($json);
		$req->content_type('application/x-www-form-urlencoded');
		$time1 = [gettimeofday()] if $TIMING;
		$result = $ua->request($req)->content();
		$timearray[14] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		
		print "bad PUT (payload not json-encoded): $result\n" if $VERBOSE;
		
		
		#bad post (bad json);
		$batch =~ s/\]$//;
		$req = POST "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test";
		$req->content($batch);
		$req->authorization_basic($USERNAME, $PASSWORD);
		$req->content_type('application/x-www-form-urlencoded');
		{
			local $SIG{'__WARN__'} = sub{}; # stupid warn in LWP can't be suppressed any other way...
			$time1 = [gettimeofday()] if $TIMING;
			$result = $ua->request($req)->content();
			$timearray[15] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		}
		print "bad batch upload (bad json): $result\n" if $VERBOSE;
		
		
		#post with some bad records
		
		$batch .= "]";
		$batch =~ s/parentid":"2"/parentid":"3333333333333333333333333333333333333333333333333333333333333333333333333333333333333333333333333333333333"/g;
		$req = POST "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test";
		$req->content($batch);
		$req->authorization_basic($USERNAME, $PASSWORD);
		$req->content_type('application/x-www-form-urlencoded');
		{
			local $SIG{'__WARN__'} = sub{}; # stupid warn in LWP can't be suppressed any other way...
			$time1 = [gettimeofday()] if $TIMING;
			$result = $ua->request($req)->content();
			$timearray[16] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		}
		print "mixed batch upload (bad parentids on some): $result\n" if $VERBOSE;
		
		
		# should return ["1", "2" .. "20"]
		$req = GET "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test/";
		$req->authorization_basic($USERNAME, $PASSWORD);
		$time1 = [gettimeofday()] if $TIMING;
		$result = $ua->request($req)->content();
		$timearray[17] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		print "should return [\"1\", \"2\" .. \"20\"] (in some order): $result\n" if $VERBOSE;
		
		# should return ["1", "2" .. "20"]
		$req = GET "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test/?sort=index";
		$req->authorization_basic($USERNAME, $PASSWORD);
		$time1 = [gettimeofday()] if $TIMING;
		$result = $ua->request($req)->content();
		$timearray[18] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		print "should return [\"1\", \"2\" .. \"20\"] (in order): $result\n" if $VERBOSE;
		
		# should return ["1", "2" .. "20"]
		$req = GET "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test/?sort=depthindex";
		$req->authorization_basic($USERNAME, $PASSWORD);
		$time1 = [gettimeofday()] if $TIMING;
		$result = $ua->request($req)->content();
		$timearray[19] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		print "should return [\"1\", \"2\" .. \"20\"] (3 at end): $result\n" if $VERBOSE;
		
		# should return the user id record for #3 (check the depth)
		$req = GET "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test/3";
		$req->authorization_basic($USERNAME, $PASSWORD);
		$time1 = [gettimeofday()] if $TIMING;
		$result = $ua->request($req)->content();
		$timearray[20] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		print "should return record 3 (replaced depth): $result\n" if $VERBOSE;
		
		# should return the user id record for #4
		$req = GET "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test/4";
		$req->authorization_basic($USERNAME, $PASSWORD);
		$time1 = [gettimeofday()] if $TIMING;
		$result = $ua->request($req)->content();
		$timearray[21] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		print "should return record 4: $result\n" if $VERBOSE;
		
		# should return the user id record for #4, #5 and #6
		$req = GET "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test/?ids=4,5,6";
		$req->authorization_basic($USERNAME, $PASSWORD);
		$time1 = [gettimeofday()] if $TIMING;
		$result = $ua->request($req)->content();
		$timearray[21] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		print "should return 4, 5 and 6: $result\n" if $VERBOSE;
		
		# should return about half the ids
		$req = GET "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test/?newer=$halftime";
		$req->authorization_basic($USERNAME, $PASSWORD);
		$time1 = [gettimeofday()] if $TIMING;
		$result = $ua->request($req)->content();
		$timearray[22] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		print "modified after halftime: $result\n" if $VERBOSE;
		
		# should return about one-third the ids
		$req = GET "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test/?parentid=1";
		$req->authorization_basic($USERNAME, $PASSWORD);
		$time1 = [gettimeofday()] if $TIMING;
		$result = $ua->request($req)->content();
		$timearray[23] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		print "parent ids (mod 3 = 1): $result\n" if $VERBOSE;
		
		# mix our params
		$req = GET "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test/?parentid=1&newer=$halftime";
		$req->authorization_basic($USERNAME, $PASSWORD);
		$time1 = [gettimeofday()] if $TIMING;
		$result = $ua->request($req)->content();
		$timearray[24] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		print "parentid and modified: $result\n" if $VERBOSE;
		
		#as above, but full records
		$req = GET "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test/?parentid=1&newer=$halftime&full=1";
		$req->authorization_basic($USERNAME, $PASSWORD);
		$time1 = [gettimeofday()] if $TIMING;
		$result = $ua->request($req)->content();
		$timearray[25] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		print "parentid and modified (full records): $result\n" if $VERBOSE;
		
		#delete the first two with $parentid = 1
		$req = HTTP::Request->new(DELETE => "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test?parentid=1&limit=2");
		$req->authorization_basic($USERNAME, $PASSWORD);
		$time1 = [gettimeofday()] if $TIMING;
		$result = $ua->request($req)->content();
		$timearray[26] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		print "delete 2 items: $result\n" if $VERBOSE;
		
		#delete records 4 and 6
		$req = HTTP::Request->new(DELETE => "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test?ids=4,6");
		$req->authorization_basic($USERNAME, $PASSWORD);
		$time1 = [gettimeofday()] if $TIMING;
		$result = $ua->request($req)->content();
		$timearray[26] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		print "delete ids 4 and 6: $result\n" if $VERBOSE;
		
		# should return about one-third the ids, less the two we deleted
		$req = GET "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test/?parentid=1";
		$req->authorization_basic($USERNAME, $PASSWORD);
		$time1 = [gettimeofday()] if $TIMING;
		$result = $ua->request($req)->content();
		$timearray[27] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		print "parent ids (mod 3 = 1): $result\n" if $VERBOSE;
		
		# should return everything left
		$req = GET "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test/";
		$req->authorization_basic($USERNAME, $PASSWORD);
		$time1 = [gettimeofday()] if $TIMING;
		$result = $ua->request($req)->content();
		$timearray[17] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		print "should return everything left: $result\n" if $VERBOSE;
		
		# should return ['test']
		$req = GET "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/info/collections";
		$req->authorization_basic($USERNAME, $PASSWORD);
		$time1 = [gettimeofday()] if $TIMING;
		$result = $ua->request($req)->content();
		$timearray[28] += tv_interval($time1, [gettimeofday()]) if $TIMING;
		print "collection list: $result\n" if $VERBOSE;
		
		
		if ($DELETE_USER)
		{
			#clear the user again
			my $req = HTTP::Request->new(DELETE => "$PROTOCOL://$SERVER/$PREFIX/$USERNAME/storage/test");
			$req->authorization_basic($USERNAME, $PASSWORD);
			$time1 = [gettimeofday()] if $TIMING;
			$result = $ua->request($req)->content();
			$timearray[29] += tv_interval($time1, [gettimeofday()]) if $TIMING;
			print "clear: $result\n" if $VERBOSE;
		}
		
		if ($DO_ADMIN_TESTS && $DELETE_USER)
		{
			#delete the user
			my $req = HTTP::Request->new(DELETE => "$PROTOCOL://$SERVER/$ADMIN_PREFIX/$USERNAME");
			$req->content_type('application/x-www-form-urlencoded');
			$req->authorization_basic($USERNAME, $PASSWORD);
			$time1 = [gettimeofday()] if $TIMING;
			$result = $ua->request($req)->content();
			$timearray[30] += tv_interval($time1, [gettimeofday()]) if $TIMING;
			print "delete user: $result\n" if $VERBOSE;
		}
	}
	
	if ($TIMING)
	{
		open OUT, ">>$TIMING";
		$timearray[5] = $timearray[5]/10;
		@timearray = map {sprintf("%0.4f", $_ / $LOOPS)} @timearray;
		print OUT join("\t", @timearray) . "\n";
		close OUT;
	}
}