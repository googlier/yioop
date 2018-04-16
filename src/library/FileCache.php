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
namespace seekquarry\yioop\library;

use seekquarry\yioop\configs as C;
use seekquarry\yioop\models\datasources as D;

/** For Yioop global defines */
require_once __DIR__."/../configs/Config.php";
/**
 * Library of functions used to implement a simple file cache
 *
 * @author Chris Pollett
 */
class FileCache
{
    /**
     * File used to serve last cache request
     * @var string
     */
    public $cache_file;
    /**
     * Folder name to use for this FileCache
     * @var string
     */
    public $dir_name;
    /**
     * Total number of bins to cycle between
     */
    const NUMBER_OF_BINS = 24;
    /**
     * Maximum number of files in a bin
     */
    const MAX_FILES_IN_A_BIN = 10000;
    /**
     * Creates the directory for the file cache, sets how frequently
     * all items in the cache expire
     *
     * @param string $dir_name folder name of where to put the file cache
     */
    public function __construct($dir_name)
    {
        $this->dir_name = $dir_name;

        if (!is_dir($this->dir_name)) {
            mkdir($this->dir_name);
            $db_class = C\NS_DATASOURCES . ucfirst(C\DBMS)."Manager";
            $db = new $db_class();
            $db->setWorldPermissionsRecursive($this->dir_name, true);
        }
    }
    /**
     * Retrieve data associated with a key that has been put in the cache
     *
     * @param string $key the key to look up
     * @return mixed the data associated with the key if it exists, false
     *     otherwise
     */
    public function get($key)
    {
        $checksum_block = $this->checksum($key);
        $this->cache_file = $this->dir_name . "/$checksum_block/" .
            webencode($key);
        if (file_exists($this->cache_file)) {
            return unserialize(file_get_contents($this->cache_file));
        }
        return false;
    }
    /**
     * Stores in the cache a key-value pair
     *
     * Only when a key is set is there a check for whether to invalidate
     * a cache bin. It is deleted as invalid if the following two conditions
     * both hold:
     * The last time it was expired is more than SECONDS_IN_A_BIN seconds ago,
     * and the number of cache items is more than self::MAX_FILES_IN_A_BIN.
     *
     * @param string $key to associate with value
     * @param mixed $value to store
     */
    public function set($key, $value)
    {
        $checksum_block = $this->checksum($key);
        $checksum_dir = $this->dir_name."/$checksum_block";
        if (file_exists("$checksum_dir/last_expired.txt")) {
            $data =
                unserialize(
                    file_get_contents("$checksum_dir/last_expired.txt"));
        }
        if (!isset($data['last_expired'])) {
            $data = ['last_expired' => time(), 'count' => 0];
        }
        if ($data['count'] > self::MAX_FILES_IN_A_BIN) {
            $db_class = C\NS_DATASOURCES . ucfirst(C\DBMS) . "Manager";
            $db = new $db_class();
            $db->unlinkRecursive($checksum_dir);
        }
        if (!file_exists($checksum_dir)) {
            mkdir($checksum_dir);
            $data['last_expired'] = time(); /* currently count is used rather
                than time, but we store time anyway.
                */
        }
        $cache_file = "$checksum_dir/".webencode($key);
        if (!file_exists($cache_file)) {
            $data['count']++;
        }
        file_put_contents("$checksum_dir/last_expired.txt",
            serialize($data));
        file_put_contents($cache_file, serialize($value));
    }
    /**
     * Makes a 0 - self::NUMBER_OF_BINS value out of the provided key
     *
     * @param string $key to convert to a random value between
     *     0 - self::NUMBER_OF_BINS
     * @return int value between 0 and self::NUMBER_OF_BINS
     */
    public function checksum($key)
    {
        $len = strlen($key);
        $value = 0;
        for ($i = 0; $i < $len; $i++) {
            $value += ord($key[$i]);
        }
        return ($value % self::NUMBER_OF_BINS);
    }
}
