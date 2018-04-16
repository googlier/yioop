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
namespace seekquarry\yioop\views\helpers;

use seekquarry\yioop\configs as C;

/**
 * This is a helper class is used to handle
 * draw select options form elements
 *
 * @author Chris Pollett
 */
class OptionsHelper extends Helper
{
    /**
     * Draws an HTML select tag according to the supplied parameters
     *
     * @param string $id   the id attribute the select tag should have
     *      If empty string id attribute not echo'd
     * @param string $name   the name this form element should use
     *      If empty string name attribute not echo'd
     * @param array $options   an array of key value pairs for the options
     *    tags of this select element. The key is used as an option tag's
     *    value and the value being used as its contents. If the value
     *    is empty in a key value pair then the key is taken as the
     *    label of a new optgroup.
     * @param string $selected   which option (note singular -- no support
     *     for selecting more than one) should be set as selected
     *     in the select tag
     * @param mixed $onchange_action if true then submit the parent form if
     *     this drop down is changed, if false, normal dropdown, if
     *     a callback function, then when change call callback
     * @param array $additional_attributes associative array of attributes =>
     *      values to add to the open select tag if present
     */
    public function render($id, $name, $options, $selected,
        $onchange_action = false, $additional_attributes = [])
    {
        $stretch = (C\MOBILE) ? 4 : 6;
        $word_wrap_len = $stretch * C\NAME_TRUNCATE_LEN;
        $id_info = ($id != "") ? " id='$id' " : " ";
        $name_info = ($name != "") ? " name='$name' " : " ";
        ?>
        <select <?= $id_info ?> <?= $name_info ?> <?php
            if ($onchange_action === true) {
                e(' onchange="this.form.submit()" ');
            } else if (is_string($onchange_action) ) {
                e(" onchange='$onchange_action' ");
            }
            foreach ($additional_attributes as $attribute => $value) {
                e(" $attribute='$value' ");
            }
        ?> >
        <?php
        $open_optgroup = false;
        foreach ($options as $value => $text) {
            if (empty($text) && !empty($value)) {
                if ($open_optgroup) {
                    ?></optgroup><?php
                }
                ?><optgroup label='<?=$value ?>'><?php
                continue;
            }
            ?>
            <option value="<?= $value ?>" <?php
                if ($value== $selected) { e('selected="selected"'); }
                if (mb_strlen($text) > $word_wrap_len + 3) {
                    $text = mb_substr($text, 0, $word_wrap_len)."...";
                }
             ?>><?= $text ?></option>
        <?php
        }
        if ($open_optgroup) {
            ?></optgroup><?php
        }
        ?>
        </select>
        <?php
    }
    /**
     * Creates a dropdown where selecting an item redirects to a given url.
     *
     * @param string $id  the id attribute the select tag should have
     *      If empty string id attribute not echo'd
     * @param array $options an array of key value pairs for the options
     *    tags of this select element. The key is used as an option tag's
     *    value and the value being used as its contents. If the value
     *    is empty in a key value pair then the key is taken as the
     *    label of a new optgroup.
     * @param string $selected which url should be selected in dropdown
     * @param string $url_prefix keys in $options should correspond to urls.
     *      if such a key doesn't begin with http, it is assumed to be
     *      a url suffix and the value $url_prefix is put before it to get
     *      a complete url before the window location is changed.
     */
    public function renderLinkDropDown($id, $options, $selected, $url_prefix)
    {
        $stretch = (C\MOBILE) ? 4 : 6;
        $word_wrap_len = $stretch * C\NAME_TRUNCATE_LEN;
        $id_info = ($id != "") ? " id='$id' " : " ";

        ?>
        <select class='link-dropdown' <?= $id_info ?>  <?php
            e(' onchange="var next_loc = '. "'$url_prefix'" .
                ';
                var self = this;
                var sel_option = this.options[this.selectedIndex];
                var sel_value = sel_option.value;
                if (sel_option.epub_ref) {
                    sel_option.epub_ref.goto(sel_option.ref).then(
                        function () {
                            updateMediaLocationInfo(sel_option.epub_ref);
                            self.selectedIndex = self.num_doc_sections;
                        }
                    );
                } else if (sel_option.pdf_ref) {
                    sel_option.pdf_ref.getDestination(sel_option.ref).then(
                        function (destination) {
                            sel_option.pdf_ref.getPageIndex(
                                destination[0]).then(
                                function (page_index) {
                                    renderPdfPage(sel_option.pdf_ref,
                                        page_index + 1);
                                }
                            );
                        }
                    );
                } else if (sel_value != -1) {
                    next_url = (sel_value.substring(0,4) == '."'http'".
                    ') ? sel_value : next_loc + sel_value;
                    window.location = next_url;
                }" ');
        ?> >
        <?php
        $open_optgroup = false;
        foreach ($options as $value => $text) {
            if (empty($text) && !empty($value)) {
                if ($open_optgroup) {
                    ?></optgroup><?php
                }
                $open_optgroup = true;
                ?><optgroup label='<?=$value ?>'><?php
                continue;
            }
            ?>
            <option value="<?= $value ?>" <?php
                if ($value== $selected) { e('selected="selected"'); }
                if (strlen($text) > $word_wrap_len + 3) {
                    $text = substr($text, 0, $word_wrap_len)."...";
                }
             ?>><?= $text ?></option>
        <?php
        }
        if ($open_optgroup) {
            ?></optgroup><?php
        }
        ?>
        </select>
        <?php
    }
}