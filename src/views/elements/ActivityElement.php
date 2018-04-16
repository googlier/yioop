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
 * @author Chris Pollett chris@pollett.orgs
 * @license http://www.gnu.org/licenses/ GPL3
 * @link http://www.seekquarry.com/
 * @copyright 2009 - 2017
 * @filesource
 */
namespace seekquarry\yioop\views\elements;

use seekquarry\yioop as B;
use seekquarry\yioop\configs as C;

/**
 * This element is used to display the list of available activities
 * in the AdminView
 *
 * @author Chris Pollett
 */
class ActivityElement extends Element
{
    /**
     * Displays a list of admin activities
     *
     * @param array $data  available activities and CSRF token
     */
    public function render($data)
    {
    ?>
        <?php
        if (isset($data['ACTIVITIES'])) {
            if (C\MOBILE) {
                ?>
                <div class="frame activity-menu">
                <h2><?= tl('activity_element_activities') ?></h2>
                <?php
                $count = count($data['ACTIVITIES']);
                $activities = $data['ACTIVITIES'];
                $out_activities = [];
                $base_url = B\controllerUrl('admin', true) . C\CSRF_TOKEN."=".
                    $data[C\CSRF_TOKEN]. "&amp;a=";
                $current = "";
                foreach ($activities as $activity) {
                    $out_activities[$base_url .
                        $activity['METHOD_NAME'] ]= $activity['ACTIVITY_NAME'];
                    if (strcmp($activity['ACTIVITY_NAME'],
                        $data['CURRENT_ACTIVITY']) == 0) {
                        $current = $base_url .$activity['METHOD_NAME'];
                    }
                }

                $this->view->helper("options")->render(
                    "activity", "a", $out_activities,  $current);
                ?>
                <script type="text/javascript">
                activity_select = document.getElementById('activity');
                function activityChange() {
                    document.location = activity_select.value;
                }
                activity_select.onchange = activityChange;
                </script>
                </div>
                <?php
            } else {
                $logged_in = isset($data["ADMIN"]) && $data["ADMIN"];
                $just_admin = empty($data["CONTROLLER"]);
                $is_admin = ($just_admin || $data["CONTROLLER"] == "admin");
                $arrows = empty($data['HIDE_ACTIVITIES']) ? "expand.png" :
                    "collapse.png";
                $other_controller = (!$just_admin && $is_admin) ?
                    "group" : "admin";
                if ($just_admin) {
                    $base_query = B\controllerUrl('admin', true) .
                         "a=".$data['ACTIVITY_METHOD'];
                    $other_base_query = $base_query .
                        "&amp;TOGGLE_ACTIVITIES=true";
                    if (!empty($data['FORM_TYPE']) &&
                        in_array($data['FORM_TYPE'], ['statistics'])) {
                        $other_base_query .=
                            "&amp;arg=" . $data['FORM_TYPE'];
                        if (!empty($data['CURRENT_GROUP']['id'])) {
                            $other_base_query .=
                                "&amp;group_id=" . $data['CURRENT_GROUP']['id'];
                        }
                        if (!empty($data['FILTER'])) {
                            $other_base_query .=
                                "&amp;filter=" . $data['FILTER'];
                        }
                    }
                } else {
                    switch ($data['ACTIVITY_METHOD']) {
                        case 'wiki':
                            $base_query = htmlentities(B\wikiUrl("", true,
                                $data['CONTROLLER'],
                                $data["GROUP"]["GROUP_ID"]));
                            $other_base_query = B\wikiUrl($data['PAGE_NAME'],
                                true, $other_controller,
                                $data["GROUP"]["GROUP_ID"]) .
                                "arg=".$data['MODE'];
                            break;
                        case 'groupFeeds':
                            $base_query = B\feedsUrl("", "", true,
                                $data['CONTROLLER']);
                            $paging_query = $data['PAGING_QUERY'];
                            $other_base_query = $data['OTHER_PAGING_QUERY'];
                            break;
                    }
                }
                $csrf_token = "";
                if ($logged_in) {
                    $csrf_token = C\CSRF_TOKEN."=".$data[C\CSRF_TOKEN];
                    $base_query .= $csrf_token;
                }
                if (isset($data['OTHER_BACK_URL'])) {
                    $other_base_query .= $data['OTHER_BACK_URL'];
                }
                if ($logged_in) {
                    $other_base_query .= "&amp;". $csrf_token;
                }
                if (!empty($data['SUB_PATH'])) {
                    $other_base_query .= "&amp;sf=". $data['SUB_PATH'];
                }
                if (!empty($data['RESOURCE_NAME'])) {
                    $other_base_query .= "&amp;n=". $data['RESOURCE_NAME'];
                }
                if (isset($data['LIMIT'])) {
                    $other_base_query .= "&amp;limit=".$data['LIMIT'];
                }
                if (isset($data['RESULTS_PER_PAGE'])) {
                    $other_base_query .=
                        "&amp;num=".$data['RESULTS_PER_PAGE'];
                }
                if (isset($data['MODE']) && $data['MODE'] == 'grouped') {
                    $other_base_query .= "&amp;v=grouped";
                }
                ?>
                <div class="component-container">
                <?php
                if (!$data['HIDE_ACTIVITIES']) {
                    foreach ($data['COMPONENT_ACTIVITIES'] as
                        $component_name => $activities) {
                        $count = count($activities);
                        ?>
                        <div class="frame activity-menu">
                        <h2><?=$component_name ?></h2>
                        <ul>
                        <?php
                        for ($i = 0 ; $i < $count; $i++) {
                            if ($i < $count - 1) {
                                $class="class='bottom-border'";
                            } else {
                                $class="";
                            }
                            e("<li $class><a href='"
                                . B\controllerUrl('admin', true)
                                . C\CSRF_TOKEN . "=" . $data[C\CSRF_TOKEN]
                                . "&amp;a="
                                . $activities[$i]['METHOD_NAME']."'>"
                                . $activities[$i]['ACTIVITY_NAME']."</a></li>");
                        }
                        ?>
                        </ul>
                        </div>
                        <?php
                    }
                }
                ?>
                </div>
                <?php
                if (($is_admin || $logged_in) && !C\MOBILE &&
                    (!isset($data['page_type']) ||
                    $data['page_type'] != 'presentation')) { ?>
                    <div class="float-same admin-collapse sidebar"><a
                    id='arrows-link' href="<?= $other_base_query ?>" onclick="
                    arrows=elt('arrows-link');
                    arrows_url = arrows.href;
                    caret = (elt('wiki-page').selectionStart) ?
                        elt('wiki-page').selectionStart : 0;
                    edit_scroll = elt('scroll-top').value =
                        (elt('wiki-page').scrollTop)?
                        elt('wiki-page').scrollTop : 0;
                    arrows_url += '&amp;caret=' + caret + '&amp;scroll_top=' +
                        edit_scroll;
                    arrows.href = arrows_url;" ><?=
                    "<img src='" . C\BASE_URL .
                        "resources/" . $arrows . "' alt=''/>" ?></a></div>
                <?php
                }
            }
        }

    }
}
