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
namespace seekquarry\yioop\controllers;

use seekquarry\yioop\configs as C;
use seekquarry\yioop\library as L;
use seekquarry\yioop\library\CrawlConstants;
use seekquarry\yioop\library\FetchUrl;
use seekquarry\yioop\library\UrlParser;
use seekquarry\yioop\library\MediaConstants;
use seekquarry\yioop\library\processors\ImageProcessor;

/**
 * Used to serve resources, css, or scripts such as images from APP_DIR
 *
 * @author Chris Pollett
 */
class ResourceController extends Controller implements CrawlConstants
{
    /**
     * These are the activities supported by this controller
     * @var array
     */
    public $activities = ["get", "syncList", "syncNotify", "suggest"];
    /**
     * Checks that the request seems to be coming from a legitimate fetcher
     * or mirror server then determines which activity  is being requested
     * and calls the method for that activity.
     *
     */
    public function processRequest()
    {
        if ((isset($_REQUEST['a']) && in_array(
            $_REQUEST['a'], ["get", "suggest"]))
            || $this->checkRequest()) {
            $activity = $_REQUEST['a'];
            if (in_array($activity, $this->activities)) {
                $this->call($activity);
                return;
            }
        }
        $this->requestError();
    }
    /**
     * Gets the resource $_REQUEST['n'] from APP_DIR/$_REQUEST['f'] or
     * CRAWL_DIR/$_REQUEST['f']  after cleaning
     */
    public function get()
    {
        if (!isset($_REQUEST['n']) || !isset($_REQUEST['f'])) {
            return;
        }
        $name = $this->clean($_REQUEST['n'], "file_name");
        if (in_array($_REQUEST['f'], ["css", "scripts", "resources"])) {
            /* notice in this case we didn't check if request come from a
               legitimate source but we do try to restrict it to being
               a file (not a folder) in the above array. If the request
               is for a file in resources, then if it is for a private
               group, we will check in getBaseFolder if the request is legit
            */
            $base_dir = $this->getBaseFolder();
            if (!$base_dir) {
                $this->requestError();
            }
            $type = UrlParser::getDocumentType($name);
            if (!empty($_REQUEST['t']) && $_REQUEST['t'] == 'feed') {
                $type = "";
            }
            $name = UrlParser::getDocumentFilename($name);
            $name = ($type != "") ? "$name.$type":$name;
            if (!empty($_REQUEST['t'])) {
                $name .= ".jpg";
            }
            $sub_path = "";
            if (!empty($_REQUEST['sf'])) {
                $sub_path = $this->clean($_REQUEST['sf'], "string");
                $sub_path = str_replace(".", "", $sub_path) . "/";
                if ($sub_path == "/") {
                    $sub_path = "";
                }
            }
            $name = $sub_path . $name;
        } else if (in_array($_REQUEST['f'], ["cache"])) {
            /*  perform check since these request should come from a known
                machine
            */
            if (!$this->checkRequest()) {
                $this->requestError();
            }
            $folder = $_REQUEST['f'];
            $base_dir = C\CRAWL_DIR."/$folder";
        } else {
            return;
        }
        if (isset($_REQUEST['o']) && isset($_REQUEST['l'])) {
            $offset = $this->clean($_REQUEST['o'], "int");
            $limit = $this->clean($_REQUEST['l'], "int");
        }
        $path = "$base_dir/$name";
        if (isset($_REQUEST['t']) && $_REQUEST['t'] == 'feed' &&
            !file_exists($path) && file_exists("$path.txt")) {
            $image_url = file_get_contents("$path.txt");
            if (!empty($image_url)) {
                $image_page = FetchUrl::getPage($image_url);
                restore_error_handler();
                $image = @imagecreatefromstring($image_page);
                set_error_handler(C\NS_LIB . "yioop_error_handler");
                $thumb = ImageProcessor::createThumb($image);
                if (!empty($thumb)) {
                    file_put_contents($path, $thumb);
                }
            }
        }
        if (file_exists($path)) {
            $path = realpath($path);
            $mime_type = L\mimeType($path);
            $size = filesize($path);
            $start = 0;
            $end = $size - 1;
            header("Content-type: $mime_type");
            header('Content-Disposition: filename="' .$name. '"');
            header("Accept-Ranges: bytes");
            if (isset($_SERVER['HTTP_RANGE'])) {
                $this->serveRangeRequest($path, $size, $start, $end);
                return;
            }
            header("Content-Length: ".$size);
            header("Content-Range: bytes $start-$end/$size");
            if (isset($offset) && isset($limit)) {
                echo file_get_contents($path, false, null, $offset, $limit);
            } else {
                readfile($path);
            }
        } else {
            $this->requestError();
        }
    }
    /**
     * Handles requests that result in errors to this controller
     */
    public function requestError()
    {
        header("Location:".C\BASE_URL."/error.php");
        return;
    }
    /**
     * Computes based on the request the folder that should be used to
     * find a file during a resource get request. It also checks if user
     * has access to the requested folder.
     *
     * @return mixed either a string with the folder name in it or false if
     *      the user does not have access or that folder does not exist.
     */
    public function getBaseFolder()
    {
        $folder = $this->clean($_REQUEST['f'], 'string');
        $base_dir = C\APP_DIR . "/$folder";
        $add_to_path = false;
        $is_group_item = false;
        $page_id = "";
        if (isset($_REQUEST['s'])&& !isset($_REQUEST['g']) &&
            $folder == "resources") {
            // handle sub-folders of resource (must be numeric)
            $subfolder = $this->clean($_REQUEST['s'], "hash");
            $prefix_folder = substr($subfolder, 0, 3);
            $add_to_path = true;
        } else if (isset($_REQUEST['g'])) {
            $user_id = isset($_SESSION['USER_ID']) ? $_SESSION['USER_ID'] :
                C\PUBLIC_USER_ID;
            if (isset($_REQUEST['p'])) {
                $page_id = $this->clean($_REQUEST['p'], 'string');
            }
            $group_id = $this->clean($_REQUEST['g'], "int");
            $group_model = $this->model('group');
            $token_okay = true;
            $pre_token_okay = $this->checkCSRFToken(C\CSRF_TOKEN, $user_id);
            if ($group_id == C\PUBLIC_GROUP_ID) {
                $user_id = C\PUBLIC_USER_ID;
            } else {
                $token_okay = $pre_token_okay;
                if (empty($_COOKIE) && stristr($_SERVER['HTTP_USER_AGENT'],
                    "Mobile") !== false && stristr($_SERVER['HTTP_USER_AGENT'],
                    "Safari") !== false) {
                    header('HTTP/1.0 403 Forbidden');
                    //fixes mobile safari no send cookie bug
                    exit();
                }
            }
            $group = $group_model->getGroupById($group_id, $user_id);
            if (!$group || !$token_okay) {
                return false;
            }
            $prefix_word = (isset($_REQUEST['t'])) ? 't' : '';
            $base_subfolder = L\crawlHash(
                'group' . $group_id. $page_id . C\AUTH_KEY);
            $prefix_folder = substr($base_subfolder, 0, 3);
            $subfolder = $prefix_word . $base_subfolder;
            $add_to_path = true;
            $is_group_item = true;
        }
        if ($add_to_path) {
            if ($is_group_item) {
                $redirect_dir = "$base_dir/$prefix_folder/$base_subfolder";
            }
            if ($is_group_item &&
                file_exists($redirect_dir . "/redirect.txt")) {
                $tmp_path = file_get_contents($redirect_dir . "/redirect.txt");
                if (is_dir($tmp_path)) {
                    if ($subfolder == $base_subfolder) {
                        $base_dir = $tmp_path;
                    } else {
                        $subfolder = L\crawlHash($tmp_path);
                        $prefix_folder = substr($subfolder, 0, 3);
                        $subfolder = $prefix_word . $subfolder;
                        $base_dir .= "/$prefix_folder/$subfolder";
                    }
                }
            } else {
                $base_dir .= "/$prefix_folder/$subfolder";
            }
        }
        return $base_dir;
    }
    /**
     * Code to handle HTTP range requests of resources. This allows
     * HTTP pseudo-streaming of video. This code was inspired by:
     * http://www.tuxxin.com/php-mp4-streaming/
     *
     * @param string $file Name of file to serve range request for
     * @param int $size size of the file in bytes
     * @param int $start starting byte location want to serve
     * @param int $end ending byte location want ot serve
     */
    public function serveRangeRequest($file, $size, $start, $end)
    {
        $current_start = $start;
        $current_end = $end;
        list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
        if (strpos($range, ',') !== false) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header("Content-Range: bytes $start-$end/$size");
            return;
        }
        if ($range == '-') {
            $current_start = $size - 1;
        } else {
            $range = explode('-', $range);
            $current_start = trim($range[0]);
            $current_end = (isset($range[1]) && is_numeric(trim($range[1])))
                ? trim($range[1]) : $size;
            if ($current_start === "") {
                $current_start = max(0, $size - $range[1] - 1);
            }
        }
        $current_end = ($current_end > $end) ? $end : $current_end;
        if ($current_start > $current_end || $current_start > $size - 1 ||
            $current_end >= $size) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header("Content-Range: bytes $start-$end/$size");
            return;
        }
        $start = $current_start;
        $end = $current_end;
        $length = $end - $start + 1;
        $fp = @fopen($file, 'rb');
        fseek($fp, $start);
        header('HTTP/1.1 206 Partial Content');
        header("Content-Range: bytes $start-$end/$size");
        header("Content-Length: ".$length);
        $buffer = 8192;
        $position = 0;
        while(!feof($fp) && $position <= $end && connection_status() == 0) {
            $position = ftell($fp);
            if ($position + $buffer > $end) {
                $buffer = $end - $position + 1;
            }
            echo fread($fp, $buffer);
            flush();
        }
        fclose($fp);
    }
    /**
     * Used to get a keyword suggest trie. This sends additional
     * header so will be decompressed on the fly
     */
    public function suggest()
    {
        if (!isset($_REQUEST["locale"])){return;}
        $locale = $_REQUEST["locale"];
        $count = preg_match("/^[a-zA-z]{2}(-[a-zA-z]{2})?$/", $locale);
        if ($count != 1) {return;}
        $locale = str_replace("-", "_", $locale);
        $path = C\LOCALE_DIR."/$locale/resources/suggest_trie.txt.gz";
        if (file_exists($path)) {
            header("Content-Type: application/json");
            header("Content-Encoding: gzip");
            header("Content-Length: ".filesize($path));
            readfile($path);
        }
    }
    /**
     * Used to notify a machine that another machine acting as a mirror
     * is still alive. Data is stored in a txt file self::mirror_table_name
     */
    public function syncNotify()
    {
        if (isset($_REQUEST['last_sync']) && $_REQUEST['last_sync'] > 0 ) {
            $mirror_table_name = C\CRAWL_DIR."/".self::mirror_table_name;
            $mirror_table = [];
            $time = time();
            if (file_exists($mirror_table_name) ) {
                $mirror_table = unserialize(
                    file_get_contents($mirror_table_name));
                if (isset($mirror_table['time']) &&
                    $mirror_table['time'] - $time > C\MIRROR_SYNC_FREQUENCY) {
                    $mirror_table = [];
                    // truncate table periodically to get rid of stale entries
                }
            }
            if (isset($_REQUEST['robot_instance'])) {
                $mirror_table['time'] = $time;
                $mirror_table['machines'][
                    $this->clean($_REQUEST['robot_instance'], "string")] =
                    [$_SERVER['REMOTE_ADDR'], $_REQUEST['machine_uri'],
                    $time,
                    $this->clean($_REQUEST['last_sync'], "int")];
                file_put_contents($mirror_table_name, serialize($mirror_table));
            }
        }
    }
    /**
     * Returns a list of syncable files and the modification times
     */
    public function syncList()
    {
        $this->syncNotify();
        $info = [];
        if (isset($_REQUEST["last_sync"])) {
            $last_sync = $this->clean($_REQUEST["last_sync"], "int");
        } else {
            $last_sync = 0;
        }
        // substrings to exclude from our list
        $excludes = [".DS", "__MACOSX", "queries", "QueueBundle", "tmp",
            "thumb"];
        $sync_files = $this->model("crawl")->getDeltaFileInfo(
            C\CRAWL_DIR."/cache", $last_sync, $excludes);
        if (count($sync_files) > 0 ) {
            $info[self::STATUS] = self::CONTINUE_STATE;
            $info[self::DATA] = $sync_files;
        } else {
            $info[self::STATUS] = self::NO_DATA_STATE;
        }
        echo base64_encode(gzcompress(serialize($info)));
    }
}
