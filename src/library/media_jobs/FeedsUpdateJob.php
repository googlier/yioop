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
 * @author Chris Pollett chris@pollett.org (initial MediaJob class
 *      and subclasses based on work of Pooja Mishra for her master's)
 * @license http://www.gnu.org/licenses/ GPL3
 * @link http://www.seekquarry.com/
 * @copyright 2009 - 2017
 * @filesource
 */
namespace seekquarry\yioop\library\media_jobs;

use seekquarry\yioop\configs as C;
use seekquarry\yioop\library as L;
use seekquarry\yioop\library\CrawlConstants;
use seekquarry\yioop\library\FetchUrl;
use seekquarry\yioop\library\IndexShard;
use seekquarry\yioop\library\PhraseParser;
use seekquarry\yioop\library\UrlParser;

/**
 * A media job to download and index feeds from various search sources (RSS,
 * HTML scraper, etc). Idea is that this job runs once an hour to get the
 * latest news, movies, weather from those sources.
 */
class FeedsUpdateJob extends MediaJob
{
    /**
     * how long in seconds before a feed item expires
     */
    const ITEM_EXPIRES_TIME = C\ONE_WEEK;
    /**
     * Mamimum number of feeds to download in one try
     */
    const MAX_FEEDS_ONE_GO = 100;
    /**
     * Time in current epoch when feeds last updated
     * @var int
     */
    public $update_time;
    /**
     * Datasource object used to run db queries related to fes items
     * (for storing and updating them)
     * @var object
     */
    public $db;
    /**
     * Initializes the last update time to far in the past so, feeds will get
     * immediately updated. Sets up connect to DB to store feeds items, and
     * makes it so the same media job runs both on name server and client
     * Media Updaters
     */
    public function init()
    {
        $this->update_time = 0;
        $this->name_server_does_client_tasks = true;
        $this->name_server_does_client_tasks_only = true;
        $db_class = C\NS_DATASOURCES . ucfirst(C\DBMS). "Manager";
        $this->db = new $db_class();
        $this->db->connect();
        C\nsconddefine("FEEDS_UPDATE_INTERVAL", C\ONE_HOUR);
    }
    /**
     * Only update if its been more than an hour since the last update
     *
     * @return bool whether its been an hour since the last update
     */
    public function checkPrerequisites()
    {
        $time = time();
        $something_updated = false;
        $delta = $time - $this->update_time;
        if ($delta > C\FEEDS_UPDATE_INTERVAL) {
            $this->update_time = $time;
            L\crawlLog("Performing medias feeds update");
            return true;
        }
        L\crawlLog("Time since last update not exceeded, skipping medias
            update");
        return false;
    }
    /**
     * Get the media sources from the local database and use those to run the
     * the same task as in the distributed setting
     */
    public function nondistributedTasks()
    {
        $db = $this->db;
        $sql = "SELECT * FROM MEDIA_SOURCE WHERE (TYPE='rss'
             OR TYPE='html' OR TYPE='json' OR TYPE='regex')";
        $result = $db->execute($sql);
        while ($feed = $db->fetchArray($result)) {
            $this->parseFeedAuxInfo($feed);
            $feeds[] = $feed;
        }
        $this->tasks = $feeds;
        $this->doTasks($feeds);
    }
    /**
     * For each feed source downloads the feeds, checks which items are
     * not in the database, adds them. Then calls the method to rebuild the
     * inverted index shard for feeds
     *
     * @param array $tasks array of feed info (url to download, paths to
     *  extract etc)
     */
    public function doTasks($tasks)
    {
        if (!is_array($tasks)) {
            L\crawlLog(
                "----This media updater is NOT responsible for any feeds!");
            return;
        }
        $feeds = $tasks;
        L\crawlLog("----This media updater is responsible for the feeds:");
        $i = 1;
        foreach ($feeds as $feed) {
            L\crawlLog("----  $i. ".$feed["NAME"]);
            $i++;
        }
        $num_feeds = count($feeds);
        $feeds_one_go = self::MAX_FEEDS_ONE_GO;
        $limit = 0;
        while ($limit < $num_feeds) {
            $feeds_batch = array_slice($feeds, $limit, $feeds_one_go);
            $this->updateFeedItemsOneGo($feeds_batch, self::ITEM_EXPIRES_TIME);
            $limit += $feeds_one_go;
        }
        $this->rebuildFeedShard(self::ITEM_EXPIRES_TIME);
    }
    /**
     * Handles the request to get the  array of feed sources which hash to
     * a particular value i.e. match with the index of requesting machine's
     * hashed url/name from array of available machines hash
     *
     * @param int $machine_id id of machine making request for feeds
     * @param array $data not used but inherited from the base MediaJob
     *      class as a parameter (so will alwasys be null in this case)
     * @return array of feed urls and paths to extract from them
     */
    public function getTasks($machine_id, $data = null)
    {
        $parent = $this->controller;
        if (!$parent) {
            return;
        }
        $source_model = $parent->model("source");
        $current_machine = $machine_id;
        $machine_hashes = $source_model->getMachineHashUrls();
        $machine_index_match = array_search($current_machine, $machine_hashes);
        if ($machine_index_match === false) {
            return [];
        }
        $num_machines = count($machine_hashes);
        $pre_feeds = $source_model->getMediaSources("rss");
        $pre_feeds = array_merge($pre_feeds,
            $source_model->getMediaSources("html"));
        $pre_feeds = array_merge($pre_feeds,
            $source_model->getMediaSources("json"));
        $pre_feeds = array_merge($pre_feeds,
            $source_model->getMediaSources("regex"));
        if (!$pre_feeds) {
            return false;
        }
        $feeds = [];
        foreach ($pre_feeds as $pre_feed) {
            if (!isset($pre_feed['NAME'])) {
                continue;
            }
            $hash_int = unpack("N", L\crawlHash($pre_feed['NAME']));
            if (!isset($hash_int[1])) {
                continue;
            }
            $hash_index = ($hash_int[1]) % $num_machines;
            if ($machine_index_match != $hash_index) {continue; }
            $this->parseFeedAuxInfo($pre_feed);
            $feeds[] = $pre_feed;
        }
        return $feeds;
    }
    /**
     * Downloads one batch of $feeds_one_go feed items for @see updateFeedItems
     * For each feed source downloads the feeds, checks which items are
     * not in the database, adds them. This method does not update
     * the inverted index shard.
     *
     * @param array $feeds list of feeds to download
     * @param int $age how many seconds old records should be ignored
     */
    public function updateFeedItemsOneGo($feeds, $age = C\ONE_WEEK)
    {
        //initialize xlink image folder

        $base_dir = C\APP_DIR . "/resources";
        $subfolder = L\crawlHash(
            'group' . C\PUBLIC_GROUP_ID . C\AUTH_KEY);
        $prefix_folder = substr($subfolder, 0, 3);
        $subfolder = "t" . $subfolder;
        $date = date('Y-m-d');
        foreach ([$prefix_folder, $subfolder, $date]
            as $add_folder) {
            $base_dir .= "/$add_folder";
            if (!file_exists($base_dir)) {
                mkdir($base_dir);
                chmod($base_dir, 0777);
            }
        }
        $image_url = C\NAME_SERVER . "?c=resource&amp;a=get&amp;f=resources".
            "&amp;g=" . C\PUBLIC_GROUP_ID . "&amp;t=feed&amp;sf=$date";
        // now get feed items
        $feeds = FetchUrl::getPages($feeds, false, 0, null, "SOURCE_URL",
            CrawlConstants::PAGE, true, null, true);
        $sql = "UPDATE MEDIA_SOURCE SET LANGUAGE=? WHERE TIMESTAMP=?";
        $db = $this->db;
        libxml_use_internal_errors(true);
        foreach ($feeds as $feed) {
            if (empty($feed[CrawlConstants::PAGE])) {
                L\crawlLog("...No data in feed skipping.");
                continue;
            }
            L\crawlLog("----Updating {$feed['NAME']}.");
            $is_html = ($feed['TYPE'] == 'html') ? true : false;
            $is_json = ($feed['TYPE'] == 'json') ? true : false;
            $is_regex = ($feed['TYPE'] == 'regex') ? true : false;
            if (!$is_regex) {
                L\crawlLog("Making dom object from feed.");
                $dom = new \DOMDocument();
            }
            if ($is_json) {
                $json_decode = json_decode($feed[CrawlConstants::PAGE], true);
                $page = "<html><body>".
                    $this->convertJsonDecodeToTags($json_decode) .
                    "</body></html>";
                $is_html = true;
            } else if ($is_regex) {
                $page = $feed[CrawlConstants::PAGE];
            } else {
                //strip namespaces
                $page = preg_replace('@<(/?)(\w+\s*)\:@u', '<$1',
                    $feed[CrawlConstants::PAGE]);
            }
            if (isset($feed['IMAGE_XPATH']) && !$is_regex) {
                $feed['IMAGE_XPATH'] = preg_replace('@/(\s*\w+\s*)\:@u', '/',
                    $feed['IMAGE_XPATH']);
            }
            if ($is_html) {
                @$dom->loadHTML($page);
            } else if (!$is_regex) {
                /*
                    We parse using loadHTML as less strict. loadHTML
                    auto-closes link tags immediately after open link
                    so to avoid this we replace link with xlink
                 */
                $page = preg_replace("@<link@", "<slink", $page);
                $page = preg_replace("@</link@", "</slink", $page);
                $page = preg_replace("@pubDate@i", "pubdate", $page);
                $page = preg_replace("@&lt;@", "<", $page);
                $page = preg_replace("@&gt;@", ">", $page);
                $page = preg_replace("@<!\[CDATA\[(.+?)\]\]>@s", '$1', $page);
                // we also need a hack to make UTF-8 work correctly
                @$dom->loadHTML('<?xml encoding="UTF-8">' . $page);
                foreach ($dom->childNodes as $item) {
                    if ($item->nodeType == XML_PI_NODE) {
                        $dom->removeChild($item);
                    }
                }
                $dom->encoding = 'UTF-8';
            }
            L\crawlLog("----...done. Extracting info about whole feed.");
            $lang = "";
            if (!in_array($feed['TYPE'], ['html', 'regex']) &&
                empty($feed["LANGUAGE"])) {
                $languages = $dom->getElementsByTagName('language');
                if ($languages && is_object($languages) &&
                    is_object($languages->item(0))) {
                    $lang = $languages->item(0)->textContent;
                    $db->execute($sql, [$lang, $feed['TIMESTAMP']]);
                }
            } else if (!empty($feed["LANGUAGE"])) {
                $lang = $feed["LANGUAGE"];
            } else {
                $lang = C\DEFAULT_LOCALE;
            }
            L\crawlLog("----...Language is $lang. Getting " .
                "channel, finding nodes.");
            if ($is_html) {
                $sub_dom = $this->getTags($dom, $feed['CHANNEL_PATH']);
                if (!$sub_dom) {
                    L\crawlLog("----... Scraper couldn't parse channel".
                        " path so bailing on this feed.");
                    continue;
                } else {
                    L\crawlLog("----...Channel scraped.");
                }
                $nodes = $this->getTags($sub_dom[0], $feed['ITEM_PATH']);
                $item_elements = ["title" => $feed['TITLE_PATH'],
                    "description" => $feed['DESCRIPTION_PATH'],
                    "link" => $feed['LINK_PATH']];
            } else if ($is_regex) {
                preg_match($feed['CHANNEL_PATH'], $page, $matches);
                $channel = "";
                if (!empty($matches[1])) {
                    $nodes = preg_split($feed['ITEM_PATH'], $matches[1]);
                }
                if (empty($nodes)) {
                    L\crawlLog("----... Scraper couldn't parse channel".
                        " path so bailing on this feed.");
                    continue;
                }
                L\crawlLog("----...Channel scraped.");
                $item_elements = ["title" => $feed['TITLE_PATH'],
                    "description" => $feed['DESCRIPTION_PATH'],
                    "link" => $feed['LINK_PATH']];
            } else {
                $nodes = $dom->getElementsByTagName('item');
                // see above comment on why slink rather than link
                $item_elements = ["title" => "title",
                    "description" => "description", "link" =>"slink",
                    "guid" => "guid", "pubdate" => "pubdate"];
                if ($nodes->length == 0) {
                    // maybe we're dealing with atom rather than rss
                    $nodes = $dom->getElementsByTagName('entry');
                    $item_elements = [
                        "title" => "title",
                        "description" => ["summary", "content"],
                        "link" => "slink", "guid" => "id",
                        "pubdate" => "updated"];
                }
            }
            L\crawlLog("----...done extracting info. Check for new feed ".
                "items in {$feed['NAME']}.");
            $num_added = 0;
            $num_seen = 0;
            foreach ($nodes as $node) {
                $item = [];
                $unique_fields = ['link'];
                foreach ($item_elements as $db_element => $feed_element) {
                    L\crawlTimeoutLog("----still adding feed items to index.");
                    if ($db_element == "link" && substr($feed_element, 0, 4)
                        == "http") {
                        $unique_fields = ['title', 'description'];
                        $element_text = $feed_element;
                        $item[$db_element] = strip_tags($element_text);
                        continue;
                    }
                    if ($is_html) {
                        $tag_nodes = $this->getTags($node, $feed_element);
                        if (!isset($tag_nodes[0])) {
                            $tag_node = null;
                        } else {
                            $tag_node = $tag_nodes[0];
                        }
                        $element_text = (is_object($tag_node)) ?
                            $tag_node->textContent: "";
                    } else if ($is_regex) {
                        preg_match($feed_element, $node, $matches);
                        $tag_node = null;
                        $element_text =
                            (empty($matches[1])) ? "" : $matches[1];
                    } else {
                        if (!is_array($feed_element)) {
                            $feed_element = [$feed_element];
                        }
                        foreach ($feed_element as $tag_name) {
                            $tag_node = $node->getElementsByTagName(
                                    $tag_name)->item(0);
                            $element_text = (is_object($tag_node)) ?
                                $tag_node->nodeValue: "";
                            if ($element_text) {
                                break;
                            }
                        }
                    }
                    if ($db_element == "link" && $tag_node &&
                        ($element_text == "" || ($is_html && !$is_json))) {
                        if ($is_html) {
                            $element_text =
                               $tag_node->documentElement->getAttribute("href");
                        } else {
                            $element_text = $tag_node->getAttribute("href");
                        }
                        $element_text = UrlParser::canonicalLink($element_text,
                            $feed["SOURCE_URL"]);
                    }
                    if ($db_element == "link" && $tag_node && $is_json) {
                        $element_text = UrlParser::canonicalLink($element_text,
                            $feed["SOURCE_URL"]);
                    }
                    $item[$db_element] = strip_tags($element_text);
                }
                $item['image_link'] = "";
                if (!empty($feed['IMAGE_XPATH'])) {
                    if ($feed['IMAGE_XPATH'][0]=="^") {
                        $is_channel_image = true;
                        $image_xpath = substr($feed['IMAGE_XPATH'], 1);
                    } else {
                        $is_channel_image = false;
                        $image_xpath = $feed['IMAGE_XPATH'];
                    }
                    if ($is_html) {
                        if ($is_channel_image) {
                            $dom_xpath = new \DOMXPath($sub_dom);
                        } else {
                            $dom_xpath = new \DOMXPath($node);
                        }
                        $image_nodes =
                            $dom_xpath->evaluate($image_xpath);
                        if ($image_nodes && $image_nodes->item(0)) {
                            $item['image_link'] =
                                $image_nodes->item(0)->nodeValue;
                        }
                    } else if ($is_regex) {
                        preg_match($feed['IMAGE_XPATH'], $node, $matches);
                        $item['image_link'] = (empty($matches[1])) ? "" :
                            $matches[1];
                    } else {
                        $dom_xpath = new \DOMXPath($dom);
                        if ($is_channel_image) {
                            $query = $image_xpath;
                        } else {
                            $query = $node->getNodePath() . $image_xpath;
                        }
                        libxml_clear_errors();
                        $image_nodes = $dom_xpath->evaluate($query);
                        if ($image_nodes && $image_nodes->item(0)) {
                            $item['image_link'] =
                                $image_nodes->item(0)->nodeValue;
                        }
                    }
                    if (!empty($item['image_link'])) {
                        $image_hash = L\crawlHash($item['image_link']);
                        file_put_contents("$base_dir/$image_hash.jpg.txt",
                            $item['image_link']);
                        $item['image_link'] = $image_url .
                            "&amp;n=" . $image_hash;
                    }
                }
                if (!empty($item['link']) && !empty($item['title']) &&
                    !empty($item_elements['link'])
                    && $item['link'] == $item_elements['link']) {
                    $item['link'] .= "#".L\crawlHash($item['title']);
                }
                $did_add = $this->addFeedItemIfNew($item, $feed['NAME'], $lang,
                    $age, $unique_fields);
                if ($did_add) {
                    $num_added++;
                }
                $num_seen++;
            }
            L\crawlLog("----...added $num_added feed items of $num_seen ".
                "on rss page.\n Done Processing {$feed['NAME']}.");
        }
    }
    /**
     * Returns an array of DOMDocuments for the nodes that match an xpath
     * query on $dom, a DOMDocument
     *
     * @param DOMDocument $dom document to run xpath query on
     * @param string $query xpath query to run
     * @return array of DOMDocuments one for each node matching the
     *  xpath query in the orginal DOMDocument
     */
    public function getTags($dom, $query)
    {
        $nodes = [];
        $dom_xpath = new \DOMXPath($dom);
        if (!$dom_xpath) {
            return [];
        }
        $tags = $dom_xpath->query($query);
        if (!$tags) {
            return [];
        }
        $i = 0;
        while ($item = $tags->item($i)) {
            $tmp_dom = new \DOMDocument;
            $tmp_dom->formatOutput = true;
            $node = $tmp_dom->importNode($item, true);
            $tmp_dom->appendChild($node);
            $nodes[] = $tmp_dom;
            $i++;
        }
        return $nodes;
    }
    /**
     * Copies all feeds items newer than $age to a new shard, then deletes
     * old index shard and database entries older than $age. Finally sets copied
     * shard to be active. If this method is going to take max_execution_time/2
     * it returns false, so an additional job can be schedules; otherwise
     * it returns true
     *
     * @param int $age how many seconds old records should be deleted
     * @return bool whether job executed to complete
     */
    public function rebuildFeedShard($age)
    {
        $time = time();
        $feed_shard_name = C\WORK_DIRECTORY."/feeds/index";
        $prune_shard_name = C\WORK_DIRECTORY."/feeds/prune_index";
        $prune_shard =  new IndexShard($prune_shard_name);
        $too_old = $time - $age;
        if (!$prune_shard) {
            return false;
        }
        $pre_feeds = $this->tasks;
        if (!$pre_feeds) {
            return false;
        }
        $feeds = [];
        foreach ($pre_feeds as $pre_feed) {
            if (!isset($pre_feed['NAME'])) {
                continue;
            }
            $feeds[$pre_feed['NAME']] = $pre_feed;
        }
        $db = $this->db;
        // we now rebuild the inverted index with the remaining items
        $sql = "SELECT * FROM FEED_ITEM ".
            "WHERE PUBDATE >= ? ".
            "ORDER BY PUBDATE DESC";
        $result = $db->execute($sql, [$too_old]);
        if ($result) {
            $completed = true;
            L\crawlLog("----..Making new index" .
                " of non-pruned items.");
            $i = 0;
            while ($item = $db->fetchArray($result)) {
                L\crawlTimeoutLog(
                    "----..have added %s non-pruned items to index.", $i);
                $i++;
                if (!isset($item['SOURCE_NAME'])) {
                    continue;
                }
                $source_name = $item['SOURCE_NAME'];
                if (isset($feeds[$source_name])) {
                    $lang = $feeds[$source_name]['LANGUAGE'];
                    $media_category = $feeds[$source_name]['MEDIA_CATEGORY'];
                } else {
                    $lang = "";
                    $media_category = "news";
                }
                $phrase_string = $item["TITLE"] . " ". $item["DESCRIPTION"];
                $word_and_qa_lists = PhraseParser::extractPhrasesInLists(
                    $phrase_string, $lang);
                $raw_guid = L\unbase64Hash($item["GUID"]);
                $doc_keys = L\crawlHash($item["LINK"], true) .
                    $raw_guid."d". substr(L\crawlHash(
                    UrlParser::getHost($item["LINK"])."/", true), 1);
                $meta_ids = $this->calculateMetas($lang, $item['PUBDATE'],
                    $source_name, $item["GUID"], $media_category);
                $prune_shard->addDocumentWords($doc_keys, $item['PUBDATE'],
                    $word_and_qa_lists["WORD_LIST"], $meta_ids,
                    PhraseParser::$materialized_metas, true, false);
            }
        }
        $prune_shard->save();
        restore_error_handler();
        @chmod($prune_shard_name, 0777);
        @chmod($feed_shard_name, 0777);
        @rename($prune_shard_name, $feed_shard_name);
        @chmod($feed_shard_name, 0777);
        set_error_handler(C\NS_LIB . "yioop_error_handler");
        L\crawlLog("----..deleting old feed items");
        $sql = "DELETE FROM FEED_ITEM WHERE PUBDATE < ?";
        $db->execute($sql, [$too_old]);
        $base_dir = C\APP_DIR . "/resources";
        $subfolder = L\crawlHash(
            'group' . C\PUBLIC_GROUP_ID . C\AUTH_KEY);
        $prefix_folder = substr($subfolder, 0, 3);
        $subfolder = "t" . $subfolder;
        $date = date('Y-m-d', $too_old - C\ONE_DAY);
        $old_folder = "$base_dir/$prefix_folder/$subfolder/$date";
        L\crawlLog("----..deleting old feed image thumb folder");
        $db->unlinkRecursive($old_folder);
        L\crawlLog("----..done deleting old items");
    }
    /**
     * Adds $item to FEED_ITEM table in db if it isn't already there
     *
     * @param array $item data from a single feed item
     * @param string $source_name string name of the feed $item was found
     * on
     * @param string $lang locale-tag of the feed
     * @param int $age how many seconds old records should be ignored
     * @param array if $item['guid'] is not set then hash these fields to
     *      produce a unique identifier
     * @return bool whether an item was added
     */
    public function addFeedItemIfNew($item, $source_name, $lang, $age,
        $unique_fields)
    {
        if (empty($item["link"]) || empty($item["title"]) ||
            empty($item["description"]) ||
            strlen($item["link"]) > C\MAX_URL_LEN) {
            return false;
        }
        $item["title"] = substr($item["title"], 0, C\TITLE_LEN);
        $item["description"] = substr($item["description"], 0,
            C\MAX_GROUP_POST_LEN);
        if (empty($item["guid"])) {
            $hash_string = "";
            foreach ($unique_fields as $field) {
                $hash_string .= $item[$field];
            }
            $item["guid"] = L\crawlHash($hash_string);
        } else {
            $item["guid"] = L\crawlHash($item["guid"]);
        }
        if (!isset($item["image_link"]) ||
            strlen($item["image_link"]) > C\MAX_URL_LEN) {
            $item["image_link"] = "";
        }
        $raw_guid = L\unbase64Hash($item["guid"]);
        if (!isset($item["pubdate"]) || $item["pubdate"] == "") {
            $item["pubdate"] = time();
        } else {
            $item["pubdate"] = strtotime($item["pubdate"]);
            if ($item["pubdate"] < 0) {
                $item["pubdate"] = time();
            }
        }
        if (time() - $item["pubdate"] > $age) {
            return false;
        }
        $sql = "SELECT COUNT(*) AS NUMBER FROM FEED_ITEM WHERE GUID = ?";
        $db = $this->db;
        $result = $db->execute($sql, [$item["guid"]]);
        if ($result) {
            $row = $db->fetchArray($result);
            if ($row["NUMBER"] > 0) {
                return false;
            }
        } else {
            return true;
        }
        $sql = "INSERT INTO FEED_ITEM (GUID, TITLE, LINK, IMAGE_LINK,".
            "DESCRIPTION, PUBDATE, SOURCE_NAME) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $result = $db->execute($sql, [$item['guid'],
            $item['title'], $item['link'], $item['image_link'],
            $item['description'], $item['pubdate'], $source_name]);
        if (!$result) {
            return false;
        }
        return true;
    }
    /**
     * Used to calculate the meta words for RSS feed items
     *
     * @param string $lang the locale_tag of the feed item
     * @param int $pubdate UNIX timestamp publication date of item
     * @param string $source_name the name of the feed
     * @param string $guid the guid of the item
     * @param string $media_category determines what media: metas to inject.
     *      Default is news.
     *
     * @return array $meta_ids meta words found
     */
    public function calculateMetas($lang, $pubdate, $source_name, $guid,
        $media_category = "news")
    {
        $meta_ids = ["media:$media_category", "media:$media_category:" .
            urlencode( mb_strtolower($source_name)),
            "guid:".strtolower($guid)];
        $meta_ids[] = 'date:'.date('Y', $pubdate);
        $meta_ids[] = 'date:'.date('Y-m', $pubdate);
        $meta_ids[] = 'date:'.date('Y-m-d', $pubdate);
        $meta_ids[] = 'date:'.date('Y-m-d-H', $pubdate);
        $meta_ids[] = 'date:'.date('Y-m-d-H-i', $pubdate);
        $meta_ids[] = 'date:'.date('Y-m-d-H-i-s', $pubdate);
        if ($lang != "") {
            $lang_parts = explode("-", $lang);
            $meta_ids[] = 'lang:'.$lang_parts[0];
            if (isset($lang_parts[1])) {
                $meta_ids[] = 'lang:' . $lang;
            }
        }
        return $meta_ids;
    }
    /**
     * Converts the results of an associative array coming from a
     * json_decode'd string to an HTML string where the json field
     * have become tags prefixed with "json". This can then be handled
     * in the rest of the feeds updater like an HTML feed.
     *
     * @param array $json_decode associative array coming from a
     *  json_decode'd string
     * @return string result of converting array to an html string
     */
    public function convertJsonDecodeToTags($json_decode)
    {
        $out = "";
        if (is_array($json_decode)) {
            foreach ($json_decode as $key => $value) {
                $tag_name = $key;
                $attributes = "";
                if (is_int($tag_name)) {
                    $tag_name = "item";
                    $attributes = "data-number='$key'";
                }
                /* this is to avoid name collisions with html tag names when
                   we convert json to html and dom it
                 */
                $out .= "\n<json$tag_name $attributes>\n";
                $out .= $this->convertJsonDecodeToTags($value);
                $out .= "\n</json$tag_name>\n";
            }
        } else {
            $out = $json_decode;
        }
        return $out;
    }
    /**
     * Information about how to parse non-rss and atom feeds is stored in the
     * MEDIA_SOURCE table in the AUX_INFO column. When a feed is read from
     * this table this method is used to parse this column into additional
     * fields which are easier to use for manipulating feed data. Example feed
     * types for which this parsing is readed are html, json and regex feeds.
     * In the case of an rss or atom feed this method assumes the AUX_INFO field
     * just contains an xpath expression for finding a feed_item's image, and
     * so just parses the AUX_INFO field into an IMAGE_XPATH field.
     *
     * @param array &$feed associative array of data about one particular feed
     */
    public function parseFeedAuxInfo(&$feed)
    {
        $aux_parts = explode("###",
                html_entity_decode($feed['AUX_INFO'], ENT_QUOTES));
        if (in_array($feed['TYPE'], ['html', 'json', 'regex'])) {
            list($feed['CHANNEL_PATH'], $feed['ITEM_PATH'],
            $feed['TITLE_PATH'], $feed['DESCRIPTION_PATH'],
            $feed['LINK_PATH']) = $aux_parts;
            $offset = 5;
        } elseif ($feed['TYPE'] == 'rss') {
            $offset = 0;
        }
        $feed['MEDIA_CATEGORY'] = "news";
        if (isset($aux_parts[$offset])) {
            $feed['IMAGE_XPATH'] = $aux_parts[$offset];
            if (isset($aux_parts[$offset + 1])) {
                $feed['MEDIA_CATEGORY'] = $aux_parts[$offset + 1];
            }
        } else {
            $feed['IMAGE_XPATH'] = "";
        }
        if ($feed['TYPE'] == 'json') {
            /* this is to avoid name collisions with html tag names when
               we convert json to html and dom it. The making of tags
               with the json prefix is done in convertJsonDecodeToTags.
               Here we are making our xpaths compatible with this
             */
            foreach (['CHANNEL_PATH', 'ITEM_PATH',
                'TITLE_PATH', 'DESCRIPTION_PATH', 'LINK_PATH',
                'IMAGE_XPATH'] as $component) {
                $xpath = $feed[$component];
                $xpath_parts = explode("/", $xpath);
                $num_parts = count($xpath_parts);
                for ($j = 0; $j < $num_parts; $j++) {
                    if ($xpath_parts[$j] != "") {
                        $xpath_parts[$j] = "json" . $xpath_parts[$j];
                    }
                }
                $feed[$component] = implode("/", $xpath_parts);
            }
        }
    }
}