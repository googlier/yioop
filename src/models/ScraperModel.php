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
namespace seekquarry\yioop\models;

use seekquarry\yioop\configs as C;
use seekquarry\yioop\library as L;
/**
 * Used to manage data related to the SCRAPER database table.
 * This table is used to store web scrapers, a tool for scraping
 * important content from pages which might have been generated
 * by a content management system.
 *
 * @author Charles Bocage (changed CMS_DETECTORS to SCRAPER and
 *  simplified Chris Pollett)
 */
class ScraperModel extends Model
{
    /**
     * Controls which tables and the names of tables
     * underlie the given model and should be used in a getRows call
     *
     * @param string $args it does not matter.
     * @return string which table to use
     */
    public function fromCallback($args = null)
    {
        return "SCRAPER";
    }
    /**
     * Return the contents of the SCRAPER table
     * @return array associative of rows with ID, NAME, SIGNATURE,
     *     and SCRAPE_RULES
     */
    public function getAllScrapers()
    {
        $db = $this->db;
        $sources = [];
        $sql = "SELECT * FROM SCRAPER";
        $result = $db->execute($sql);
        if (!$result) {
            return false;
        }
        while ($sources[] = $db->fetchArray($result)) {
        }
        return $sources;
    }
    /**
     * Used to add a new scraper to Yioop
     *
     * @param string $name of scraper to add
     * @param string $signature the regex to query the html headers for
     *     a match
     * @param string $scrape_rules the xpath strings used to find
     *     the important content in the html document
     */
    public function add($name, $signature, $scrape_rules)
    {
        $db = $this->db;
        $sql = "INSERT INTO SCRAPER(NAME, SIGNATURE, SCRAPE_RULES)
            VALUES (?,?,?)";
        $db->execute($sql, [$name, $signature, $scrape_rules]);
    }
    /**
     * Deletes the scraper with the provided id
     *
     * @param int $id of scraper to be deleted
     */
    public function delete($id)
    {
        $sql = "DELETE FROM SCRAPER WHERE ID=?";
        $this->db->execute($sql, [$id]);
    }
    /**
     * Returns the scraper with the given id
     * @param int $id of scraper to look up
     * @return array associative array with ID, NAME, SIGNATURE,
     *     and SCRAPE_RULES of a scraper
     */
    public function get($id)
    {
        $db = $this->db;
        $sql = "SELECT * FROM SCRAPER WHERE ID = ?";
        $result = $db->execute($sql, [$id]);
        if (!$result) {
            return false;
        }
        $row = $db->fetchArray($result);
        return $row;
    }
    /**
     * Used to update the fields stored in a SCRAPER row according to
     * an array holding new values
     * @param array $scraper_info updated values for scraper
     */
    public function update($scraper_info)
    {
        $id = $scraper_info['ID'];
        unset($scraper_info['ID']);
        $sql = "UPDATE SCRAPER SET ";
        $comma = "";
        $params = [];
        foreach ($scraper_info as $field => $value) {
            $sql .= "$comma $field=? ";
            $comma = ",";
            $params[] = $value;
        }
        $sql .= " WHERE ID=?";
        $params[] = $id;
        $this->db->execute($sql, $params);
    }
}
