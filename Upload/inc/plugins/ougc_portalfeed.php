<?php

/***************************************************************************
 *
 *   OUGC Portal Feed plugin (/inc/plugins/ougc_portalfeed.php)
 *	 Author: Omar Gonzalez
 *   Copyright: © 2012-2014 Omar Gonzalez
 *   
 *   Website: http://omarg.me
 *
 *   Adds a feed to the portal page, using threads available only in the portal.
 *
 ***************************************************************************
 
****************************************************************************
	This program is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.
	
	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
	
	You should have received a copy of the GNU General Public License
	along with this program.  If not, see <http://www.gnu.org/licenses/>.
****************************************************************************/

// Die if IN_MYBB is not defined, for security reasons.
defined('IN_MYBB') or die('This file cannot be accessed directly.');

// Run/Add Hooks
if(THIS_SCRIPT == 'portal.php')
{
	global $settings;

	// All right, so what if fid = -1? Lest make that equal to all forums
	if($settings['portal_announcementsfid'] == '-1')
	{
		global $forum_cache;
		$forum_cache or cache_forums();

		$fids = array();
		foreach($forum_cache as $forum)
		{
			if($forum['type'] == 'f' && $forum['active'] == 1 && $forum['open'] == 1)
			{
				$fids[(int)$forum['fid']] = (int)$forum['fid'];
			}
		}
		$settings['portal_announcementsfid'] = implode(',', array_unique($fids));
	}

	$plugins->add_hook('global_start', 'ougc_portalfeed_run', -99999);
}

// PLUGINLIBRARY
defined('PLUGINLIBRARY') or define('PLUGINLIBRARY', MYBB_ROOT.'inc/plugins/pluginlibrary.php');

// Plugin API
function ougc_portalfeed_info()
{
	global $lang;
	ougc_portalfeed_lang_load();

	return array(
		'name'			=> 'OUGC Portal Feed',
		'description'	=> $lang->ougc_portalfeed_desc,
		'website'		=> 'http://omarg.me',
		'author'		=> 'Omar G.',
		'authorsite'	=> 'http://omarg.me',
		'version'		=> '1.1',
		'versioncode'	=> 1100,
		'compatibility'	=> '16*',
		'guid'			=> 'd4ada16c00542cad9d0888e4bfb968b5',
		'pl'			=> array(
			'version'	=> 12,
			'url'		=> 'http://mods.mybb.com/view/pluginlibrary'
		)
	);
}

// _activate
function ougc_portalfeed_activate()
{
	global $cache;
	ougc_portalfeed_pl_check();

	// Insert/update version into cache
	$plugins = $cache->read('ougc_plugins');
	if(!$plugins)
	{
		$plugins = array();
	}

	$info = ougc_portalfeed_info();

	if(!isset($plugins['portalfeed']))
	{
		$plugins['portalfeed'] = $info['versioncode'];
	}

	/*~*~* RUN UPDATES START *~*~*/

	/*~*~* RUN UPDATES END *~*~*/

	$plugins['portalfeed'] = $info['versioncode'];
	$cache->update('ougc_plugins', $plugins);
}

// _is_installed() routine
function ougc_portalfeed_is_installed()
{
	global $cache;

	$plugins = (array)$cache->read('ougc_plugins');

	return !empty($plugins['portalfeed']);
}

// _uninstall() routine
function ougc_portalfeed_uninstall()
{
	global $PL, $cache;
	ougc_portalfeed_pl_check();

	// Delete version from cache
	$plugins = (array)$cache->read('ougc_plugins');

	if(isset($plugins['portalfeed']))
	{
		unset($plugins['portalfeed']);
	}

	if(!empty($plugins))
	{
		$cache->update('ougc_plugins', $plugins);
	}
	else
	{
		$PL->cache_delete('ougc_plugins');
	}
}

// Loads language strings
function ougc_portalfeed_lang_load()
{
	global $lang;

	isset($lang->ougc_portalfeed_desc) or $lang->load('ougc_portalfeed', false, true);

	if(!isset($lang->ougc_portalfeed_desc))
	{
		// Plugin API
		$lang->ougc_portalfeed_desc = 'Adds a feed to the portal page, using threads available only in the portal.';

		// PluginLibrary
		$lang->ougc_portalfeed_pl_required = 'This plugin requires <a href="{1}">PluginLibrary</a> version {2} or later to be uploaded to your forum.';
		$lang->ougc_portalfeed_pl_old = 'This plugin requires <a href="{1}">PluginLibrary</a> version {2} or later, whereas your current version is {3}.';
	}
}

// PluginLibrary dependency check & load
function ougc_portalfeed_pl_check()
{
	global $lang;
	ougc_portalfeed_lang_load();
	$info = ougc_portalfeed_info();

	if(!file_exists(PLUGINLIBRARY))
	{
		flash_message($lang->sprintf($lang->ougc_portalfeed_pl_required, $info['pl']['url'], $info['pl']['version']), 'error');
		admin_redirect('index.php?module=config-plugins');
		exit;
	}

	global $PL;

	$PL or require_once PLUGINLIBRARY;

	if($PL->version < $info['pl']['version'])
	{
		flash_message($lang->sprintf($lang->ougc_portalfeed_pl_old, $info['pl']['url'], $info['pl']['version'], $PL->version), 'error');
		admin_redirect('index.php?module=config-plugins');
		exit;
	}
}

// Output the RSS :D
function ougc_portalfeed_run()
{
	global $mybb;

	if(!isset($mybb->input['action']) || my_strtolower($mybb->input['action']) != 'rss')
	{
		return;
	}

	global $db, $PL, $forum_cache, $lang;
	$PL or require_once PLUGINLIBRARY;
	$forum_cache or cache_forums();

	$page = (isset($mybb->input['page']) && $mybb->input['page'] > 0 ? (int)$mybb->input['page'] : 1);

	if(($limit = &$mybb->settings['portal_numannouncements']) < 1)
	{
		$limit = 10;
	}

	// Build a where clause
	$where = array();
	$where[] = 'visible=\'1\'';
	$where[] = 'closed NOT LIKE \'moved|%\'';

	if($unviewableforums = get_unviewable_forums(true))
	{
		$where[] = 'fid NOT IN('.$unviewableforums.')';
	}

	// START: OUGC Show In Portal
	if(function_exists('ougc_showinportal_info'))
	{
		$where[] = 'showinportal=\'1\'';
	}
	// END: OUGC Show In Portal

	$input = $options = array();
	foreach($mybb->input as $key => &$val)
	{
		switch($key)
		{
			case 'limit':
				$input[$key] = $limit = (int)$val;
				break;
			/*case 'uid':
			case 'username':*/
			case 'author':
				if(!is_numeric($val))
				{
					$query = $db->simple_select('users', 'uid', 'LOWER(username)=\''.$db->escape_string(my_strtolower($val)).'\'', array('limit' => 1));

					$where[] = 'uid=\''.(int)$db->fetch_field($query, 'uid').'\'';

					$input[$key] = (string)$val;
					break;
				}

				$where[] = 'uid=\''.(int)$val.'\'';

				$input[$key] = (int)$val;
				break;
			case 'prefix':
				if(!is_numeric($val))
				{
					$val = my_strtolower($mybb->input[$key]);
					$prefixes = (array)$mybb->cache->read('threadprefixes');
					foreach($prefixes as $prefix)
					{
						if($val == my_strtolower($prefix['prefix']))
						{
							$where[] = 'prefix=\''.(int)$prefix['pid'].'\'';

							$input[$key] = (string)$val;
							break;
						}
					}
					break;
				}

				$where[] = 'prefix=\''.(int)$val.'\'';

				$input[$key] = (int)$val;
				break;
			case 'forum':
				// Google SEO URL support
				// Code from Starpaul20's Move Posts plugin
				if(!is_numeric($val))
				{
					if(!$db->table_exists('google_seo'))
					{
						break;
					}

					// Build regexp to match URL.
					$regexp = $mybb->settings['bburl'].'/'.$mybb->settings['google_seo_url_forums'];

					if($regexp)
					{
						$regexp = preg_quote($regexp, '#');
						$regexp = str_replace('\\{\\$url\\}', '([^./]+)', $regexp);
						$regexp = str_replace('\\{url\\}', '([^./]+)', $regexp);
						$regexp = '#^'.$regexp.'$#u';
					}

					// Fetch the (presumably) Google SEO URL:
					$url = $input[$key] = $val = (string)$val;

					// $url can be either 'http://host/Thread-foobar' or just 'foobar'.

					// Kill anchors and parameters.
					$url = preg_replace('/^([^#?]*)[#?].*$/u', '\\1', $url);

					// Extract the name part of the URL.
					$url = preg_replace($regexp, '\\1', $url);

					// Unquote the URL.
					$url = urldecode($url);

					// If $url was 'http://host/Thread-foobar', it is just 'foobar' now.

					// Look up the ID for this item.
					$query = $db->simple_select('google_seo', 'id', 'idtype=\'3\' AND url=\''.$db->escape_string($url).'\'');

					$mybb->settings['portal_announcementsfid'] = (int)$db->fetch_field($query, 'id');
					break;
				}

				$input[$key] = (int)$val;
				$mybb->settings['portal_announcementsfid'] = (int)$val;
				break;
			case 'poll':
			case 'sticky':
				$where[] = $key.($val == 1 ? '!' : '').'=\'0\'';

				$input[$key] = (int)$val;
				break;
			case 'order_by':
				$val = my_strtolower($val);
				if(in_array($val, array('dateline', 'lastpost', 'replies')))
				{
					$options[$key] = $val;
				}
				$input[$key] = $val;
				break;
			case 'order_dir':
				$options[$key] = (my_strtolower($val) == 'asc' ? 'ASC' : 'DESC');
				$input[$key] = $val;
				break;
		}
	}

	$where[] = 'fid IN (\''.implode('\',\'', array_map('intval', explode(',', $mybb->settings['portal_announcementsfid']))).'\')';

	if(!isset($options['order_by']))
	{
		$options['order_by'] = 'dateline';
	}
	if(!isset($options['order_dir']))
	{
		$options['order_dir'] = 'DESC';
	}

	require_once MYBB_ROOT.'inc/class_feedgeneration.php';
	$feedgenerator = new FeedGenerator();

	require_once MYBB_ROOT.'inc/class_parser.php';
	$parser = new postParser;

	$feedgenerator->set_feed_format((isset($mybb->input['type']) ? $mybb->input['type'] : 'rss2.0'));

	$feedgenerator->set_channel(array(
		'title'			=>	htmlspecialchars_uni($mybb->settings['homename']),
		'link'			=>	$mybb->settings['bburl'].'/',
		'date'			=>	TIME_NOW,
		'description'	=>	$mybb->settings['homename'].' - '.$mybb->settings['bburl']
	));

	// Loop through the threads
	$query = $db->query('
		SELECT t.subject, t.fid, t.tid, t.dateline, p.message, p.edittime, u.username
		FROM '.TABLE_PREFIX.'threads t
		LEFT JOIN '.TABLE_PREFIX.'posts p ON (p.pid=t.firstpost)
		LEFT JOIN '.TABLE_PREFIX.'users u ON (u.uid=t.uid)
		WHERE t.'.implode(' AND t.', $where).'
		ORDER BY t.'.$options['order_by'].' '.$options['order_dir'].', t.dateline DESC
		LIMIT 0, '.$limit
		);

	while($thread = $db->fetch_array($query))
	{
		$forum = $forum_cache[$thread['fid']];

		$parser_options = array(
			'allow_html'		=>	(int)$forum['allowhtml'],
			'allow_mycode'		=>	(int)$forum['allowmycode'],
			'allow_smilies'		=>	(int)$forum['allowsmilies'],
			'allow_imgcode'		=>	(int)$forum['allowimgcode'],
			'allow_videocode'	=>	(int)$forum['allowvideocode'],
			'filter_badwords'	=>	 1
		);

		$threadlink = htmlspecialchars_uni(get_thread_link($thread['tid']));

		// START: OUGC Show In Portal
		if(function_exists('ougc_showinportal_cutoff'))
		{
			ougc_showinportal_cutoff($thread['message'], $thread['fid'], $thread['tid']);
		}
		// END: OUGC Show In Portal

		$feedgenerator->add_item(array(
			'title'			=>	htmlspecialchars_uni($parser->parse_badwords($thread['subject'])),
			'link'			=>	$mybb->settings['bburl'].'/'.$threadlink,
			'date'			=>	(int)$thread['dateline'],
			'updated'		=>	(int)$thread['edittime'],
			'description'	=>	$parser->parse_message($thread['message'], $parser_options),
			'author'		=>	htmlspecialchars_uni(($thread['username'] ? $thread['username'] : $lang->guest))
		));
	}

	$feedgenerator->output_feed();
	exit;
}