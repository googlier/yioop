<?php
/**
 * SeekQuarry/Yioop --
 * Open Source Pure PHP Search Engine, Crawler, and Indexer
 *
 * Copyright (C) 2009 - 2017  Chris Pollett chris@pollett.org
 *
 * LICENSE:
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * END LICENSE
 *
 * @author Chris Pollett chris@pollett.org
 * @license http://www.gnu.org/licenses/ GPL3
 * @link http://www.seekquarry.com/
 * @copyright 2009 - 2017
 * @filesource
 */
namespace seekquarry\yioop\views;

use seekquarry\yioop as B;
use seekquarry\yioop\configs as C;

/**
 * View used to draw and allow editing of wiki page when not in the admin view
 * (so activities panel on side is not present.) This is also used to draw
 * wiki pages for public groups when not logged.
 *
 * @author Chris Pollett
 */
class WikiView extends View
{
    /** This view is drawn on a web layout
     * @var string
     */
    public $layout = "web";

    /**
     * Draws a minimal container with a WikiElement in it on which a group
     * wiki page can be drawn
     *
     * @param array $data with fields used for drawing the container and page
     */
    public function renderView($data)
    {
        $logo = C\LOGO;
        $logged_in = isset($data["ADMIN"]) && $data["ADMIN"];
        $can_edit = $logged_in && isset($data["CAN_EDIT"]) && $data["CAN_EDIT"];
        $is_admin = ($data["CONTROLLER"] == "admin");
        $use_header = !$is_admin &&
                isset($data['PAGE_HEADER']) && $data['PAGE_HEADER'] &&
                isset($data["HEAD"]['page_type']) &&
                $data["HEAD"]['page_type'] != 'presentation';
        $group_id = (empty($data["GROUP"]["GROUP_ID"])) ? C\PUBLIC_GROUP_ID:
            $data["GROUP"]["GROUP_ID"];
        $feed_base_query = B\feedsUrl("group", $group_id,
            true, "group");
        $token_string =  ($logged_in) ? C\CSRF_TOKEN."=".
            $data[C\CSRF_TOKEN] : "";
        $feed_base_query .= $token_string;
        if (C\MOBILE) {
            $logo = C\M_LOGO;
        }
        if ($use_header) {
            e("<div>".$this->element("wiki")->dynamicSubstitutions($group_id,
                $data, $data['PAGE_HEADER'])."</div>");
        } else if (!$use_header &&
            (!isset($data['page_type']) || $data['page_type'] != 'presentation')
            ) {
            ?>
            <div class="top-bar">
            <?php
            $this->element("signin")->render($data);
            ?>
            </div>
            <div class="wiki current-activity-header">
            <h1 class="group-heading logo"><a href="<?php
                e(C\BASE_URL);
                if ($logged_in) {
                    e("?".C\CSRF_TOKEN."=".$data[C\CSRF_TOKEN]);
                } ?>"><img
                src="<?= C\BASE_URL . $logo ?>" alt="<?= $this->logo_alt_text
                    ?>" /></a>
                <span> - <?php
            $human_page_name = str_replace("_", " ", $data['PAGE_NAME']);
            $wiki_base_url = htmlentities(B\wikiUrl(
                    $data['PAGE_NAME'], true, $data['CONTROLLER'],
                    $data["GROUP"]["GROUP_ID"])) . $token_string;
            $options = [ tl('wiki_view_page_places',
                $human_page_name, $data['GROUP']['GROUP_NAME']) => ""];
            if ($data["MODE"] != 'pages') {
                if ($can_edit) {
                    $modes = [
                        "read" => tl('wiki_view_read_page',
                            $human_page_name,
                            $data['GROUP']['GROUP_NAME']),
                        "edit" => tl('wiki_view_edit_page',$human_page_name),
                        "history" => tl('wiki_view_page_history',
                            $human_page_name),
                    ];
                } else {
                    $modes = [
                        "read" => tl('wiki_view_read_page',
                            $human_page_name,
                            $data['GROUP']['GROUP_NAME']),
                        "source" => tl('wiki_view_page_source',
                            $human_page_name),
                        "history" => tl('wiki_view_page_history',
                            $human_page_name),
                    ];
                }
                if (!empty($data['PAGE_HAS_RELATIONSHIPS'])) {
                    $relationship_mode = [
                        "relationships" => tl('wiki_view_page_relationships',
                            $human_page_name),
                    ];
                    $modes = array_merge($modes, $relationship_mode);
                }
                $modes[htmlentities(B\feedsUrl("thread",
                    $data['DISCUSS_THREAD'], true, $data['CONTROLLER'])) .
                    $token_string] = tl('wiki_view_page_discuss',
                            $human_page_name);
            }
            $modes["pages"] = tl('wiki_view_page_list',
                $data['GROUP']['GROUP_NAME']);
            $modes[htmlentities(
                B\feedsUrl("group", $data["GROUP"]["GROUP_ID"],
                true, $data["CONTROLLER"])) . $token_string] =
                tl('wiki_view_group_feed', $data['GROUP']['GROUP_NAME']);
            $selected_url = "";
            foreach ($modes as $name => $translation) {
                $append = "";
                $page_name = ($name == 'pages') ?
                    'pages' : $data['PAGE_NAME'];
                $arg = '&amp;arg=' . $name ;
                if (in_array($name, ['history','relationships'])) {
                    $page_id = (empty($data['PAGE_ID'])) ? "" :
                        $data['PAGE_ID'];
                    $append .= "&amp;page_id=" . $page_id;
                }
                if (!empty($data['SUB_PATH'])) {
                    $append .= "&amp;sf=" . urlencode($data['SUB_PATH']);
                }
                if (isset($_REQUEST['noredirect'])) {
                    $append .= '&amp;noredirect=true';
                }
                if (isset($data['OTHER_BACK_URL'])) {
                    $append .= $data['OTHER_BACK_URL'];
                }
                if (substr($name, 0, 4) != "http") {
                    $url = htmlentities(B\wikiUrl(
                        $page_name, true, $data['CONTROLLER'],
                        $data["GROUP"]["GROUP_ID"])) . $token_string .
                        $arg . $append;
                } else {
                    $url = $name . $arg . $append;
                }
                if ($data["MODE"] == $name) {
                    $selected_url = $url;
                }
                $options[$url] = $translation;
            }
            $sub_path = $this->element("wiki")->renderPath("page-path", $data,
                $options, $selected_url, "",
                "just_groups_and_pages"); ?></span>
            </h1>
            </div>
            <?php
        }
        $this->element("wiki")->render($data);
        if (!$is_admin &&
            isset($data['PAGE_FOOTER']) &&
            isset($data["HEAD"]['page_type']) &&
            $data["HEAD"]['page_type'] != 'presentation') {
            e("<div class='current-activity-footer'>".
                $this->element("wiki")->dynamicSubstitutions($group_id, $data,
                $data['PAGE_FOOTER'])."</div>");
        }
        if ($logged_in) {
        ?>
        <script type="text/javascript">
        /*
            Used to warn that user is about to be logged out
         */
        function logoutWarn()
        {
            doMessage(
                "<h2 class='red'><?=
                    tl('wiki_view_auto_logout_one_minute') ?></h2>");
        }
        /*
            Javascript to perform autologout
         */
        function autoLogout()
        {
            document.location= '<?=C\BASE_URL ?>?a=signout';
        }
        //schedule logout warnings
        var sec = 1000;
        var minute = 60*sec;
        setTimeout("logoutWarn()", 59 * minute);
        setTimeout("autoLogout()", 60 * minute);
        </script>
        <?php
        }
    }
}
