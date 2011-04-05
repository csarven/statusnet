<?php
/**
 * StatusNet - the distributed open-source microblogging tool
 * Copyright (C) 2011, StatusNet, Inc.
 *
 * A plugin to enable local tab subscription
 *
 * PHP version 5
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @category  TagSubPlugin
 * @package   StatusNet
 * @author    Brion Vibber <brion@status.net>
 * @copyright 2011 StatusNet, Inc.
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html AGPL 3.0
 * @link      http://status.net/
 */

if (!defined('STATUSNET')) {
    exit(1);
}

/**
 * TagSub plugin main class
 *
 * @category  TagSubPlugin
 * @package   StatusNet
 * @author    Brion Vibber <brionv@status.net>
 * @copyright 2011 StatusNet, Inc.
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html AGPL 3.0
 * @link      http://status.net/
 */
class TagSubPlugin extends Plugin
{
    const VERSION         = '0.1';

    /**
     * Database schema setup
     *
     * @see Schema
     *
     * @return boolean hook value; true means continue processing, false means stop.
     */
    function onCheckSchema()
    {
        $schema = Schema::get();
        $schema->ensureTable('tagsub', TagSub::schemaDef());
        return true;
    }

    /**
     * Load related modules when needed
     *
     * @param string $cls Name of the class to be loaded
     *
     * @return boolean hook value; true means continue processing, false means stop.
     */
    function onAutoload($cls)
    {
        $dir = dirname(__FILE__);

        switch ($cls)
        {
        case 'TagSub':
            include_once $dir.'/'.$cls.'.php';
            return false;
        case 'TagsubAction':
        case 'TagunsubAction':
        case 'TagsubsAction':
        case 'TagSubForm':
        case 'TagUnsubForm':
            include_once $dir.'/'.strtolower($cls).'.php';
            return false;
        default:
            return true;
        }
    }

    /**
     * Map URLs to actions
     *
     * @param Net_URL_Mapper $m path-to-action mapper
     *
     * @return boolean hook value; true means continue processing, false means stop.
     */
    function onRouterInitialized($m)
    {
        $m->connect('tag/:tag/subscribe',
                    array('action' => 'tagsub'),
                    array('tag' => Router::REGEX_TAG));
        $m->connect('tag/:tag/unsubscribe',
                    array('action' => 'tagunsub'),
                    array('tag' => Router::REGEX_TAG));

        $m->connect(':nickname/tag-subscriptions',
                    array('action' => 'tagsubs'),
                    array('nickname' => Nickname::DISPLAY_FMT));
        return true;
    }

    /**
     * Plugin version data
     *
     * @param array &$versions array of version data
     *
     * @return value
     */
    function onPluginVersion(&$versions)
    {
        $versions[] = array('name' => 'TagSub',
                            'version' => self::VERSION,
                            'author' => 'Brion Vibber',
                            'homepage' => 'http://status.net/wiki/Plugin:TagSub',
                            'rawdescription' =>
                            // TRANS: Plugin description.
                            _m('Plugin to allow following all messages with a given tag.'));
        return true;
    }

    /**
     * Hook inbox delivery setup so tag subscribers receive all
     * notices with that tag in their inbox.
     *
     * Currently makes no distinction between local messages and
     * remote ones which happen to come in to the system. Remote
     * notices that don't come in at all won't ever reach this.
     *
     * @param Notice $notice
     * @param array $ni in/out map of profile IDs to inbox constants
     * @return boolean hook result
     */
    function onStartNoticeWhoGets(Notice $notice, array &$ni)
    {
        foreach ($notice->getTags() as $tag) {
            $tagsub = new TagSub();
            $tagsub->tag = $tag;
            $tagsub->find();

            while ($tagsub->fetch()) {
                // These constants are currently not actually used, iirc
                $ni[$tagsub->profile_id] = NOTICE_INBOX_SOURCE_SUB;
            }
        }
        return true;
    }

    /**
     *
     * @param TagAction $action
     * @return boolean hook result
     */
    function onStartTagShowContent(TagAction $action)
    {
        $user = common_current_user();
        if ($user) {
            $tag = $action->trimmed('tag');
            $tagsub = TagSub::pkeyGet(array('tag' => $tag,
                                            'profile_id' => $user->id));
            if ($tagsub) {
                $form = new TagUnsubForm($action, $tag);
            } else {
                $form = new TagSubForm($action, $tag);
            }
            $action->elementStart('div', 'entity_actions');
            $action->elementStart('ul');
            $action->elementStart('li', 'entity_subscribe');
            $form->show();
            $action->elementEnd('li');
            $action->elementEnd('ul');
            $action->elementEnd('div');
        }
        return true;
    }

    /**
     * Menu item for personal subscriptions/groups area
     *
     * @param Widget $widget Widget being executed
     *
     * @return boolean hook return
     */

    function onEndSubGroupNav($widget)
    {
        $action = $widget->out;
        $action_name = $action->trimmed('action');

        $action->menuItem(common_local_url('tagsubs', array('nickname' => $action->user->nickname)),
                          // TRANS: SubMirror plugin menu item on user settings page.
                          _m('MENU', 'Tags'),
                          // TRANS: SubMirror plugin tooltip for user settings menu item.
                          _m('Configure tag subscriptions'),
                          $action_name == 'tagsubs' && $action->arg('nickname') == $action->user->nickname);

        return true;
    }

    /**
     * Add a count of mirrored feeds into a user's profile sidebar stats.
     *
     * @param Profile $profile
     * @param array $stats
     * @return boolean hook return value
     */
    function onProfileStats($profile, &$stats)
    {
        $cur = common_current_user();
        if (!empty($cur) && $cur->id == $profile->id) {
            $tagsub = new TagSub();
            $tagsub->profile_id = $profile->id;
            $entry = array(
                'id' => 'tagsubs',
                'label' => _m('Tag subscriptions'),
                'link' => common_local_url('tagsubs', array('nickname' => $profile->nickname)),
                'value' => $tagsub->count(),
            );

            $insertAt = count($stats);
            foreach ($stats as $i => $row) {
                if ($row['id'] == 'groups') {
                    // Slip us in after them.
                    $insertAt = $i + 1;
                    break;
                }
            }
            array_splice($stats, $insertAt, 0, array($entry));
        }
        return true;
    }
}