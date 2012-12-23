<?php

/***************************************************************************
 *
 *   OUGC Portal Feed plugin (/inc/plugins/ougc_profilecomments.php)
 *	 Author: Omar Gonzalez
 *   Copyright: © 2012 Omar Gonzalez
 *   
 *   Website: http://community.mybb.com/user-25096.html
 *
 *   Shows a feed from the portal, using threads avaible in the portal.
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

// Run the ACP hooks.
if(!defined('IN_ADMINCP') && defined('THIS_SCRIPT') && THIS_SCRIPT == 'portal.php')
{
	global $mybb;

	// All right, so what if fid = -1? Lest make that equal to all forums
	if($mybb->settings['portal_announcementsfid'] == '-1')
	{
		global $forum_cache;
		$forum_cache or cache_forums();

		$fids = array(0);
		foreach($forum_cache as $forum)
		{
			if($forum['type'] == 'f' && $forum['active'] == 1 && $forum['open'] == 1)
			{
				$fids[] = (int)$forum['fid'];
			}
		}
		$mybb->settings['portal_announcementsfid'] = implode(',', array_unique($fids));
	}

	if(!empty($mybb->input['action']) && my_strtolower($mybb->input['action']) == 'rss')
	{
		$plugins->add_hook('global_start', 'ougc_portalfeed', -99999);
	}
}

// Necessary plugin information for the ACP plugin manager.
function ougc_portalfeed_info()
{
	return array(
		'name'			=> 'OUGC Portal Feed',
		'description'	=> 'Shows a feed from the portal, using threads avaible in the portal.',
		'website'		=> 'http://udezain.com.ar/',
		'author'		=> 'Omar G.',
		'authorsite'	=> 'http://udezain.com.ar/',
		'version'		=> '1.0',
		'compatibility'	=> '16*',
		'guid'			=> ''
	);
}

function ougc_portalfeed()
{
	global $mybb;

	$announcementsfids = array_unique(array_map('intval', explode(',', $mybb->settings['portal_announcementsfid'])));

	$where = '';

	// OUGC Show In Portal
	$plugins = $mybb->cache->read('plugins');
	if(($sip_active = !empty($plugins['active']['ougc_showinportal'])))
	{
		$where .= ' AND t.showinportal=\'1\'';
	}
	unset($plugins);

	if($unviewableforums = get_unviewable_forums(true))
	{
		$where .= ' AND t.fid NOT IN('.$unviewableforums.')';
	}

	/*if($inactiveforums = get_inactive_forums())
	{
		$where .= ' AND t.fid NOT IN('.$inactiveforums.')';
	}*/

	global $db, $lang, $forum_cache;
	$forum_cache or cache_forums();

	require_once MYBB_ROOT.'inc/class_feedgeneration.php';
	$feedgenerator = new FeedGenerator();

	require_once MYBB_ROOT.'inc/class_parser.php';
	$parser = new postParser;

	$feedgenerator->set_feed_format((isset($mybb->input['type']) ? $mybb->input['type'] : 'rss2.0'));

	$feedgenerator->set_channel(array(
		'title'			=>	htmlspecialchars_uni($mybb->settings['homename']),
		'link'			=>	$mybb->settings['bburl'].'/',
		'date'			=>	TIME_NOW,
		'description'	=>	$mybb->settings['homename'].' - '.$mybb->settings['homeurl']
	));

	// Loop through all the threads.
	$query = $db->query('
		SELECT t.subject, t.fid, t.tid, t.dateline, p.message, p.edittime, u.username
		FROM '.TABLE_PREFIX.'threads t
		LEFT JOIN '.TABLE_PREFIX.'posts p ON (p.pid=t.firstpost)
		LEFT JOIN '.TABLE_PREFIX.'users u ON (u.uid=t.uid)
		WHERE t.fid IN (\''.implode('\',\'', $announcementsfids).'\') AND t.visible=\'1\' AND t.closed NOT LIKE \'moved|%\''.$where.'
		ORDER BY t.dateline DESC
		LIMIT 0, 20
	');

	while($thread = $db->fetch_array($query))
	{
		$parser_options = array(
			'allow_html'		=>	$forum_cache[$thread['fid']]['allowhtml'],
			'allow_mycode'		=>	$forum_cache[$thread['fid']]['allowmycode'],
			'allow_smilies'		=>	$forum_cache[$thread['fid']]['allowsmilies'],
			'allow_imgcode'		=>	$forum_cache[$thread['fid']]['allowimgcode'],
			'allow_videocode'	=>	$forum_cache[$thread['fid']]['allowvideocode'],
			'filter_badwords'	=>	 1
		);

		$threadlink = htmlspecialchars_uni(get_thread_link($thread['tid']));

		// OUGC Show In Portal
		if($sip_active)
		{
			ougc_showinportal_readmore($thread['message'], $thread['fid'], $thread['tid']);
		}

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