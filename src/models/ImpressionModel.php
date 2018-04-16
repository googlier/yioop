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
 * @author Chris Pollett
 * @license http://www.gnu.org/licenses/ GPL3
 * @link http://www.seekquarry.com/
 * @copyright 2009 - 2017
 * @filesource
 */
namespace seekquarry\yioop\models;

use seekquarry\yioop\configs as C;
use seekquarry\yioop\library as L;

/**
 * Model used to keep track for analytic and user experience activities that
 * users carry out on a Yioop web site. For analytics things that might
 * tracked are wiki page views, queries, query outcomes. For UX things that
 * the impression model allows is to keep track of recent group a user has
 * visited to provide better bread crumb drop downs, make the manage account
 * landing page list more relevant groups, determine start of whether a
 * media item has been watched, completely watched, etc.
 *
 * @author Chris Pollett
 */
class ImpressionModel extends Model
{
    /**
     * Used to create a new counter related to a particular user for a
     * particular activity in the impression analytics. This entails adding both
     * a log-like record of when the activity happened and creating  a
     * new global count for this acitvity.
     *
     * @param int $user_id id of user we are adding analytic information for
     * @param int $item_id id of particular item we are adding analytic
     *      information of
     * @param int $type_id type of particular item we are adding analytic
     *      information of (group, wiki, thread, etc)
     */
    public function init($user_id, $item_id, $type_id)
    {
        $this->initWithDb($user_id, $item_id, $type_id, $this->db);
    }
    /**
     * Used to add a count record related to a particular user for a particular
     * activity to the impression analytics. This entails adding both
     * a log-like record of when the activity happened and incrementing a
     * global count of this activity.
     *
     * @param int $user_id id of user we are adding analytic information for
     * @param int $item_id id of particular item we are adding analytic
     *      information of
     * @param int $type_id type of particular item we are adding analytic
     *      information of (group, wiki, thread, etc)
     */
    public function add($user_id, $item_id, $type_id)
    {
        $this->addWithDb($user_id, $item_id, $type_id, $this->db);
    }
    /**
     * Used to add a count record related to a web search to the impression
     * analytics. This entails adding a record to QUERY_ITEM if it doesn't
     * exist together with an add() call. This is perhaps not the ideal
     * model for this function, but didn't want to create a new model for
     * just this one method.
     *
     * @param int $query search query we are adding an impression for
     */
    public function addQueryImpression($query)
    {
        if (!C\SEARCH_ANALYTICS) {
            return;
        }
        $db = $this->db;
        $query_hash = L\crawlHash($query);
        $sql = "SELECT ID FROM QUERY_ITEM WHERE QUERY_HASH = ?";
        $result = $db->execute($sql, [$query_hash]);
        $row = $db->fetchArray($result);
        if (empty($row['ID'])) {
            $sql = "INSERT INTO QUERY_ITEM (QUERY_HASH, QUERY, CREATION)
                VALUES (?, ?, ?)";
            $result = $db->execute($sql, [$query_hash, $query, time()]);
            $this->initWithDb(C\PUBLIC_USER_ID, $db->insertID("QUERY_ITEM"),
                C\QUERY_IMPRESSION, $db);
        } else {
            $this->addWithDb(C\PUBLIC_USER_ID, $row['ID'],
                C\QUERY_IMPRESSION, $db);
        }
    }
    /**
     * Used to delete information related to a particular user from the
     * impression analytics.
     *
     * @param int $user_id id of user we are deleting analytic information for
     * @param int $item_id id of particular item we are deleting analytic
     *      information of
     * @param int $type_id type of particular item we are deleting analytic
     *      information of (group, wiki, thread, etc)
     */
    public function delete($user_id, $item_id, $type_id)
    {
        $this->deleteWithDb($user_id, $item_id, $type_id, $this->db);
    }
    /**
     * Returns num many most recent impression items of the given type for
     * a user
     *
     * @param int $user_id id of user we are looking for information about
     * @param int $type_id type of particular item we want information on
     *      (group, wiki, thread, etc)
     * @param int $num how many most recent entries we want to get
     * @return array of $num many most recent item id's of $type_id of
     *      for the given $user_id
     */
    public function recent($user_id, $type_id, $num)
    {
        $db = $this->db;
        $sql = "SELECT ITEM_ID, MAX(VIEW_DATE) AS MOST_RECENT
            FROM ITEM_IMPRESSION
            WHERE USER_ID = ? AND ITEM_TYPE = ? GROUP BY ITEM_ID
            ORDER BY MOST_RECENT DESC LIMIT ?";
        $result = $db->execute($sql, [$user_id, $type_id, $num]);
        $rows = [];
        while ($row = $db->fetchArray($result)) {
            $rows[] = $row['ITEM_ID'];
        }
        $rows = array_values(array_filter($rows));
        return $rows;
    }
    /**
     * Used by Analytics job to aggregate impression raw data to make
     * hourly, daily, monthly, and yearly impression statistics.
     */
    public function computeStatistics()
    {
        $db = $this->db;
        $timestamps = [C\ONE_HOUR => floor(time()/C\ONE_HOUR) * C\ONE_HOUR,
            C\ONE_DAY => floor(time()/C\ONE_DAY)*C\ONE_DAY,
            C\ONE_MONTH => floor(time()/C\ONE_MONTH)*C\ONE_MONTH,
            C\ONE_YEAR => floor(time()/C\ONE_YEAR)*C\ONE_YEAR];
        $table = "ITEM_IMPRESSION";
        $condition = "VIEW_DATE >= ?";
        $sum = "COUNT(*)";
        foreach ($timestamps as $period => $timestamp) {
            $sql = "DELETE FROM ITEM_IMPRESSION_SUMMARY
                WHERE UPDATE_PERIOD = ? AND UPDATE_TIMESTAMP = ?";
            $db->execute($sql, [$period, $timestamp]);
            $sql = "INSERT INTO ITEM_IMPRESSION_SUMMARY (USER_ID, ITEM_ID,
                ITEM_TYPE, UPDATE_PERIOD, UPDATE_TIMESTAMP, NUM_VIEWS)
                SELECT USER_ID, ITEM_ID, ITEM_TYPE, ? AS UPDATE_PERIOD,
                ? AS UPDATE_TIMESTAMP, $sum AS NUM_VIEWS
                FROM  $table
                WHERE $condition
                GROUP BY USER_ID, ITEM_ID, ITEM_TYPE";
            L\crawlLog( "Computing statistics for $period " .
                "second update period");
            $db->execute($sql, [$period, $timestamp, $timestamp]);
            $table = "ITEM_IMPRESSION_SUMMARY";
            $condition = "UPDATE_PERIOD = $period AND UPDATE_TIMESTAMP >= ?";
            $sum = "SUM(NUM_VIEWS)";
        }
        // delete user data older than one year
        $sql = "DELETE FROM ITEM_IMPRESSION WHERE VIEW_DATE < ?";
        $db->execute($sql, [$timestamp]);
        $sql = "DELETE FROM ITEM_IMPRESSION_SUMMARY WHERE UPDATE_TIMESTAMP < ?".
            " AND UPDATE_PERIOD > ". C\FOREVER;
        $db->execute($sql, [$timestamp]);
    }
    /**
     * Used to return an array of impression statistics for a particular update
     * period for a particular type of impression.
     *
     * @param int $type type of impression to return statistic
     * @param int $period an update period to get statistics for in second, for
     *      example, 3600 would give statistics for an hour. Only
     *      hour, day, month, and year second quantities supported
     * @param int $filter a string to filter the items names of the
     *      statistics returns (for example, filter could be used to
     *      filter statistics about popular thread names with respect to
     *      the number of views statistics )
     * @param int $group_id group identifier of group want stats for
     * @param int $user_id user identifier of user want stats for
     * @param int $limit first row we want from the result set
     * @param int $num number of rows we want starting from the first row
     *     in the result set
     * @return array
     */
    public function getStatistics($type, $period, $filter = "",
        $group_id = C\PUBLIC_GROUP_ID, $user_id = C\PUBLIC_USER_ID,
        $limit = 0, $num = 100)
    {
        $db = $this->db;
        $select = "";
        $from = "";
        $where = "";
        list ($timestamp, $actual_period) = $this->getLastTimestamp($period);
        $parameters = [$type, $actual_period, $group_id, $user_id];
        $filter_where = "";
        switch ($type)
        {
            case C\GROUP_IMPRESSION:
                $select = "";
                $from = "";
                $where = " AND IIS.ITEM_ID = G.GROUP_ID";
                break;
            case C\QUERY_IMPRESSION:
                $select = ", MIN(QI.QUERY) AS ITEM_NAME";
                $from = ", QUERY_ITEM QI";
                $where = " AND IIS.ITEM_ID = QI.ID ";
                if (!empty($filter)) {
                    $filter_where = " AND LOWER(QI.QUERY) ".
                        "LIKE LOWER('%$filter%') ";
                }
                break;
            case C\THREAD_IMPRESSION:
                $select = ", MIN(GI.TITLE) AS ITEM_NAME";
                $from = ", GROUP_ITEM GI";
                $where = " AND IIS.ITEM_ID = GI.ID ".
                    "AND G.GROUP_ID = GI.GROUP_ID";
                if (!empty($filter)) {
                    $filter_where = " AND LOWER(GI.TITLE) ".
                        "LIKE LOWER('%$filter%') ";
                }
                break;
            case C\WIKI_IMPRESSION:
                $select = ", MIN(GP.TITLE) AS ITEM_NAME";
                $from = ", GROUP_PAGE GP";
                $where = " AND IIS.ITEM_ID = GP.ID ".
                    "AND G.GROUP_ID = GP.GROUP_ID";
                if (!empty($filter)) {
                    $filter_where = " AND LOWER(GP.TITLE) ".
                        "LIKE LOWER('%$filter%') ";
                }
                break;
        }
        $timestamp_where = "";
        if ($period != C\FOREVER) {
            $timestamp_where = " AND IIS.UPDATE_TIMESTAMP >= ? ";
            $parameters[] = $timestamp;
        }
        $sql = "SELECT MIN(G.GROUP_NAME) AS GROUP_NAME, IIS.ITEM_ID AS ID,
            MIN(IIS.UPDATE_PERIOD) AS PERIOD,
            MIN(IIS.UPDATE_TIMESTAMP) AS TIMESTAMP,
            SUM(IIS.NUM_VIEWS) AS NUM_VIEWS $select
            FROM ITEM_IMPRESSION_SUMMARY IIS, GROUPS G $from
            WHERE  IIS.ITEM_TYPE = ? AND IIS.UPDATE_PERIOD = ?
            AND G.GROUP_ID = ? AND IIS.USER_ID = ? $where $filter_where
            $timestamp_where GROUP BY IIS.ITEM_ID ORDER BY PERIOD ASC,
            TIMESTAMP DESC, NUM_VIEWS DESC " .
            $db->limitOffset($limit, $num);
        $result = $db->execute($sql, $parameters);
        $statistics = [];
        while ($row = $db->fetchArray($result)) {
            if ($type == C\GROUP_IMPRESSION) {
                $statistics[] = $row;
            } else {
                $item_name = $row['ITEM_NAME'];
                unset($row['ITEM_NAME']);
                $statistics[$item_name][] = $row;
            }
        }
        return $statistics;
    }
    /**
     * Calculates total number of views of given item id for
     * given time period
     *
     * @param int $type the impression type to get data for
     * @param int $period time period for which to show stats
     * @param int $item_id item identifier of item for which to show stats
     * @return array
     */
    public function getPeriodHistogramData($type, $period, $item_id)
    {
        $db = $this->db;
        list ($timestamp, $actual_period) = $this->getLastTimestamp($period);
        $parameters = [$type, $actual_period, $item_id, $timestamp];
        $sql = "SELECT IIS.ITEM_ID AS ID, IIS.NUM_VIEWS AS VIEWS,
            IIS.ITEM_TYPE AS TYPE, IIS.UPDATE_TIMESTAMP,
            IIS.UPDATE_PERIOD AS PERIOD
            FROM ITEM_IMPRESSION_SUMMARY IIS
            WHERE IIS.ITEM_TYPE = ? AND IIS.UPDATE_PERIOD = ?
            AND IIS.ITEM_ID = ? AND IIS.USER_ID = 2
            AND IIS.UPDATE_TIMESTAMP >= ? ORDER BY UPDATE_TIMESTAMP ASC";
        $result = $db->execute($sql, $parameters);
        $statistics = [];
        while ($row = $db->fetchArray($result)) {
            $statistics[] = $row;
        }
        return $statistics;
    }
    /**
     * Subtracts the timestamp to get actual time period window.
     * To get statistics for one time period, lower time period's data needs
     * to be extracted. For example, to get last day's stat, result in every
     * one hour needs to be extracted in the last 24 hours
     *
     * @param int $period time period
     * @return array
     */
    public function getLastTimestamp($period)
    {
        $time_periods = [C\ONE_HOUR => 1, C\ONE_DAY => 24, C\ONE_MONTH => 30,
            C\ONE_YEAR => 12, C\FOREVER => 0];
        $previous_period = [C\ONE_HOUR => C\ONE_HOUR,
            C\ONE_DAY => C\ONE_HOUR, C\ONE_MONTH => C\ONE_DAY,
            C\ONE_YEAR => C\ONE_MONTH, C\FOREVER => C\FOREVER];
        $interval = (!empty($time_periods[$period])) ?
            $time_periods[$period] : C\FOREVER;
        $period = (!empty($previous_period[$period])) ?
            $previous_period[$period] : 0;
        $current_timestamp = floor(time()/$period) * $period;
        $last_timestamp = ($period == C\FOREVER) ? 0:
            ($current_timestamp - ($period * ($interval - 1)));
        return [$last_timestamp, $period];
    }
    /**
     * Used to create a new counter related to a particular user for a
     * particular activity in the impression analytics. This entails adding both
     * a log-like record of when the activity happened and creating  a
     * new global count for this activity. This static method version requires
     * having an initialized data source manager and may be appropriate to call
     * in the context of another model.
     *
     * @param int $user_id id of user we are adding analytic information for
     * @param int $item_id id of particular item we are adding analytic
     *      information of
     * @param int $type_id type of particular item we are adding analytic
     *      information of (group, wiki, thread, etc)
     * @param object $db a DatasourceManager used to query the Yioop
     *      Yioop database
     */
    public static function initWithDb($user_id, $item_id, $type_id, $db)
    {
        $sql = "INSERT INTO ITEM_IMPRESSION_SUMMARY VALUES
            (?, ?, ?, ".C\FOREVER.", 0, 0, -1, -1)";
        $db->execute($sql, [C\PUBLIC_USER_ID, $item_id, $type_id]);
        self::addWithDb($user_id, $item_id, $type_id, $db);
    }
    /**
     * Used to add a count record related to a particular user for a particular
     * activity to the impression analytics. This entails adding both
     * a log-like record of when the activity happened and incrementing a
     * global count of this activity. This static method version requires
     * having an initialized data source manager and may be appropriate to call
     * in the context of another model.
     *
     * @param int $user_id id of user we are adding analytic information for
     * @param int $item_id id of particular item we are adding analytic
     *      information of
     * @param int $type_id type of particular item we are adding analytic
     *      information of (group, wiki, thread, etc)
     * @param object $db a DatasourceManager used to query the Yioop
     *      Yioop database
     */
    public static function addWithDb($user_id, $item_id, $type_id, $db)
    {
        $sql = "INSERT INTO ITEM_IMPRESSION VALUES (?, ?, ?, ?)";
        if ($user_id != C\PUBLIC_USER_ID) {
            $db->execute($sql, [$user_id, $item_id, $type_id, time()]);
        }
        $db->execute($sql, [C\PUBLIC_USER_ID, $item_id, $type_id, time()]);
        $sql = "UPDATE ITEM_IMPRESSION_SUMMARY
            SET NUM_VIEWS = NUM_VIEWS + 1 WHERE USER_ID=? AND
            ITEM_ID=? AND ITEM_TYPE=? AND UPDATE_PERIOD = ". C\FOREVER . "
            AND UPDATE_TIMESTAMP = 0";
        $db->execute($sql, [C\PUBLIC_USER_ID, $item_id, $type_id]);
    }
    /**
     * Used to delete information related to a particular user from the
     * impression analytics. This static method version requires
     * having an initialized data source manager and may be appropriate to call
     * in the context of another model.
     *
     * @param int $user_id id of user we are deleting analytic information for
     * @param int $item_id id of particular item we are deleting analytic
     *      information of
     * @param int $type_id type of particular item we are deleting analytic
     *      information of (group, wiki, thread, etc)
     * @param object $db a DatasourceManager used to query the Yioop
     *      Yioop database
     */
    public static function deleteWithDb($user_id, $item_id, $type_id, $db)
    {
        $sql = "DELETE FROM ITEM_IMPRESSION WHERE USER_ID=? AND
            ITEM_ID=? AND ITEM_TYPE=?";
        $db->execute($sql, [$user_id, $item_id, $type_id]);
        $db->execute($sql, [C\PUBLIC_USER_ID, $item_id, $type_id]);
        $sql = "DELETE FROM ITEM_IMPRESSION_SUMMARY WHERE USER_ID=? AND
            ITEM_ID=? AND ITEM_TYPE=?";
        $db->execute($sql, [$user_id, $item_id, $type_id]);
        $db->execute($sql, [C\PUBLIC_USER_ID, $item_id, $type_id]);
    }
}
