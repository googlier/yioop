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
 * @author Charles Bocage (charles.bocage@sjsu.edu)
 * @license http://www.gnu.org/licenses/ GPL3
 * @link http://www.seekquarry.com/
 * @copyright 2009 - 2017
 * @filesource
 */
namespace seekquarry\yioop\library;

use seekquarry\yioop\configs as C;
/**
 * Class used by html processors to detect if a page matches a particular
 * signature such as that of a content management system, and
 * also to provide scraping mechanisms for the content of such a page
 *
 * @author Charles Bocage (charles.bocage@sjsu.edu)
 */
class ScraperManager
{
    /**
     * Method used to check a page against a supplied list of scrapers
     * for a matching signature. If a match is found that scraper is returned.
     *
     * @param string $page the html page to check
     * @param array $scrapers an array of scrapers to check against
     * @return array an associative array of scraper properties if a matching
     *      scraper signature found; otherwise, the empty array
     */
    public static function getScraper($page, $scrapers)
    {
        $out_scraper = [];
        foreach ($scrapers as $scraper) {
            if (empty($scraper)) {
                continue;
            }
            $signature = html_entity_decode(
                $scraper['SIGNATURE'], ENT_QUOTES);
            if (self::checkSignature($page, $signature)) {
                $out_scraper['SIGNATURE'] = $signature;
                $out_scraper['ID'] = $scraper['ID'];
                $out_scraper['SCRAPE_RULES'] = html_entity_decode(
                    $scraper['SCRAPE_RULES'], ENT_QUOTES);
                $out_scraper['NAME'] = $scraper['NAME'];
                break;
            }
        }
        return $out_scraper;
    }
    /**
     * Applies scrape rules to a given page. A scrape rule consists of
     * a sequence of xpaths delimited by ###. The first path is used
     * extract content from the page, the remaining xpaths are used
     * to delete content from the result.
     *
     * @param string $page the html page to operate on
     * @param string $scrape_rules_string a string of xpaths with ###
     *  used as a delimeter
     * @return string the result of extracting first xpath content and
     *  deleting from it according to the remaining xpath rules
     */
    public static function applyScraperRules($page, $scrape_rules_string)
    {
        $scrape_rules = preg_split('/###/u',
            $scrape_rules_string, 0, PREG_SPLIT_NO_EMPTY);
        if (count($scrape_rules) > 0) {
            $temp_page = self::getContentByXquery($page,
                $scrape_rules[0]);
            unset($scrape_rules[0]);
            if (!empty($temp_page)) {
                foreach ($scrape_rules as $tag_to_remove) {
                    $new_temp_page =
                        self::removeContentByXquery($temp_page, $tag_to_remove);
                    if (!empty($new_temp_page)) {
                        $temp_page = $new_temp_page;
                    }
                }
            }
        }
        return empty($temp_page) ? $page : $temp_page;
    }
    /**
     * If $signature begins with '/', checks to see if applying
     * the xpath in $signature to $page results
     * in a non-empty dom node list. Otherwise, does a match of the
     * regex (without matching start and end delimiters (say, /)
     * against $page and returns whether found
     *
     * @param string $page a web document to check
     * @param string $signature an xpath to check against
     * @return boolean true if the given xpath return a non empty dom node list
     */
    public static function checkSignature($page, $signature)
    {
        if ($signature[0] == '/') {
            $dom = new \DOMDocument();
            $results = false;
            restore_error_handler();
            if (@$dom->loadHTML($page)) {
                if ($xpath = new \DOMXpath($dom)) {
                    $results = $xpath->query($signature);
                }
            }
            set_error_handler(C\NS_LIB . "yioop_error_handler");
            return !empty($results->length) && $results->length > 0;
        } else {
            return (mb_ereg($signature, $page) !== false);
        }
    }
    /**
     * Get the contents of a document via an xpath
     * @param string $page a document to apply the xpath query against
     * @param string $query the xpath query to run
     *
     * @return string the content found as a string, otherwise an empty string
     */
    public static function getContentByXquery($page, $query)
    {
        $result = "";
        $dom = new \DOMDocument();
        restore_error_handler();
        if (@$dom->loadHTML($page)) {
            $xpath = new \DOMXPath($dom);
            $xpath_result = $xpath->query($query);
            if (!empty($xpath_result) && $xpath_result->length > 0) {
                $result = $dom->saveHTML($xpath_result->item(0));
            }
        }
        set_error_handler(C\NS_LIB . "yioop_error_handler");
        return $result;
    }
    /**
     * Removes from the contents of a document the results of
     * an xpath query
     * @param string $page a document to apply the xpath query against
     * @param string $query the xpath query to run
     *
     * @return string the content less the xpath results as an HTML document
     */
    public static function removeContentByXquery($page, $query)
    {
        $result = $page;
        $dom = new \DOMDocument();
        restore_error_handler();
        if (@$dom->loadHTML($page)) {
            $xpath = new \DOMXPath($dom);
            $xpath_result = $xpath->query($query);
            if ($xpath_result->length > 0) {
                $len = $xpath_result->length;
                for ($i = 0; $i < $len; $i++) {
                    $node = $xpath_result->item($i);
                    $parent = $node->parentNode;
                    if ($parent) {
                        $parent->removeChild($node);
                    }
                }
                $result = $dom->saveHTML();
            }
        }
        set_error_handler(C\NS_LIB . "yioop_error_handler");
        return $result;
    }
}