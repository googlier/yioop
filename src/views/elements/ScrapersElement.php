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
namespace seekquarry\yioop\views\elements;

use seekquarry\yioop as B;
use seekquarry\yioop\configs as C;

/**
 * Contains the forms for managing Web Page Scrapers.
 *
 * @author Charles Bocage (repurposed from CMS Detector element by
 *  Chris Pollett)
 */
class ScrapersElement extends Element
{
    /**
     * Renders Web Scrapers form and the table used for drawing the
     * current scrapers
     *
     * @param array $data contains the available scrapers
     *      as well as potentially edit info for the current scraper
     */
    public function render($data)
    {
        $admin_url = htmlentities(B\controllerUrl('admin', true));
        $token_string = C\CSRF_TOKEN."=".$data[C\CSRF_TOKEN];
        $pre_base_url = $admin_url . $token_string;
        $base_url = $pre_base_url . "&amp;a=scrapers";
        ?>
        <div class="current-activity">
        <?php if ($data["FORM_TYPE"] == "edit") {
            ?>
            <div class='float-opposite'><a href='<?= $base_url ?>'><?=
                tl('scrapers_element_add_scraper_form') ?></a></div>
            <h2><?= tl('scrapers_element_edit_scrapers')?></h2>
            <?php
        } else {
            ?>
            <h2><?= tl('scrapers_element_add_scraper')?>
            <?= $this->view->helper("helpbutton")->render(
                "Scrapers", $data[C\CSRF_TOKEN]) ?>
            </h2>
            <?php
        }
        ?>
        <form id="addScraperForm" method="post">
        <input type="hidden" name="c" value="admin" />
        <input type="hidden" name="<?= C\CSRF_TOKEN ?>" value="<?=
            $data[C\CSRF_TOKEN] ?>" />
        <input type="hidden" name="a" value="scrapers" />
        <input type="hidden" name="arg" value="<?=
            $data['FORM_TYPE']?>" />
        <?php
        if ($data['FORM_TYPE'] == "edit") {
            ?>
            <input type="hidden" name="id" value="<?= $data['id']?>" />
            <?php
        }
        ?>
        <table class="name-table">
        <tr><td><label for="scraper-name"><b><?=
            tl('scrapers_element_scraper_name')?></b></label></td><td>
            <input type="text" id="scraper-name" name="name"
                value="<?=$data['CURRENT_SCRAPER']['name'] ?>"
                maxlength="<?= C\LONG_NAME_LEN ?>"
                class="wide-field" <?php
                if ($data["FORM_TYPE"] == "edit") {
                    e("disabled='disabled'");
                } ?>/></td></tr>
        <tr><td><label for="scrapers-signature"><b><?=
            tl('scrapers_element_signature')?></b></label></td><td>
            <input type="text" id="scrapers-signature" name="signature"
                value="<?=$data['CURRENT_SCRAPER']['signature'] ?>"
                maxlength="<?=C\MAX_URL_LEN ?>"
                class="wide-field" /></td></tr>
        <tr><td><label for="scrapers-scrape-rules"><b><?=
            tl('scrapers_element_scrape_rules')?></b></label></td><td>
            <input type="text" id="scrapers-scrape-rules"
                name="scrape_rules"
                value=
                "<?=$data['CURRENT_SCRAPER']['scrape_rules']?>"
                maxlength="<?=C\MAX_URL_LEN ?>"
                class="wide-field" /></td></tr>
        <tr><td></td><td class="center"><button class="button-box"
            type="submit"><?=tl('scrapers_element_save')
            ?></button></td></tr>
        </table>
        </form>
        <?php
        $data['FORM_TYPE'] = "";
        $data['TABLE_TITLE'] = tl('scrapers_element_scrapers');
        $data['NO_FLOAT_TABLE'] = true;
        $data['ACTIVITY'] = 'scrapers';
        $data['VIEW'] = $this->view;
        $data['NO_SEARCH'] = true;
        $this->view->helper("pagingtable")->render($data);
        $paging = "&amp;start_row=".$data['START_ROW'].
            "&amp;end_row=".$data['END_ROW'].
            "&amp;num_show=".$data['NUM_SHOW'];
        ?>
        <table class="scrapers-table">
        <tr><th><?= tl('scrapers_element_name_heading') ?></th>
            <th><?= tl('scrapers_element_signature_heading') ?></th>
            <th><?=
                tl('scrapers_element_scrape_rules_heading') ?></th>
            <th colspan="2"><?= tl('scrapers_element_actions') ?></th>
        <?php
        foreach ($data['SCRAPERS'] as $scraper) {
            $encode_source = urlencode(
                urlencode($scraper['NAME']));
        ?>
        <tr><td><b><?= $scraper['NAME'] ?></b></td>
            <td><?= $scraper['SIGNATURE'] ?></td>
            <td>
                <?=preg_replace("/###/", "###<br />",
                    $scraper['SCRAPE_RULES']); ?>
            </td>
            <td><a href="<?=$base_url."&amp;arg=edit&amp;id=".
                $scraper['ID']. $paging?>"><?=
                tl('scrapers_element_edit')
            ?></a></td>
            <td><a onclick='javascript:return confirm("<?=
                tl('scrapers_element_confirm_delete') ?>");' href="<?=
                $base_url . "&amp;arg=delete&amp;id=".
                $scraper['ID'] . $paging  ?>"><?=
                tl('scrapers_element_delete_scraper')
            ?></a></td></tr>
        <?php
        } ?>
        </table>
        </div>
    <?php
    }
}
