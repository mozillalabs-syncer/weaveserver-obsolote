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

class wbo
{
	var $wbo_hash = array();
	var $_collection;
	var $_error = array();
	
	function extract_json(&$json)
	{
		
		$extracted = is_string($json) ? json_decode($json, true) : $json;

		#need to check the json was valid here...
		if (!$extracted)
		{
			return 0;
		}
		
		$this->id($extracted{'id'});
		$this->parentid($extracted{'parentid'});
		$this->encryption($extracted{'encryption'});
		
		$this->payload($extracted{'payload'});
		$this->encoding($extracted{'encoding'});
		return 1;
	}
	
	function populate($id, $collection, $parent = '', $encryption = '', $modified, $encoding = 'utf8', $payload)
	{
		$this->id($id);
		$this->collection($collection);
		$this->parentid($parent);
		$this->encryption($encryption);
		$this->modified($modified);
		$this->encoding($encoding);
		$this->payload($payload);
	}

	function id($id = null)
	{
		if ($id) { $this->wbo_hash{'id'} = $id; }
		return array_key_exists('id', $this->wbo_hash) ?  $this->wbo_hash{'id'} : "";
	}
	
	function collection($collection = null)
	{
		if ($collection){ $this->_collection = $collection; }
		return $this->_collection;
	}
	
	function parentid($parentid = null)
	{
		if ($parentid){ $this->wbo_hash{'parentid'} = $parentid; }
		return array_key_exists('parentid', $this->wbo_hash) ?  $this->wbo_hash{'parentid'} : "";
	}
	
	function encryption($encryption = null)
	{
		if ($encryption){ $this->wbo_hash{'encryption'} = $encryption; }
		return array_key_exists('encryption', $this->wbo_hash) ? $this->wbo_hash{'encryption'} : "";
	}

	function modified($modified = null)
	{
		if ($modified){ $this->wbo_hash{'modified'} = $modified; }
		return array_key_exists('modified', $this->wbo_hash) ?  $this->wbo_hash{'modified'} : "";
	}
	
	function encoding($encoding = null)
	{
		if ($encoding){ $this->wbo_hash{'encoding'} = $encoding; }
		return array_key_exists('encoding', $this->wbo_hash) ?  $this->wbo_hash{'encoding'} : "";
	}

	function payload($payload = null)
	{
		if ($payload){ $this->wbo_hash{'payload'} = $payload; }
		return array_key_exists('payload', $this->wbo_hash) ?  $this->wbo_hash{'payload'} : null;
	}
	
	function validate()
	{
		
		if (!$this->id() || strlen($this->id()) > 64)
		{ $this->_error[] = "invalid id"; }

		if ($this->parentid() && strlen($this->parentid()) > 64)
		{ $this->_error[] = "invalid parentid"; }

		if (!is_numeric($this->modified()))
		{ $this->_error[] = "invalid modified date"; }
		
		if ($this->encryption() && strlen($this->encryption()) > 256)
		{ $this->_error[] = "invalid encryption"; }

		if ($this->encoding() && strlen($this->encoding()) > 16)
		{ $this->_error[] = "invalid encryption"; }

		if (!$this->modified())
		{ $this->_error[] = "no modification date"; }

		if (!$this->_collection || strlen($this->_collection) > 64)
		{ $this->_error[] = "invalid collection"; }
		
		
		if (!is_string($this->wbo_hash{'payload'}))
		{ $this->_error[] = "payload needs to be json-encoded"; }
		
		if (getenv('WEAVE_PAYLOAD_MAX_SIZE') && strlen($this->wbo_hash{'payload'}) > getenv('WEAVE_PAYLOAD_MAX_SIZE'))
		{ $this->_error[] = "payload too large"; }
		
		return !$this->get_error();
	}

	function get_error()
	{
		return $this->_error;
	}
	
	function clear_error()
	{
		$this->_error = array();
	}
	
	function json()
	{
		return json_encode($this->wbo_hash);
	}
}


?>