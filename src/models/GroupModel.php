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
 * @author Mallika Perepa, Chris Pollett
 * @license http://www.gnu.org/licenses/ GPL3
 * @link http://www.seekquarry.com/
 * @copyright 2009 - 2017
 * @filesource
 */
namespace seekquarry\yioop\models;

use seekquarry\yioop as B;
use seekquarry\yioop\configs as C;
use seekquarry\yioop\library as L;
use seekquarry\yioop\library\MediaConstants;
use seekquarry\yioop\library\WikiParser;
use seekquarry\yioop\library\processors\ImageProcessor;
use seekquarry\yioop\models\ImpressionModel;

/**
 * This is class is used to handle
 * db results related to Group Administration. Groups are collections of
 * users who might access a common blog/news feed and set of pages. This
 * method also controls adding and deleting entries to a group feed and
 * does limited access control checks of these operations.
 *
 * @author Mallika Perepa (creator), Chris Pollett (rewrite)
 */
class GroupModel extends Model implements MediaConstants
{
    /**
     * Associations of the form
     *     name of field for web forms => database column names/abbreviations
     * In this case, things will in general map to the GROUPS, or USER_GROUP
     * or GROUP_ITEM tables in the Yioop database
     * @var array
     */
    public $search_table_column_map = ["access"=>"G.MEMBER_ACCESS",
        "group_id"=>"G.GROUP_ID", "post_id" => "GI.ID",
        "join_date"=>"UG.JOIN_DATE",
        "name"=>"G.GROUP_NAME", "owner"=>"O.USER_NAME",
        "pub_date" => "GI.PUBDATE", "parent_id"=>"GI.PARENT_ID",
        "register"=>"G.REGISTER_TYPE", "status"=>"UG.STATUS",
        "user_id"=>"P.USER_ID", "voting" => "G.VOTE_ACCESS",
        "lifetime" => "G.POST_LIFETIME",
        "key" => "G.GROUP_ID"];
    /**
     * These fields if present in $search_array (used by @see getRows() ),
     * but with value "-1", will be skipped as part of the where clause
     * but will be used for order by clause
     * @var array
     */
    public $any_fields = ["access", "register", "voting", "lifetime"];
    /**
     * Used to determine the select clause for GROUPS table when do query
     * to marshal group objects for the controller mainly in mangeGroups
     * @param mixed $args We use $args[1] to say whether in browse mode or not.
     *     browse mode is for groups a user could join rather than ones already
     *     joined
     */
    public function selectCallback($args = null)
    {
        if (count($args) < 2) {
            return "*";
        }
        list($user_id, $browse, ) = $args;
        if ($browse) {
            $join_date = "";
            $status = "";
        } else {
            $join_date = ", UG.JOIN_DATE AS JOIN_DATE";
            $status = " UG.STATUS AS STATUS,";
        }
        $select = "DISTINCT G.GROUP_ID AS GROUP_ID,
            G.GROUP_NAME AS GROUP_NAME, G.OWNER_ID AS OWNER_ID,
            O.USER_NAME AS OWNER, REGISTER_TYPE, $status
            G.MEMBER_ACCESS, VOTE_ACCESS, POST_LIFETIME $join_date";
        return $select;
    }
    /**
     * {@inheritDoc}
     *
     * @param mixed $args any additional arguments which should be used to
     *     determine these tables (in this case none)
     */
    public function fromCallback($args = null)
    {
        return "GROUPS G, USER_GROUP UG, USERS O";
    }
    /**
     * Used to restrict getRows in which rows it returns. Rows in this
     * case corresponding to Yioop groups. The restrictions added are to
     * restrict to those group available to a given user_id and whether or
     * not the user wants groups subscribed to, or groups that could be
     * subscribed to
     *
     * @param array $args first two elements are the $user_id of the user
     *     and the $browse flag which says whether or not user is browing
     *     through all groups to which he could subscribe and read or
     *     just those groups to which he is alrady subscribed.
     * @return string a SQL WHERE clause suitable to perform the above
     *     restrictions
     */
    public function whereCallback($args = null)
    {
        $db = $this->db;
        if (count($args) < 2) {
            return "";
        }
        list($user_id, $browse, ) = $args;
        if ($browse) {
            $where =
                " UG.GROUP_ID=G.GROUP_ID AND G.OWNER_ID=O.USER_ID AND NOT ".
                "EXISTS (SELECT * FROM USER_GROUP UG2 WHERE UG2.USER_ID = ".
                $db->escapeString($user_id)." AND UG2.GROUP_ID = G.GROUP_ID)";
        } else {
            $where = " UG.USER_ID='".$db->escapeString($user_id).
                "' AND  UG.GROUP_ID=G.GROUP_ID AND G.OWNER_ID=O.USER_ID";
        }
        return $where;
    }
    /**
     * Get an array of users that belong to a group
     *
     * @param string $group_id  the group_id to get users for
     * @param string $filter to LIKE filter users
     * @param array $sorts directions on how to sort the columns of the
     *      results format is column_name => direction
     * @param int $limit first user to get
     * @param int $num number of users to return
     * @return array of USERS rows
     */
    public function getGroupUsers($group_id, $filter = "", $sorts = [],
        $limit = "", $num = C\NUM_RESULTS_PER_PAGE)
    {
        $db = $this->db;
        if ($limit !== "") {
            $limit = $db->limitOffset($limit, $num);
        }
        $like = "";
        $param_array = [$group_id];
        if ($filter != "") {
            $like = "AND U.USER_NAME LIKE ?";
            $param_array[] = "%" . $filter . "%";
        }
        $order_by = "";
        if (!empty($sorts)) {
            $sort_fields = ["JOIN_DATE", "STATUS", "USER_NAME"];
            $directions = ["ASC", "DESC"];
            foreach ($sorts as $column_name => $direction) {
                if (in_array($column_name, $sort_fields) &&
                    in_array($direction, $directions)) {
                    if (empty($order_by)) {
                        $order_by = " ORDER BY ";
                    } else {
                        $order_by .= ",";
                    }
                    $order_by .= " $column_name $direction";
                }
            }
        }
        $users = [];
        $sql = "SELECT UG.USER_ID, U.USER_NAME AS USER_NAME,".
            " UG.GROUP_ID, G.OWNER_ID, U.EMAIL, UG.STATUS AS STATUS,".
            " UG.JOIN_DATE AS JOIN_DATE".
            " FROM USER_GROUP UG, USERS U, GROUPS G".
            " WHERE UG.GROUP_ID = ? AND UG.USER_ID = U.USER_ID AND" .
            " G.GROUP_ID = UG.GROUP_ID $like $order_by $limit";
        $result = $db->execute($sql, $param_array);
        $i = 0;
        while ($users[$i] = $db->fetchArray($result)) {
            $i++;
        }
        unset($users[$i]); //last one will be null
        return $users;
    }
    /**
     * Get the number of users which belong to a group and whose user_name
     * matches a filter
     *
     * @param int $group_id id of the group to get a count of
     * @param string $filter to filter usernames by
     * @return int count of matching users
     */
    public function countGroupUsers($group_id, $filter="")
    {
        $db = $this->db;
        $users = [];
        $like = "";
        $users = "";
        $param_array = [$group_id];
        if ($filter != "") {
            $like = "AND UG.USER_ID = U.USER_ID AND U.USER_NAME LIKE ?";
            $users = ", USERS U";
            $param_array[] = "%".$filter."%";
        }
        $sql = "SELECT COUNT(DISTINCT UG.USER_ID) AS NUM ".
            " FROM USER_GROUP UG $users".
            " WHERE UG.GROUP_ID = ? $like";
        $result = $db->execute($sql, $param_array);
        if ($result) {
            $row = $db->fetchArray($result);
        }
        return $row['NUM'];
    }
    /**
     * Add a groupname to the database using provided string
     *
     * @param string $group_name  the groupname to be added
     * @param int $user_id user identifier of who owns the group
     * @param int $register flag that says what kinds of registration are
     *      allowed for this group NO_JOIN, REQUEST_JOIN, PUBLIC_JOIN
     * @param int $member flag that says how members other than the owner can
     *      access this group GROUP_READ, GROUP_READ_COMMENT (can comment
     *      on threads but not start. i.e., a blog), GROUP_READ_WRITE,
     *      (can read, comment, start threads), GROUP_READ_WIKI, (can read,
     *      comment, start threads, and edit the wiki)
     * @param int $voting flag that says how members can vote on each others
     *      posts: NON_VOTING_GROUP, UP_VOTING_GROUP, UP_DOWN_VOTING_GROUP
     * @param int $post_lifetime specifies the time in seconds that posts should
     *      live before they expire and are deleted
     */
    public function addGroup($group_name, $user_id, $register = C\REQUEST_JOIN,
        $member = C\GROUP_READ, $voting = C\NON_VOTING_GROUP,
        $post_lifetime = C\FOREVER)
    {
        $db = $this->db;
        $timestamp = L\microTimestamp();
        $sql = "INSERT INTO GROUPS (GROUP_NAME, CREATED_TIME, OWNER_ID,
            REGISTER_TYPE, MEMBER_ACCESS, VOTE_ACCESS, POST_LIFETIME)
            VALUES (?, ?, ?, ?, ?, ?, ?);";
        $db->execute($sql, [$group_name, $timestamp, $user_id,
            $register, $member, $voting, $post_lifetime]);
        $sql = "SELECT G.GROUP_ID AS GROUP_ID FROM ".
            " GROUPS G WHERE G.GROUP_NAME = ?";
        $result = $db->execute($sql, [$group_name]);
        if (!$row = $db->fetchArray($result)) {
            $last_id = -1;
        }
        $last_id = $row['GROUP_ID'];
        $now = time();
        $sql= "INSERT INTO USER_GROUP (USER_ID, GROUP_ID, STATUS,
            JOIN_DATE) VALUES
            ($user_id, $last_id, " . C\ACTIVE_STATUS . ", $now)";
        $db->execute($sql);
        ImpressionModel::initWithDb($user_id, $last_id, C\GROUP_IMPRESSION,
            $db);
        ImpressionModel::initWithDb(C\PUBLIC_GROUP_ID, $last_id,
            C\GROUP_IMPRESSION, $db);
        return $last_id;
    }
    /**
     * Takes the passed associated array $group representing changes
     * fields of a GROUPS row, and executes an UPDATE statement to persist
     * those changes fields to the database.
     *
     * @param array $group associative array with a GROUP_ID as well as the
     *     fields to update
     */
    public function updateGroup($group)
    {
        $db = $this->db;
        $group_id = $group['GROUP_ID'];
        unset($group['GROUP_ID']);
        unset($group['GROUP_NAME']);
        unset($group['OWNER']); //column not in table
        unset($group['STATUS']); // column not in table
        unset($group['JOIN_DATE']); // column not in table
        $sql = "UPDATE GROUPS SET ";
        $comma ="";
        $params = [];
        foreach ($group as $field => $value) {
            $sql .= "$comma $field=? ";
            $comma = ",";
            $params[] = $value;
        }
        $sql .= " WHERE GROUP_ID=?";
        $params[] = $group_id;
        $db->execute($sql, $params);
    }
    /**
     * Check is a user given by $user_id belongs to a group given
     * by $group_id. If the field $status is sent then check if belongs
     * to the group with $status access (active, invited, request, banned)
     *
     * @param int $user_id user to look up
     * @param int $group_id group to check if member of
     * @param int $status membership type
     * @return bool whether or not is a member
     */
    public function checkUserGroup($user_id, $group_id, $status = -1)
    {
        $db = $this->db;
        $params = [$user_id, $group_id];
        $sql = "SELECT COUNT(*) AS NUM FROM USER_GROUP UG WHERE
            UG.USER_ID=? AND UG.GROUP_ID=?";
        if ($status >=0) {
            $sql .= " AND STATUS=?";
            $params[] = $status;
        }
        $result = $db->execute($sql, $params);
        if (!$row = $db->fetchArray($result)) {
            return false;
        }
        if ($row['NUM'] <= 0) {
            return false;
        }
        return true;
    }
    /**
     * Change the status of a user in a group
     *
     * @param int $user_id of user to change
     * @param int $group_id of group to change status for
     * @param int $status what the new status should be
     */
    public function updateStatusUserGroup($user_id, $group_id, $status)
    {
        $db = $this->db;
        $sql = "UPDATE USER_GROUP SET STATUS=? WHERE
            GROUP_ID=? AND USER_ID=?";
        $db->execute($sql, [$status, $group_id, $user_id]);
    }
    /**
     * Get group id associated with groupname (so groupnames better be unique)
     *
     * @param string $group_name to use to look up a group_id
     * @return int  group_id corresponding to the groupname.
     */
    public function getGroupId($group_name)
    {
        $db = $this->db;
        $sql = "SELECT G.GROUP_ID AS GROUP_ID FROM ".
            "GROUPS G WHERE G.GROUP_NAME = ? ";
        $result = $db->execute($sql, [$group_name]);
        if (!$row = $db->fetchArray($result)) {
            return -1;
        }
        return $row['GROUP_ID'];
    }
    /**
     * Get group id associated with groupname (so groupnames better be unique)
     *
     * @param int $group_id to use to look up a group name
     * @return string group_name corresponding to the id.
     */
    public function getGroupName($group_id)
    {
        $db = $this->db;
        $sql = "SELECT G.GROUP_NAME AS GROUP_NAME FROM ".
            "GROUPS G WHERE G.GROUP_ID = ? ";
        $result = $db->execute($sql, [$group_id]);
        if (!$row = $db->fetchArray($result)) {
            return false;
        }
        return $row['GROUP_NAME'];
    }
    /**
     * Delete a group from the database and any associated data in
     * GROUP_ITEM and USER_GROUP tables.
     *
     * @param string $group_id id of the group to delete
     */
    public function deleteGroup($group_id)
    {
        $db = $this->db;
        $params = [$group_id];
        $sql = "DELETE FROM GROUPS WHERE GROUP_ID=?";
        $db->execute($sql, $params);
        $sql = "DELETE FROM GROUP_ITEM WHERE GROUP_ID=?";
        $db->execute($sql, $params);
        $sql = "DELETE FROM GROUP_PAGE WHERE GROUP_ID=?";
        $db->execute($sql, $params);
        $sql = "DELETE FROM GROUP_PAGE_HISTORY WHERE GROUP_ID=?";
        $db->execute($sql, $params);
    }
    /**
     * Return the type of the registration for a group given by $group_id
     * This says who is allowed to register for the group (i.e., is it
     *  by invitation only, by request, or anyone can join)
     *
     * @param int $group_id which group to find the type of
     * @return int the numeric code for the registration type
     */
    public function getRegisterType($group_id)
    {
        $db = $this->db;
        $groups = [];
        $sql = "SELECT REGISTER_TYPE FROM GROUPS G WHERE GROUP_ID=?";
        $result = $db->execute($sql, [$group_id]);
        if (!$result) {
            return false;
        }
        $row = $db->fetchArray($result);
        if (!$row) {
            return false;
        }
        return $row['REGISTER_TYPE'];
    }
    /**
     * Returns information about the group with id $group_id provided
     * that the requesting user $user_id has access to it
     *
     * @param int $group_id id of group to look up
     * @param int $user_id user asking for group info
     * @param bool $require_root_or_member require the $user_id to be in the
     *      group or root
     * @return array row from group table or false (if no access or doesn't
     *     exists)
     */
    public function getGroupById($group_id, $user_id,
        $require_root_or_member = false)
    {
        $db = $this->db;
        $group = $this->getRows(0, 1,
            $total_rows, [["group_id", "=", $group_id, ""]], $user_id);
        if (!is_numeric($user_id)) { //Postgres strict about types so being safe
            $user_id = C\PUBLIC_USER_ID;
        }
        $where = " WHERE ";
        $params = [":group_id" => $group_id];
        if ($user_id != C\ROOT_ID) {
            if ($require_root_or_member) {
                $where .= " (UG.USER_ID = :user_id) AND ";
            } else {
                $where .= " (UG.USER_ID = :user_id OR G.REGISTER_TYPE IN (".
                    C\PUBLIC_BROWSE_REQUEST_JOIN. ",". C\PUBLIC_JOIN .")) AND ";
            }
            $params[":user_id"] = $user_id;
        }
        $where .= " UG.GROUP_ID= :group_id".
            " AND  UG.GROUP_ID=G.GROUP_ID AND OWNER_ID = O.USER_ID";
        $sql = "SELECT G.GROUP_ID AS GROUP_ID,
            G.GROUP_NAME AS GROUP_NAME, G.OWNER_ID AS OWNER_ID,
            O.USER_NAME AS OWNER, REGISTER_TYPE, UG.STATUS AS STATUS,
            G.MEMBER_ACCESS AS MEMBER_ACCESS, G.VOTE_ACCESS AS VOTE_ACCESS,
            G.POST_LIFETIME AS POST_LIFETIME, UG.JOIN_DATE AS JOIN_DATE
            FROM GROUPS G, USERS O, USER_GROUP UG $where " .
            $db->limitOffset(1);
        $result = $db->execute($sql, $params);
        $group = false;
        if ($result) {
            $group = $db->fetchArray($result);
            if (!$group) {
                return false;
            }
            if (!$require_root_or_member) {
                $sql = "SELECT STATUS FROM USER_GROUP WHERE
                    USER_ID=? AND GROUP_ID=? ".$db->limitOffset(1);
                $params = [$user_id, $group_id];
                $result = $db->execute($sql, $params);
                if ($result) {
                    $row = $db->fetchArray($result);
                    if ($row) {
                        $group['STATUS'] = $row['STATUS'];
                    } else {
                        $group['STATUS'] = C\NOT_MEMBER_STATUS;
                    }
                } else {
                    $group['STATUS'] = C\NOT_MEMBER_STATUS;
                }
            }
        }
        if (!$group) {
            return false;
        }
        return $group;
    }
    /**
     * Get a list of all groups which user_id belongs to. Group names
     * are not localized since these are
     * created by end user admins of the search engine
     *
     * @param int $user_id to get groups for
     * @param string $filter to LIKE filter groups
     * @param array $sorts directions on how to sort the columns of the
     *      results format is column_name => direction
     * @param int $limit first user to get
     * @param int $num number of users to return
     * @return array an array of group_id, group_name pairs
     */
    public function getUserGroups($user_id, $filter, $sorts, $limit,
        $num = C\NUM_RESULTS_PER_PAGE)
    {
        $db = $this->db;
        $groups = [];
        $limit = $db->limitOffset($limit, $num);
        $like = "";
        $param_array = [$user_id];
        if ($filter != "") {
            $like = "AND G.GROUP_NAME LIKE ?";
            $param_array[] = "%".$filter."%";
        }
        $order_by = "";
        if (!empty($sorts)) {
            $sort_fields = ["GROUP_NAME", "STATUS"];
            $directions = ["ASC", "DESC"];
            foreach ($sorts as $column_name => $direction) {
                if (in_array($column_name, $sort_fields) &&
                    in_array($direction, $directions)) {
                    if (empty($order_by)) {
                        $order_by = " ORDER BY ";
                    } else {
                        $order_by .= ",";
                    }
                    $order_by .= " $column_name $direction";
                }
            }
        }
        $sql = "SELECT UG.GROUP_ID AS GROUP_ID, UG.USER_ID AS USER_ID," .
            " G.GROUP_NAME AS GROUP_NAME, UG.STATUS AS STATUS ".
            " FROM USER_GROUP UG, GROUPS G" .
            " WHERE USER_ID = ? AND UG.GROUP_ID = G.GROUP_ID $like ".
            " $order_by $limit";
        $result = $db->execute($sql, $param_array);
        $groups = [];
        while ($group = $db->fetchArray($result)) {
            $groups[] = $group;
        }
        return $groups;
    }
    /**
     * Get a count of the number of groups to which user_id belongs.
     *
     * @param int $user_id to get groups for
     * @param string $filter to LIKE filter groups
     * @return int number of groups of the filtered type for the user
     */
    public function countUserGroups($user_id, $filter="")
    {
        $db = $this->db;
        $users = [];
        $like = "";
        $param_array = [$user_id];
        if ($filter != "") {
            $like = "AND G.GROUP_NAME LIKE ?";
            $param_array[] = "%".$filter."%";
        }
        $sql = "SELECT COUNT(DISTINCT G.GROUP_ID) AS NUM ".
            " FROM USER_GROUP UG, GROUPS G".
            " WHERE UG.USER_ID = ? AND UG.GROUP_ID = G.GROUP_ID $like";
        $result = $db->execute($sql, $param_array);
        if ($result) {
            $row = $db->fetchArray($result);
        }
        return $row['NUM'];
    }
    /**
     * To update the OWNER_ID of a group
     *
     * @param string $user_id the id of the user who becomes the admin of group
     * @param string $group_id  the group id  to transfer admin privileges
     */
    public function changeOwnerGroup($user_id, $group_id)
    {
        $db = $this->db;
        $sql = "UPDATE GROUPS SET OWNER_ID=? WHERE GROUP_ID=?";
        $db->execute($sql, [$user_id, $group_id]);
    }
    /**
     * Add an allowed user to an existing group
     *
     * @param string $user_id the id of the user to add
     * @param string $group_id  the group id of the group to add the user to
     * @param int $status what should be the membership status of the added
     *      user. Should be one of ACTIVE_STATUS, INACTIVE_STATUS,
     *      SUSPENDED_STATUS, INVITED_STATUS
     */
    public function addUserGroup($user_id, $group_id, $status = C\ACTIVE_STATUS)
    {
        $join_date = time();
        $db = $this->db;
        $sql = "INSERT INTO USER_GROUP VALUES (?, ?, ?, ?)";
        $db->execute($sql, [$user_id, $group_id, $status, $join_date]);
    }
    /**
     * Checks if a user belongs to a group but is not the owner of that group
     * Such a user could be deleted from the group
     *
     * @param int $user_id which user to look up
     * @param int $group_id which group to look up for
     * @return bool where user is deletable
     */
    public function deletableUser($user_id, $group_id)
    {
        $db = $this->db;
        $sql = "SELECT COUNT(*) AS NUM FROM USER_GROUP UG, GROUPS G WHERE
            UG.USER_ID != G.OWNER_ID AND UG.USER_ID=? AND UG.GROUP_ID=?";
        $result = $db->execute($sql, [$user_id, $group_id]);
        if (!$row = $db->fetchArray($result)) {
            return false;
        }
        if ($row['NUM'] <= 0) {
            return false;
        }
        return true;
    }
    /**
     * Delete a user from a group by userid an groupid
     *
     * @param string $user_id  the userid of the user to delete
     * @param string $group_id  the group id of the group to delete
     */
    public function deleteUserGroup($user_id, $group_id)
    {
        $db = $this->db;
        $sql = "DELETE FROM USER_GROUP WHERE USER_ID=? AND GROUP_ID=?";
        $db->execute($sql, [$user_id, $group_id]);
    }
    /**
     * Returns the GROUP_FEED item with the given id
     *
     * @param int $item_id the item to get info about
     * @return array row from GROUP_FEED table
     */
    public function getGroupItem($item_id)
    {
        $db = $this->db;
        $sql = "SELECT * FROM GROUP_ITEM WHERE ID=? " . $db->limitOffset(1);
        $result = $db->execute($sql, [$item_id]);
        if (!$result) {
            return false;
        }
        $row = $db->fetchArray($result);
        return $row;
    }
    /**
     * Returns the id of the relationship between wiki pages with the given name
     *
     * @param string $relationship_type the relationship type to get id about
     * @return array row from PAGE_RELATIONSHIP table or false (if no access
     *      or doesn't exist)
     */
    public function getRelationshipId($relationship_type)
    {
        $db = $this->db;
        $sql = "SELECT ID AS RELATIONSHIP_ID FROM " .
            "PAGE_RELATIONSHIP WHERE NAME=? " . $db->limitOffset(1);
        $result = $db->execute($sql, [$relationship_type]);
        if (!$result) {
            return false;
        }
        $row = $db->fetchArray($result);
        return $row['RELATIONSHIP_ID'];
    }
    /**
     * Returns an array of user information about users who have contributed
     * to a thread or own the group a thread belongs to
     *
     * @param int $thread_id the id of the thread that want users for
     * @param int $owner_id owner of group thread belongs to
     * @param int $exclude_id an id of a user to exclude from the array
     *      returned
     * @return array user information of users following the thread
     */
    public function getThreadFollowers($thread_id, $owner_id, $exclude_id = -1)
    {
        $db = $this->db;
        $params = [$thread_id, $owner_id];
        $sql = "SELECT DISTINCT U.USER_NAME AS USER_NAME, U.EMAIL AS EMAIL ".
            "FROM GROUP_ITEM GI, USERS U ".
            "WHERE GI.PARENT_ID=? AND (GI.USER_ID=U.USER_ID OR U.USER_ID=?)";
        if ($exclude_id != -1) {
            $sql .= " AND U.USER_ID != ?";
            $params[] = $exclude_id;
        }
        $result = $db->execute($sql, $params);
        if (!$result) {
            return false;
        }
        $i = 0;
        $rows = [];
        while ($row = $db->fetchArray($result)) {
            $rows[] = $row;
        }
        return $rows;
    }
    /**
     * Creates a new group item
     *
     * @param int $parent_id thread id to use for the item
     * @param int $group_id what group the item should be added to
     * @param int $user_id of user making the post
     * @param string $title title of the group feed item
     * @param string $description actual content of the post
     * @param int $type flag saying what kind of group item this is. One of
     *      STANDARD_GROUP_ITEM, WIKI_GROUP_ITEM (used for threads discussing
     *      a wiki page)
     * @param int $post_time timstamp for when this group item was created
     *      default to the current time
     * @return int $id of item added
     */
    public function addGroupItem($parent_id, $group_id, $user_id, $title,
        $description, $type= C\STANDARD_GROUP_ITEM, $post_time = 0)
    {
        $db = $this->db;
        if ($post_time == 0) {
            $post_time = time();
        }
        $sql = "INSERT INTO GROUP_ITEM (PARENT_ID, GROUP_ID, USER_ID, TITLE,
            DESCRIPTION, PUBDATE, EDIT_DATE, TYPE) VALUES
            (?, ?, ?, ?, ?, ?, ?, ?)";
        $db->execute($sql, [$parent_id, $group_id, $user_id, $title,
            $description, $post_time, $post_time, $type]);
        $id = $db->insertID("GROUP_ITEM");
        if ($parent_id == 0) {
            $sql = "UPDATE GROUP_ITEM SET PARENT_ID=? WHERE ID=?";
            $db->execute($sql, [$id, $id]);
            ImpressionModel::initWithDb($user_id, $id, C\THREAD_IMPRESSION,
                $db);
        }
        return $id;
    }
    /**
     * Updates a group feed item's title and description. This assumes
     * the given item already exists.
     *
     * @param int $id which item to change
     * @param string $title the new title
     * @param string $description the new description
     */
    public function updateGroupItem($id, $title, $description)
    {
        $db = $this->db;
        $edit_date = time();
        $sql = "UPDATE GROUP_ITEM SET TITLE=?, DESCRIPTION=?,
            EDIT_DATE=? WHERE ID=?";
        $db->execute($sql, [$title, $description, $edit_date, $id]);
    }
    /**
     * Removes a group feed item from the GROUP_ITEM table.
     *
     * @param int $post_id of item to remove
     * @param int $user_id the id of the person trying to perform the
     *     removal. If not root, or the original creator of the item,
     *     the item won't be removed
     */
    public function deleteGroupItem($post_id, $user_id)
    {
        $db = $this->db;
        $params = [$post_id];
        if ($user_id == C\ROOT_ID) {
            $and_where = "";
        } else {
            $and_where = " AND USER_ID=?";
            $params[] = $user_id;
        }
        $sql = "DELETE FROM GROUP_ITEM WHERE ID=? $and_where";
        if ($result = $db->execute($sql, $params)) {
            $affected_rows = $db->affectedRows();
            ImpressionModel::deleteWithDb($user_id, $post_id,
                C\THREAD_IMPRESSION, $db);
            ImpressionModel::deleteWithDb(C\PUBLIC_USER_ID, $post_id,
                C\THREAD_IMPRESSION, $db);
        } else {
            $affected_rows = $db->affectedRows();
        }
        return $affected_rows;
    }
    /**
     * Gets the group feed items visible to a user with $user_id
     * and which match the supplied search criteria found in $search_array,
     * starting from the $limit'th matching item to the $limit+$num item.
     *
     * @param int $limit starting offset group item to display
     * @param int $num number of items from offset to display
     * @param array $search_array each element of this is a quadruple
     *     name of a field, what comparison to perform, a value to check,
     *     and an order (ascending/descending) to sort by
     * @param int $user_id who is making this request to determine which
     * @param int $for_group if this value is set it is a assumed
     *     that group_items are being returned for only one group
     *     and that they should be grouped by thread
     * @return array elements of which represent one group feed item
     */
    public function getGroupItems($limit = 0, $num = 100, $search_array = [],
        $user_id = C\ROOT_ID, $for_group = -1)
    {
        $db = $this->db;
        $limit = $db->limitOffset($limit, $num);
        $any_fields = ["access", "register"];
        $is_thread = false;
        foreach ($search_array as $search_item) {
            if ($search_item[0] == 'parent_id') {
                if ($search_item[2] > 0) {
                    $is_thread = true;
                }
                break;
            }
        }
        list($where, $order_by) =
            $this->searchArrayToWhereOrderClauses($search_array, $any_fields);
        $add_where = " WHERE ";
        if ($where != "") {
            $add_where = " AND ";
        }
        $user_id = $db->escapeString($user_id);
        if ($for_group > 0 || $for_group == -2) { //-2 is just_thread case)
            $non_public_where = " (UG.USER_ID='$user_id' OR ".
                " G.REGISTER_TYPE IN ('" . C\PUBLIC_JOIN . "','".
                C\PUBLIC_BROWSE_REQUEST_JOIN."') ) AND ";
            if (!$is_thread) {
                $non_public_where .=
                 "TYPE = " . C\STANDARD_GROUP_ITEM. " AND ";
            }
        } else {
            $non_public_where = " UG.USER_ID='$user_id' AND ";
        }
        $non_public_status = ($user_id != C\PUBLIC_GROUP_ID) ?
            " UG.STATUS='" . C\ACTIVE_STATUS . "' AND " : "";
        $where .= $add_where. $non_public_where .
            "GI.GROUP_ID=G.GROUP_ID AND GI.GROUP_ID=UG.GROUP_ID AND ((
            $non_public_status
            G.MEMBER_ACCESS IN ('".C\GROUP_READ."','".C\GROUP_READ_COMMENT.
            "','".C\GROUP_READ_WRITE."', '". C\GROUP_READ_WIKI ."'))OR
            (G.OWNER_ID = UG.USER_ID OR UG.USER_ID = '".C\ROOT_ID."'))";
        if ($for_group >= 0) {
            $group_by = " GROUP BY GI.PARENT_ID";
            $order_by = " ORDER BY E.PUBDATE DESC ";
            $select = "SELECT E.*, I.TITLE AS TITLE,
                I.DESCRIPTION AS DESCRIPTION,
                I.USER_ID AS USER_ID, II.USER_ID AS LAST_POSTER_ID,
                U.USER_NAME AS USER_NAME, P.USER_NAME AS LAST_POSTER,
                IIS.NUM_VIEWS AS NUM_VIEWS, IIS.FUZZY_NUM_VIEWS AS
                FUZZY_NUM_VIEWS, IIS.TMP_NUM_VIEWS AS TMP_NUM_VIEWS";
            $sub_select = "SELECT DISTINCT MIN(GI.ID) AS ID,
                MAX(GI.ID) AS LAST_ID,
                COUNT(DISTINCT GI.ID) AS NUM_POSTS, GI.PARENT_ID AS PARENT_ID,
                MIN(GI.GROUP_ID) AS GROUP_ID, MAX(GI.PUBDATE) AS PUBDATE,
                MIN(G.OWNER_ID) AS OWNER_ID,
                MIN(G.MEMBER_ACCESS) AS MEMBER_ACCESS,
                MIN(G.GROUP_NAME) AS GROUP_NAME,
                MIN(GI.PUBDATE) AS RECENT_DATE,
                MIN(GI.TYPE) AS TYPE";
            $sub_sql = "$sub_select
                FROM GROUP_ITEM GI, GROUPS G, USER_GROUP UG
                $where $group_by";
            $sql = "$select FROM ($sub_sql) E,
                GROUP_ITEM I, GROUP_ITEM II, USERS U, USERS P,
                ITEM_IMPRESSION_SUMMARY IIS
                WHERE E.ID = I.ID AND E.LAST_ID = II.ID AND
                I.USER_ID = U.USER_ID  AND IIS.ITEM_TYPE=" .
                C\THREAD_IMPRESSION . " AND IIS.USER_ID=" .
                C\PUBLIC_USER_ID . " AND
                IIS.ITEM_ID = E.PARENT_ID AND II.USER_ID = P.USER_ID AND
                IIS.UPDATE_PERIOD=" . C\FOREVER .
                " $order_by $limit";
        } else {
            $where .= " AND P.USER_ID = GI.USER_ID";
            $select = "SELECT DISTINCT GI.ID AS ID,
                GI.PARENT_ID AS PARENT_ID, GI.GROUP_ID AS GROUP_ID,
                GI.TITLE AS TITLE, GI.DESCRIPTION AS DESCRIPTION,
                GI.PUBDATE AS PUBDATE, GI.EDIT_DATE AS EDIT_DATE,
                G.OWNER_ID AS OWNER_ID,
                G.MEMBER_ACCESS AS MEMBER_ACCESS,
                G.GROUP_NAME AS GROUP_NAME, P.USER_NAME AS USER_NAME,
                P.USER_ID AS USER_ID, GI.TYPE AS TYPE, GI.UPS AS UPS,
                GI.DOWNS AS DOWNS, G.VOTE_ACCESS AS VOTE_ACCESS ";
            $sql = "$select
                FROM GROUP_ITEM GI, GROUPS G, USER_GROUP UG, USERS P
                $where $order_by $limit";
        }
        $result = $db->execute($sql);
        $i = 0;
        $read_only = ($user_id == C\PUBLIC_GROUP_ID);
        if ($read_only) {
            while ($groups[$i] = $db->fetchArray($result)) {
                $groups[$i]["MEMBER_ACCESS"] = C\GROUP_READ;
                $i++;
            }
        } else {
            while ($groups[$i] = $db->fetchArray($result)) {
                $i++;
            }
        }
        unset($groups[$i]); //last one will be null
        return $groups;
    }
    /**
     * Used to update the fuzzified view counts of a thread item.
     *
     * @param int $item_id id of the thread item to update fuzzified counts for
     * @param int $num_views current number of views for item. This value
     *  is stored in the TMP_NUM_VIEWS column to remember the last time the
     *  FUZZY_NUM_VIEWS column was updated. Only when the TMP_NUM_VIEWS column
     *  differs from the NUM_VIEWS COLUMN will this method need to be called.
     * @param int $fuzzy_num_views number of views after epsilon privacy
     *  fuzzification applied.
     */
    public function updatePrivacyViews($item_id, $num_views, $fuzzy_num_views)
    {
        $sql = "UPDATE ITEM_IMPRESSION_SUMMARY SET FUZZY_NUM_VIEWS=?,
            TMP_NUM_VIEWS=? WHERE ITEM_ID=? AND ITEM_TYPE=" .
            C\THREAD_IMPRESSION;
        $this->db->execute($sql, [$fuzzy_num_views, $num_views, $item_id]);
    }
    /**
     * Gets the number of group feed items visible to a user with $user_id
     * and which match the supplied search criteria found in $search_array
     *
     * @param array $search_array each element of this is a quadruple
     *     name of a field, what comparison to perform, a value to check,
     *     and an order (ascending/descending) to sort by
     * @param int $user_id who is making this request to determine which
     * @param int $for_group if this value is set it is a assumed
     *     that group_items are being returned for only one group
     *     and that the count desrired is over the number of threads in that
     *     group
     * @return int number of items matching the search criteria for the
     *     given user_id
     */
    public function getGroupItemCount($search_array = [], $user_id = C\ROOT_ID,
        $for_group = -1)
    {
        $db = $this->db;
        $any_fields = ["access", "register"];
        $is_thread = false;
        foreach ($search_array as $search_item) {
            if ($search_item[0] == 'parent_id') {
                if ($search_item[2] > 0) {
                    $is_thread = true;
                }
                break;
            }
        }
        list($where, $order_by) =
            $this->searchArrayToWhereOrderClauses($search_array, $any_fields);
        $add_where = " WHERE ";
        if ($where != "") {
            $add_where = " AND ";
        }
        $user_id = $db->escapeString($user_id);
        if ($for_group > 0 || $for_group == -2) { //-2 is just_thread case
            $non_public_where = " (UG.USER_ID='$user_id' OR ".
                " G.REGISTER_TYPE IN ('".C\PUBLIC_JOIN."','".
                C\PUBLIC_BROWSE_REQUEST_JOIN."') ) AND ";
            if (!$is_thread) {
                $non_public_where .=
                 "TYPE = " . C\STANDARD_GROUP_ITEM. " AND ";
            }
        } else {
            $non_public_where = " UG.USER_ID='$user_id' AND ";
        }
        $non_public_status = ($user_id != C\PUBLIC_GROUP_ID) ?
            " UG.STATUS='" . C\ACTIVE_STATUS."' AND " : "";
        $where .= $add_where. $non_public_where .
            "GI.USER_ID=P.USER_ID AND
            GI.GROUP_ID=G.GROUP_ID AND GI.GROUP_ID=UG.GROUP_ID AND ((
            $non_public_status
            G.MEMBER_ACCESS IN ('".C\GROUP_READ."','".C\GROUP_READ_COMMENT.
            "','".C\GROUP_READ_WRITE."', '" . C\GROUP_READ_WIKI . "')) OR
            (G.OWNER_ID = UG.USER_ID OR UG.USER_ID = '".C\ROOT_ID."'))";
        if ($for_group >= 0) {
            $count_col = " COUNT(DISTINCT GI.PARENT_ID) ";
        } else {
            $count_col = " COUNT(DISTINCT GI.ID) ";
        }
        $sql = "SELECT $count_col AS NUM FROM GROUP_ITEM GI, GROUPS G,
            USER_GROUP UG, USERS P $where";
        $result = $db->execute($sql);
        $row = $db->fetchArray($result);
        return $row['NUM'];
    }
    /**
     * Deletes Group Items which are older than the expiry date for posts
     * for that group
     */
    public function cullExpiredGroupItems()
    {
        $time = time();
        $sql = "DELETE FROM GROUP_ITEM WHERE ID IN (
            SELECT GI.ID AS ID FROM GROUP_ITEM GI, GROUPS G
            WHERE GI.GROUP_ID=G.GROUP_ID AND G.POST_LIFETIME > 0
            AND ($time - GI.PUBDATE) > G.POST_LIFETIME)";
        $this->db->execute($sql);
    }
    /**
     * Returns true or false depending on whether a given user has voted on
     * a given post or not
     *
     * @param int $user_id id of user to check if voted
     * @param int $post_id id of GROUP_ITEM to see if voted on
     * @return bool whether or not the user has voted on that item
     */
    public function alreadyVoted($user_id, $post_id)
    {
        $db = $this->db;
        $sql = "SELECT COUNT(*) AS NUM FROM GROUP_ITEM_VOTE WHERE USER_ID = ?
            AND ITEM_ID = ?";
        $result = $db->execute($sql, [$user_id, $post_id]);
        if (!$result) {
            return false;
        }
        $row = $db->fetchArray($result);
        if (!$row || !isset($row['NUM'])) {
            return false;
        }
        return ($row['NUM'] > 0);
    }
    /**
     * Casts one up vote by a user to a post
     *
     * @param int $user_id  id of user to cast vote for
     * @param int $post_id  id of post on which to cast vote
     */
    public function voteUp($user_id, $post_id)
    {
        $sql = "INSERT INTO GROUP_ITEM_VOTE VALUES (?, ?)";
        $this->db->execute($sql, [$user_id, $post_id]);
        $sql = "UPDATE GROUP_ITEM SET UPS = UPS + 1 WHERE ID=?";
        $this->db->execute($sql, [$post_id]);
    }
    /**
     * Casts one up vote by a user to a post
     *
     * @param int $user_id  id of user to cast vote for
     * @param int $post_id  id of post on which to cast vote
     */
    public function voteDown($user_id, $post_id)
    {
        $sql = "INSERT INTO GROUP_ITEM_VOTE VALUES (?, ?)";
        $this->db->execute($sql, [$user_id, $post_id]);
        $sql = "UPDATE GROUP_ITEM SET DOWNS = DOWNS + 1 WHERE ID=?";
        $this->db->execute($sql, [$post_id]);
    }
    /**
     * Used to add a wiki page revision by a given user to a wiki page
     * of a given name in a given group viewing the group under a given
     * language. If the page does not exist yet it, and its corresponding
     * discussion thread is created. Two pages are used for storage
     * GROUP_PAGE which contains a parsed to html version of the most recent
     * revision of a wiki page and GROUP_PAGE_HISTORY which contains non-parsed
     * versions of all revisions
     *
     * @param int $user_id identifier of who is adding this revision
     * @param int $group_id which group the wiki page revision if being done in
     * @param string $page_name title of page being revised
     * @param string $page wiki page with potential wiki mark up containing the
     *     revision
     * @param string $locale_tag locale we are adding the revision to
     * @param string $edit_comment user's reason for making the revision
     * @param string $thread_title if this is the first revision, then this
     *     should contain the title for the discussion thread about the
     *     revision
     * @param string $thread_description if this is the first revision, then
     *     this should be the body of the first post in discussion thread
     * @param string $base_address default url to be used in links
     *     on wiki page that use short syntax
     * @param array $additional_substitutions list of pairs additional wiki
     *      page rewrites to do when parsing wiki pages
     * @return int $page_id id of added or updated page
     */
    public function setPageName($user_id, $group_id, $page_name, $page,
        $locale_tag, $edit_comment, $thread_title, $thread_description,
        $base_address = "", $additional_substitutions = [])
    {
        $db = $this->db;
        $pubdate = time();
        $parser = new WikiParser($base_address, $additional_substitutions);
        $links_relationships = $parser->fetchLinks($page);
        $parsed_page = $parser->parse($page);
        if ($page_id = $this->getPageId($group_id, $page_name, $locale_tag)) {
            //can only add and use resources for a page that exists
            $parsed_page = $this->insertResourcesParsePage($group_id, $page_id,
                $locale_tag, $parsed_page);
            $sql = "UPDATE GROUP_PAGE SET PAGE=? WHERE ID = ?";
            $result = $db->execute($sql, [$parsed_page, $page_id]);
        } else {
            $discuss_thread = $this->addGroupItem(0, $group_id, $user_id,
                $thread_title, $thread_description." ".date("r", $pubdate),
                C\WIKI_GROUP_ITEM);
            $sql = "INSERT INTO GROUP_PAGE (DISCUSS_THREAD, GROUP_ID,
                TITLE, PAGE, LOCALE_TAG) VALUES (?, ?, ?, ?, ?)";
            $result = $db->execute($sql, [$discuss_thread, $group_id,
                $page_name, $parsed_page, $locale_tag]);
            $page_id = $db->insertID("GROUP_PAGE");
            ImpressionModel::initWithDb($user_id, $page_id, C\WIKI_IMPRESSION,
                $db);
            ImpressionModel::initWithDb(C\PUBLIC_USER_ID, $page_id,
                C\WIKI_IMPRESSION, $db);
        }
        $sql = "DELETE FROM GROUP_PAGE_LINK WHERE FROM_ID = ?";
        $db->execute($sql, [$page_id]);
        $template_prefix = "template:";
        if (substr($page_name, 0, strlen($template_prefix)) ==
            $template_prefix) {
            $sql = "INSERT INTO GROUP_PAGE_LINK (LINK_TYPE_ID, FROM_ID, TO_ID)".
                " VALUES (?, ?, ?)";
            $db->execute($sql, [C\WIKI_TEMPLATE_LINK, $page_id, $group_id]);
        }
        $sql = "INSERT INTO GROUP_PAGE_LINK (LINK_TYPE_ID, FROM_ID, TO_ID) ".
            "SELECT LINK_TYPE_ID, FROM_ID, ?  FROM GROUP_PAGE_PRE_LINK ".
            "WHERE TO_GROUP_ID = ? AND TO_PAGE_NAME = ?";
        $db->execute($sql, [$page_id, $group_id, $page_name]);
        $sql = "DELETE FROM GROUP_PAGE_PRE_LINK WHERE TO_GROUP_ID = ? AND " .
            "TO_PAGE_NAME = ?";
        $db->execute($sql, [$group_id, $page_name]);
        $sql = "DELETE FROM GROUP_PAGE_PRE_LINK WHERE FROM_ID = ?";
        $db->execute($sql, [$page_id]);
        $link_sql = "INSERT INTO GROUP_PAGE_LINK ".
            "(LINK_TYPE_ID, FROM_ID, TO_ID) VALUES (?, ?, ?)";
        $pre_link_sql = "INSERT INTO GROUP_PAGE_PRE_LINK ".
            "(LINK_TYPE_ID, FROM_ID, TO_GROUP_ID, TO_PAGE_NAME) ".
            " VALUES (?, ?, ?, ?)";
        foreach ($links_relationships as $links_relationship) {
            //extract link and relation type from $links_relationship
            list($link_page_name, $relationship_type) = explode('|',
                $links_relationship);
            if (!$relationship_type) {
                $relationship_id = C\WIKI_STANDARD_LINK;
            } else if (!($relationship_id = $this->getRelationshipId(
                $relationship_type))) {
                $sql = "INSERT INTO PAGE_RELATIONSHIP (NAME) VALUES (?)";
                $db->execute($sql, [$relationship_type]);
                $relationship_id = $this->getRelationshipId(
                    $relationship_type);
            }
            //get page id
            $linked_page_id = $this->getPageId($group_id, $link_page_name,
                $locale_tag);
            //insert into GROUP_PAGE_LINK values of parent and child links
            if (!$linked_page_id) {
                $db->execute($pre_link_sql, [$relationship_id, $page_id,
                    $group_id, $link_page_name]);
            } else {
                $db->execute($link_sql, [$relationship_id, $page_id,
                    $linked_page_id]);
            }
        }
        $sql = "INSERT INTO GROUP_PAGE_HISTORY (PAGE_ID, EDITOR_ID,
            GROUP_ID, TITLE, PAGE, LOCALE_TAG, PUBDATE, EDIT_COMMENT)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $result = $db->execute($sql, [$page_id, $user_id, $group_id,
            $page_name, $page, $locale_tag, $pubdate, $edit_comment]);
        return $page_id;
    }
    /**
     * Returns an array page_id => page_name of all templates for a given
     * Group for a given language
     *
     * @param int $group_id id of group to produce template map for
     * @param string $locale_tag of language to produce template map for
     * @return array page_id => page_name for each template for the given
     *  group in the provided language
     */
    public function getTemplateMap($group_id, $locale_tag)
    {
        $db = $this->db;
        $sql = "SELECT GP.ID AS ID, GP.TITLE AS PAGE_NAME
            FROM GROUP_PAGE GP, GROUP_PAGE_LINK GPL
            WHERE GPL.LINK_TYPE_ID = ? AND GPL.FROM_ID = GP.ID AND
            GP.LOCALE_TAG= ? AND GPL.TO_ID = ?";
        $result = $db->execute($sql,
            [C\WIKI_TEMPLATE_LINK, $locale_tag, $group_id]);
        $templates = [];
        if ($result) {
            while ($template = $db->fetchArray($result)) {
                $templates['t'.$template['ID']] = $template['PAGE_NAME'];
            }
        }
        return $templates;
    }
    /**
     * Looks up the page_id of a wiki page based on the group it belongs to,
     * its title, and the language it is in (these three things together
     * should uniquely fix a page).
     *
     * @param int $group_id group identifier of group wiki page belongs to
     * @param string $page_name title of wiki page to look up
     * @param string $locale_tag IANA language tag of page to lookup
     * @return mixed $page_id of page if exists, false otherwise
     */
    public function getPageId($group_id, $page_name, $locale_tag)
    {
        $db = $this->db;
        $sql = "SELECT ID FROM GROUP_PAGE WHERE GROUP_ID = ?
            AND TITLE=? AND LOCALE_TAG= ?";
        $result = $db->execute($sql, [$group_id, $page_name, $locale_tag]);
        if (!$result) {
            return false;
        }
        $row = $db->fetchArray($result);
        if ($row) {
            return $row["ID"];
        }
        return false;
    }
    /**
     * Gets all the pages that are linked to a particular wiki page
     * by providing a particular relationship type.
     *
     * @param int $page_id identifier for the current page
     * @param int $group_id group that wiki page belongs
     * @param string $page_name name of the current page (in case page didn't
     *   exist before)
     * @param string $relationship the type of relationship
           linking the wiki pages
     * @param string $limit first row we want from the result set
     * @param string $num number of rows we want starting from the
     *     first row in the result set
     * @return two arrays of elements which represent all pages linked
     *     to and from the given wiki page with a particular relationship
     */
    public function pagesLinkedWithRelationship($page_id, $group_id,
        $page_name, $relationship, $limit, $num)
    {
        $db = $this->db;
        //get data from group pre link and insert to group link
        $sql = "INSERT INTO GROUP_PAGE_LINK (LINK_TYPE_ID, FROM_ID, TO_ID) ".
            "SELECT LINK_TYPE_ID, FROM_ID, ?  FROM GROUP_PAGE_PRE_LINK ".
            "WHERE TO_GROUP_ID = ? AND TO_PAGE_NAME = ?";
        $db->execute($sql, [$page_id, $group_id, $page_name]);
        $sql = "DELETE FROM GROUP_PAGE_PRE_LINK WHERE TO_GROUP_ID = ? AND " .
            "TO_PAGE_NAME = ?";
        $db->execute($sql, [$group_id, $page_name]);
        $sql = "DELETE FROM GROUP_PAGE_PRE_LINK WHERE FROM_ID = ?";
        $db->execute($sql, [$page_id]);
        //get the count of pages that link to the given page
        $sql = "SELECT COUNT(*) AS NUM
            FROM GROUP_PAGE_LINK L, PAGE_RELATIONSHIP P
            WHERE L.TO_ID = ? AND P.NAME = ? AND (L.LINK_TYPE_ID = P.ID)";
        $result = $db->execute($sql, [$page_id, $relationship]);
        if ($result) {
            $row = $db->fetchArray($result);
            $total_to_pages = $row['NUM'];
        }
        //get the count of pages that link from the given page
        $sql = "SELECT COUNT(*) AS NUM
            FROM GROUP_PAGE_LINK L, PAGE_RELATIONSHIP P
            WHERE L.FROM_ID = ? AND P.NAME = ? AND (L.LINK_TYPE_ID = P.ID)";
        $result = $db->execute($sql, [$page_id, $relationship]);
        if ($result) {
            $row = $db->fetchArray($result);
            $total_from_pages = $row['NUM'];
        }
        //get the array of all pages linking to this page
        $pages_that_link_to = [];
        $sql = "SELECT G.TITLE AS PAGES_LINKING_TO, G.ID AS PAGE_ID
            FROM GROUP_PAGE_LINK L, GROUP_PAGE G, PAGE_RELATIONSHIP P
            WHERE L.TO_ID = ? AND P.NAME = ? AND (L.FROM_ID = G.ID)
            AND (L.LINK_TYPE_ID = P.ID)"
            .$db->limitOffset($limit, $num);
        $result = $db->execute($sql, [$page_id, $relationship]);
        if ($result) {
            while ($tmp = $db->fetchArray($result)) {
                $pages_that_link_to[] = $tmp;
            }
        }
        // get the array of all pages that link from
        $pages_that_link_from = [];
        $sql = "SELECT G.TITLE AS PAGES_LINKING_FROM, G.ID AS PAGE_ID
            FROM GROUP_PAGE_LINK L, GROUP_PAGE G, PAGE_RELATIONSHIP P
            WHERE L.FROM_ID = ? AND P.NAME = ? AND (L.TO_ID = G.ID)
            AND (L.LINK_TYPE_ID = P.ID)"
            .$db->limitOffset($limit, $num);
        $result = $db->execute($sql, [$page_id, $relationship]);
        if ($result) {
            while ( $tmp = $db->fetchArray($result)) {
                $pages_that_link_from[] = $tmp;
            }
        }
        return [$total_to_pages, $pages_that_link_to, $total_from_pages,
            $pages_that_link_from];
    }
    /**
     * Gets all the relationship types between this particular wiki page
     * and all other pages that it is linked to.
     *
     * @param int $page_id identifier for the current page
     * @param string $limit first row we want from the result set
     * @param string $num number of rows we want starting from the
     *     first row in the result set
     * @return array of relationship types which represent all relationships
     *     between the given wiki page and all other linked wiki pages
     */
    public function getRelationshipsToFromPage($page_id, $limit, $num)
    {
        $total = $this->countPageRelationships($page_id);
        $db = $this->db;
        $i = 0;
        $relationships = [];
        $sql = "SELECT DISTINCT R.NAME AS RELATIONSHIP_TYPE
            FROM PAGE_RELATIONSHIP R, GROUP_PAGE_LINK G
            where (G.FROM_ID = ? OR G.TO_ID = ?) AND
            (R.ID = G.LINK_TYPE_ID)".$db->limitOffset($limit, $num);
        $result = $db->execute($sql, [$page_id, $page_id]);
        if ($result) {
            while ($relationships[$i] = $db->fetchArray($result)) {
                $i++;
            }
            unset($relationships[$i]);
        }
        return [$total, $relationships];
    }
    /**
     * Gets if there is any page related to this particular wiki page.
     *
     * @param int $page_id identifier for the current page
     */
    public function countPageRelationships($page_id)
    {
        if (!$page_id) {
            return 0;
        }
        $db = $this->db;
        $sql = "SELECT COUNT(DISTINCT LINK_TYPE_ID) AS NUM FROM GROUP_PAGE_LINK
            WHERE TO_ID = ? OR FROM_ID = ?";
        $result = $db->execute($sql, [$page_id, $page_id]);
        if ($result) {
            $row = $db->fetchArray($result);
            $total = $row['NUM'];
        }
        return $total;
    }
    /**
     * Return the page id, page string, and discussion thread id of the
     * most recent revision of a wiki page
     *
     * @param int $group_id group identifier of group wiki page belongs to
     * @param string $name title of wiki page to look up
     * @param string $locale_tag IANA language tag of page to lookup
     * @param string $mode if "edit" we assume we are looking up the page
     *     so that it can be edited and so we return the most recent non-parsed
     *     revision of the page. Otherwise, we assume the page is meant to be
     *     read and so we return the variant of the page where wiki markup
     *     has already been replaced with HTML
     * @return array (page_id, page, discussion_id) of desired wiki page
     */
    public function getPageInfoByName($group_id, $name, $locale_tag, $mode)
    {
        $db = $this->db;
        if (in_array($mode, ['api', 'edit', 'source'])) {
            $sql = "SELECT HP.PAGE_ID AS ID, HP.PAGE AS PAGE,
                GP.DISCUSS_THREAD AS DISCUSS_THREAD FROM GROUP_PAGE GP,
                GROUP_PAGE_HISTORY HP WHERE GP.GROUP_ID = ?
                AND GP.TITLE = ? AND GP.LOCALE_TAG = ? AND HP.PAGE_ID = GP.ID
                ORDER BY HP.PUBDATE DESC ".$db->limitOffset(0, 1);
        } else {
            $sql = "SELECT ID, PAGE, DISCUSS_THREAD FROM GROUP_PAGE
                WHERE GROUP_ID = ? AND TITLE=? AND LOCALE_TAG = ?";
        }
        $result = $db->execute($sql, [$group_id, $name, $locale_tag]);
        if (!$result) {
            return false;
        }
        $row = $db->fetchArray($result);
        if (!$row) {
            return false;
        }
        return $row;
    }
    /**
     * Returns the group_id, language, and page name of a wiki page
     *     corresponding to a page discussion thread with id $page_thread_id
     * @param int $page_thread_id the id of a wiki page discussion thread
     *     to look up page info for
     * @return array (group_id, language, and page name) of that wiki page
     */
    public function getPageInfoByThread($page_thread_id)
    {
        $db = $this->db;
        $sql = "SELECT GROUP_ID, LOCALE_TAG, TITLE AS PAGE_NAME FROM GROUP_PAGE
            WHERE DISCUSS_THREAD = ?";
        $result = $db->execute($sql,  [$page_thread_id]);
        if (!$result) {
            return false;
        }
        $row = $db->fetchArray($result);
        if (!$row) {
            return false;
        }
        return $row;
    }
    /**
     * Returns the group_id, language, and page name of a wiki page
     *     corresponding to $page_id
     * @param int $page_id to look up page info for
     * @return array (group_id, language, and page name) of that wiki page
     */
    public function getPageInfoByPageId($page_id)
    {
        $db = $this->db;
        $sql = "SELECT GROUP_ID, LOCALE_TAG, TITLE AS PAGE_NAME, DISCUSS_THREAD
            AS DISCUSS_THREAD FROM GROUP_PAGE WHERE ID = ?";
        $result = $db->execute($sql, [$page_id]);
        if (!$result) {
            return false;
        }
        $row = $db->fetchArray($result);
        if (!$row) {
            return false;
        }
        return $row;
    }
    /**
     * Returns an historical revision of a wiki page
     *
     * @param int $page_id identifier of wiki page want revision for
     * @param int $pubdate timestamp of revision desired
     * @return array (id, non-parsed wiki page, page_name,
     *     discussion thread id) of page revision
     */
    public function getHistoryPage($page_id, $pubdate)
    {
        $db = $this->db;
        $sql = "SELECT HP.PAGE_ID AS ID, HP.PAGE AS PAGE, HP.TITLE AS PAGE_NAME,
            GP.DISCUSS_THREAD AS DISCUSS_THREAD FROM GROUP_PAGE GP,
            GROUP_PAGE_HISTORY HP WHERE HP.PAGE_ID = ?
            AND HP.PUBDATE=? AND HP.PAGE_ID=GP.ID";
        $result = $db->execute($sql, [$page_id, $pubdate]);
        if (!$result) {
            return false;
        }
        $row = $db->fetchArray($result);
        if (!isset($row["PAGE"])) {
            return false;
        }
        return $row;
    }
    /**
     * Returns a list of revision history info for a wiki page.
     *
     * @param int $page_id identifier for page want revision history of
     * @param int $limit first row we want from the result set
     * @param int $num number of rows we want starting from the first row
     *     in the result set
     * @return array elements of which are array with the revision date
     *     (PUBDATE), user name, page length, edit reason for the wiki pages
     *     revision
     */
    public function getPageHistoryList($page_id, $limit, $num)
    {
        $db = $this->db;
        $sql = "SELECT COUNT(*) AS TOTAL, MIN(H.TITLE) AS PAGE_NAME
            FROM GROUP_PAGE_HISTORY H, USERS U
            WHERE H.PAGE_ID = ? AND
            U.USER_ID= H.EDITOR_ID";
        $page_name = "";
        $result = $db->execute($sql, [$page_id]);
        if ($result) {
            $row = $db->fetchArray($result);
            $total = ($row) ? $row["TOTAL"] : 0;
            $page_name = ($row) ? $row["PAGE_NAME"] : "";
        }
        $pages = [];
        if ($total > 0) {
            $sql = "SELECT H.PUBDATE AS PUBDATE, U.USER_NAME AS USER_NAME,
                LENGTH(H.PAGE) AS PAGE_LEN,
                H.EDIT_COMMENT AS EDIT_REASON FROM GROUP_PAGE_HISTORY H, USERS U
                WHERE H.PAGE_ID = ? AND
                U.USER_ID= H.EDITOR_ID ORDER BY PUBDATE DESC ".
                $db->limitOffset($limit, $num);
            $result = $db->execute($sql, [$page_id]);
            $i = 0;
            if ($result) {
                while ($pages[$i] = $db->fetchArray($result)) {
                    $i++;
                }
                unset($pages[$i]); //last one will be null
            }
        }
        return [$total, $page_name, $pages];
    }
    /**
     *  Returns the folder and thumb folder associated with the resources of
     *  a wiki page.
     *
     * @param int $group_id group identifier of group wiki page belongs to
     * @param int $page_id identifier for page want folder paths for
     * @param string $sub_path file system path within the resource folder
     *      to get the folder name for
     * @param bool $create if folder doesn't exist whether to create it or not
     * @param bool $check_redirect whether to check the default group page
     *      folder for a redirect to a different folder
     * @return array (page_folder, thumb_folder)
     */
    public function getGroupPageResourcesFolders($group_id, $page_id,
        $sub_path = "", $create = false, $check_redirect = true)
    {
        $redirect_filename = "redirect.txt";
        $sub_path = ($sub_path == "/" || $sub_path == "") ? "" : "/$sub_path";
        $group_page_folder = L\crawlHash(
            "group" . $group_id. $page_id . C\AUTH_KEY);
        $old_thumb_page_folder = L\crawlHash(
            "thumb" . $group_id. $page_id . C\AUTH_KEY);
        $group_prefix = substr($group_page_folder, 0, 3);
        $old_thumb_prefix = substr($old_thumb_page_folder, 0, 3);
        $resource_path = C\APP_DIR . "/resources";
        $group_prefix_path = $resource_path."/$group_prefix";
        $old_thumb_prefix_path = $resource_path."/$old_thumb_prefix";
        $group_path = "$group_prefix_path/$group_page_folder";
        $redirect_path = $group_path . "/$redirect_filename";
        $thumb_path = "$group_prefix_path/t$group_page_folder";
        $old_thumb_path = "$old_thumb_prefix_path/$old_thumb_page_folder";
        $redirected = false;
        if (file_exists($group_path)) {
            if ($check_redirect && file_exists($redirect_path)) {
                $tmp_path = file_get_contents($redirect_path);
                if (is_dir($tmp_path)) {
                    $group_path = $tmp_path;
                    $group_hash =  L\crawlHash($group_path);
                    $group_prefix = substr($group_hash, 0, 3);
                    $group_prefix_path = $resource_path."/$group_prefix";
                    $no_redirect_thumb_path = $thumb_path;
                    $thumb_path = "$group_prefix_path/t$group_hash";
                    if (!file_exists($group_prefix_path)) {
                        L\makePath($group_prefix_path);
                    }
                    $redirected = true;
                }
            }
            if (file_exists($group_path . $sub_path) &&
                file_exists($thumb_path . $sub_path)) {
                return [$group_path . $sub_path, $thumb_path . $sub_path];
            }
        } elseif (!$create) {
            return false;
        }
        /*
            The naming convention for the thumb folder directory has evolved.
            The current version allows one to compute the thumb folder
            based on the group-page resource folder. It also satisfies
            that if a two pages have redirects to the same resource folder
            their thumb folders will be the same. The code below also
            attempts to move pre-existing thumbs folders of old yioop
            version to the new locations
         */
        if (file_exists($group_path) || L\makePath($group_path)) {
            if (!$redirected &&
                file_exists($group_path . "/$redirect_filename")) {
                $tmp_path = file_get_contents($group_path .
                    "/$redirect_filename");
                if (is_dir($tmp_path)) {
                    $group_path = $tmp_path;
                    $group_hash =  L\crawlHash($group_path);
                    $group_prefix = substr($group_hash, 0, 3);
                    $group_prefix_path = $resource_path."/$group_prefix";
                    $no_redirect_thumb_path = $thumb_path;
                    $thumb_path = "$group_prefix_path/t$group_hash";
                    if (!file_exists($group_prefix_path)) {
                        L\makePath($group_prefix_path);
                    }
                }
            }
            if (!file_exists($thumb_path)) {
                if (!empty($no_redirect_thumb_path) &&
                    file_exists($no_redirect_thumb_path)) {
                    rename($no_redirect_thumb_path, $thumb_path);
                } else if (file_exists($old_thumb_path)) {
                    rename($old_thumb_path, $thumb_path);
                }
            }
            $full_group_path = $group_path . $sub_path;
            $full_thumb_path = $thumb_path . $sub_path;
            if ((file_exists($full_group_path) || L\makePath($full_group_path))
                && (file_exists($full_thumb_path) ||
                L\makePath($full_thumb_path))) {
                return [$full_group_path, $full_thumb_path];
            }
        }
        return false;
    }
    /**
     * Given a wiki page that has been parsed to html except for wiki syntax
     * related to resource, this method add the html to include these resources
     *
     * @param int $group_id group identifier of group wiki page belongs to
     * @param int $page_id identifier for page want to parse resources for
     * @param string $locale_tag the locale of the parsed page.
     * @param string $parsed_page the parsed wiki page before resources added
     * @param string $csrf_token to prevent cross-site request forgery
     * @param string $controller name of controller (admin or group) that
     *  inserted urls should be for
     * @return string resulting html page
     */
    public function insertResourcesParsePage($group_id, $page_id, $locale_tag,
        $parsed_page, $csrf_token = "", $controller = 'admin')
    {
        $default_folders = $this->getGroupPageResourcesFolders($group_id,
            $page_id);
        if ($default_folders) {
            list($folder, $thumb_folder) = $default_folders;
        }
        if (!preg_match_all('/\(\(resource(\-?[a-z]+)?\:(.+?)\|(.+?)\)\)/ui',
            $parsed_page, $matches)) {
            return $parsed_page;
        }
        $num_matches = count($matches[0]);
        for ($i = 0; $i < $num_matches; $i++) {
            $match_string = $matches[0][$i];
            $resource_namespace_name = $matches[2][$i];
            $namespace_parts = explode(":", $resource_namespace_name);
            $sub_path = "";
            $resource_description = $matches[3][$i];
            $resource_description_parts = explode("|", $matches[3][$i]);
            if (!empty($resource_description_parts[1])) {
                $resource_description = $resource_description_parts[1];
                $sub_path = $resource_description_parts[0];
            }
            if (count($namespace_parts) > 1) {
                $current_namespace = array_shift($namespace_parts);
                $current_page_id = $this->getPageId($group_id,
                    $current_namespace, $locale_tag);
                if ($current_page_id === false || $current_page_id === null) {
                    continue;
                }
                $resource_name = implode(":", $namespace_parts);
                $current_folders = $this->getGroupPageResourcesFolders(
                    $group_id, $current_page_id);
                if ($current_folders) {
                    list($current_folder, $current_thumb_folder) =
                        $current_folders;
                } else {
                    continue;
                }
            } elseif (!$default_folders) {
                continue;
            } else {
                $resource_name = $resource_namespace_name;
                $current_page_id = $page_id;
                $current_folder = $folder;
                $current_thumb_folder = $thumb_folder;
            }
            $thumb_resource = ($matches[1][$i] == "-thumb") ? true : false;
            $current_folder  = realpath($current_folder);
            $resource_name = html_entity_decode($resource_name, ENT_QUOTES);
            $file_name = "$current_folder/$sub_path/$resource_name";
            $resource_pos = strpos($file_name, "resources");
            if ($resource_pos === false) {
                $resource_path = "resources/".substr($current_folder,
                    strrpos($current_folder, "/") + 1).
                    "/$sub_path/$resource_name";
            } else {
                $resource_path = substr($file_name, $resource_pos);
            }
            $mime_type = L\mimeType($file_name);
            $is_dir = false;
            $mime_type_parts = explode(";", $mime_type);
            $mime_type = $mime_type_parts[0];
            $resource_url = $this->getGroupPageResourceUrl($csrf_token,
                $group_id, $current_page_id, $resource_name, $sub_path);
            if (is_dir($file_name)) {
                $is_dir = true;
                $is_static = ($controller == 'static') ? true : false;
                $page_info = $this->getPageInfoByPageId($current_page_id);
                if (empty($page_info['PAGE_NAME'])) {
                    continue;
                }
                $resource_url = htmlentities(B\wikiUrl($page_info['PAGE_NAME'] ,
                    true, $controller, $group_id));
                if ($csrf_token != "") {
                    $resource_url .= "&amp;". C\CSRF_TOKEN . "=" . $csrf_token;
                } else {
                    $resource_url .= "&amp;[{token}]";
                }
            }
            $video_type_extensions = ['video/mp4' => "mp4",
                'video/ogg' => "ogv",
                'video/avi' => 'avi', 'video/quicktime' => 'mov',
                'video/x-flv' => 'flv',
                'video/x-ms-wmv' => 'wmv', 'video/webm' => 'webm',
                'application/ogg' => 'ogv'];
            if ($is_dir) {
                $new_sub_path = ($sub_path) ?
                    "$sub_path/$resource_name" : "$resource_name";
                $resource_url .= "&amp;sf=" . urlencode($new_sub_path);
                $replace_string = "<a href='$resource_url' >".
                    "$resource_description</a>";
                $parsed_page = preg_replace('/'.preg_quote($match_string, '/')
                    .'/u',  $replace_string, $parsed_page);
            } else if (($matches[1][$i] == "-link")) {
                $replace_string = "<a href='$resource_url' >".
                    "$resource_description</a>";
                $parsed_page = preg_replace('/'.preg_quote($match_string, '/')
                    .'/u',  $replace_string, $parsed_page);
            } else if (in_array($mime_type, ['image/png', 'image/gif',
                'image/jpeg', 'image/bmp', 'image/svg+xml'])) {
                if ($thumb_resource) {
                    $replace_string = "<a class='image-list' ".
                        "href='$resource_url' >".
                        "<img src='$resource_url' ".
                        " alt='$resource_description' /></a>";
                } else {
                    $replace_string = "<img src='$resource_url' ".
                        " alt='$resource_description' class='photo' />";
                }
                $parsed_page = preg_replace('/'.
                    preg_quote($match_string,'/').'/u',
                    $replace_string, $parsed_page);
            } elseif (in_array($mime_type,
                array_keys($video_type_extensions))) {
                $replace_string = "<video class='video' ".
                    "controls='controls' autoplay='autoplay' id='".
                    L\crawlHash($resource_name)."' >\n".
                    "<source src='$resource_url' type='$mime_type'/>\n";
                $multi_source_types = ["mp4", "webm", "ogg"];
                $current_extension = $video_type_extensions[$mime_type];
                $add_sources = [];
                if (!in_array($current_extension, $multi_source_types)) {
                    $add_sources = array_diff($multi_source_types,
                        [$current_extension]);
                }
                $pre_name = substr($resource_name, 0,
                    -strlen($current_extension) -1);
                foreach ($add_sources as $extension) {
                    if (file_exists("$current_folder/$pre_name.$extension")) {
                        $resource_url = $this->getGroupPageResourceUrl(
                            $csrf_token, $group_id, $current_page_id,
                            "$pre_name.$extension", $sub_path);
                        $replace_string .= "<source src='$resource_url' ".
                            "type='video/$extension'/>\n";
                    }
                }
                $replace_string .= $resource_description."\n"."</video>";
                $parsed_page = preg_replace('/'.
                    preg_quote($match_string, '/').'/u',
                    $replace_string, $parsed_page);
            } elseif (in_array($mime_type, ['audio/aiff', 'audio/basic',
                'audio/L24', 'audio/mpeg', 'audio/mpeg3', 'audio/mp4',
                'audio/ogg', 'audio/opus',
                'audio/vorbis', 'audio/vnd.rn-realaudio', 'audio/vnd.wave',
                'audio/webm'])) {
                $replace_string = "<audio controls='controls' autoplay=".
                    "'autoplay' class='audio' id='".
                    L\crawlHash($resource_name)."' >\n".
                    "<source src='$resource_url'  >\n".
                    $resource_description."\n".
                    "</audio>";
                $parsed_page = preg_replace('/'.preg_quote($match_string, '/')
                    .'/u', $replace_string, $parsed_page);
            } elseif (($mime_type =='application/epub+zip' &&
                file_exists(C\APP_DIR. "/scripts/epub.js")) ||
                ($mime_type =='application/pdf' &&
                file_exists(C\APP_DIR. "/scripts/pdf.js"))) {
                $book_name = "b" . str_replace("-", "_",
                    L\crawlHash($resource_description));
                $replace_string =
                    "<script type='text/javascript'>\n".
                    "{$book_name}_url = '" . html_entity_decode(
                    $resource_url) .
                    "';\n</script>\n".
                    "<div class='ebook-controls'>".
                    "<button onclick='previousMediaPage($book_name);'>".
                    "&lt;</button>".
                    "<input type='range' class='ebook-range' ".
                    "id='range-$book_name' ".
                    "onchange='setMediaLocationFromRange($book_name);'/>".
                    "<button onclick='nextMediaPage($book_name);'>".
                    "&gt;</button>".
                    "<span id='pos-$book_name' >-/-</span> ";
                if ($mime_type =='application/pdf') {
                $replace_string .=
                    "[<a href='javascript:rotatePdf($book_name)'>@</a>] ";
                }
                $replace_string .=
                    "[<a href='$resource_url'>+</a>]</div>";
                if ($mime_type =='application/epub+zip') {
                    $replace_string .=
                        "<div class='ebook' id='area-$book_name'></div>";
                } else {
                    $replace_string .=
                        "<canvas class='ebook' id='area-$book_name'></canvas>";
                }
                $parsed_page = preg_replace('/'.preg_quote($match_string, '/')
                    .'/u',  $replace_string, $parsed_page);
            } elseif (in_array($mime_type, ['text/html', 'application/pdf'] )) {
                $replace_string = "<iframe class='wiki-resource-object' ".
                    "src='$resource_url' >$resource_description</iframe>";
                $parsed_page = preg_replace('/'.preg_quote($match_string, '/')
                    .'/u',  $replace_string, $parsed_page);
            } elseif (substr($mime_type, 0, 4) == 'text') {
                $resource = file_get_contents($file_name);
                $replace_string = "<pre>\n".htmlentities($resource)."\n</pre>";
                $parsed_page = preg_replace('/'.preg_quote($match_string, '/')
                    .'/u',  $replace_string, $parsed_page);
            } else {
                $replace_string = "<a href='$resource_url' >".
                    "$resource_description</a>";
                $parsed_page = preg_replace('/'.preg_quote($match_string, '/')
                    .'/u',  $replace_string, $parsed_page);
            }
        }
        return $parsed_page;
    }
    /**
     * Deletes a resource (image, video, etc) associated with a wiki page or
     * group feed post belong to a group
     *
     * @param string $resource_name name of resource to delete
     * @param int $group_id group identifier of group wiki page belongs to
     * @param int $page_id identifier for page want to delete resource from
     * @param string $sub_path path to a subfolder of default resource folder
     *      if desired
     * @return bool whether the deletion was successful
     */
    public function deleteResource($resource_name, $group_id, $page_id,
        $sub_path = "")
    {
        $folders = $this->getGroupPageResourcesFolders($group_id,
            $page_id, $sub_path);
        if (!$folders) {
            return false;
        }
        list($folder, $thumb_folder) = $folders;
        $file_name = "$folder/$resource_name";
        $thumb_name = "$thumb_folder/$resource_name.jpg";
        if (file_exists($file_name)) {
            $this->db->unlinkRecursive($file_name);
        }
        if (file_exists($thumb_name)) {
            unlink($thumb_name);
        }
        return true;
    }
    /**
     * Uncompresses a compressed resource associated with a wiki page or
     * group feed post belong to a group
     *
     * @param string $resource_name name of resource to delete
     * @param int $group_id group identifier of group wiki page belongs to
     * @param int $page_id identifier for page want to delete resource from
     * @param string $sub_path path to a subfolder of default resource folder
     *      if desired
     * @return bool whether the deletion was successful
     */
    public function extractResource($resource_name, $group_id, $page_id,
        $sub_path = "")
    {
        $folders = $this->getGroupPageResourcesFolders($group_id,
            $page_id, $sub_path);
        if (!$folders) {
            return false;
        }
        list($folder) = $folders;
        $file_name = "$folder/$resource_name";
        $zip_extractor = new \ZipArchive();
        if (!$zip_extractor) {
            return false;
        }
        $zip_extractor->open($file_name);
        $zip_extractor->extractTo( $folder);
        $zip_extractor->close();
        return true;
    }
    /**
     * Deletes all resources (image, video, etc) associated with a wiki page
     * belonging to a group.
     *
     * @param int $group_id group identifier of group wiki page belongs to
     * @param int $page_id identifier for page want to delete resource from
     * @param string $sub_path path to a subfolder of default resource folder
     *      if desired
     * @return bool whether the deletion was successful
     */
    public function deleteResources($group_id, $page_id, $sub_path = "")
    {
        $folders = $this->getGroupPageResourcesFolders($group_id,
            $page_id, $sub_path);
        if (!$folders) {
            return false;
        }
        list($folder, $thumb_folder) = $folders;
        if ($folder && file_exists($folder)) {
            $this->db->unlinkRecursive($folder);
        }
        if ($thumb_folder && file_exists($thumb_folder)) {
            $this->db->unlinkRecursive($thumb_folder);
        }
        return true;
    }
    /**
     * Create a new resource in the given group and page's
     * resource folder/sub_path of the type requests.
     *
     * @param string $resource_type either new-file or new-folder
     * @param int $group_id group identifier of group wiki page belongs to
     * @param int $page_id identifier for page want to delete resource from
     * @param string $sub_path path to a subfolder of default resource folder
     *      if desired
     * @return bool whether the deletion was successful
     */
    public function newResource($resource_type, $group_id, $page_id,
        $sub_path = "")
    {
        $folders = $this->getGroupPageResourcesFolders($group_id,
            $page_id, $sub_path);
        if (!$folders) {
            return false;
        }
        list($folder, $thumb_folder) = $folders;
        if (!file_exists($folder)) {
            return false;
        }
        $i = 0;
        $base_name = ($resource_type == 'new-file') ? "untitled%d.txt" :
            "untitled_folder%d";
        do {
            $file_name = sprintf($folder."/".$base_name, $i);
            $i++;
        } while (file_exists($file_name));
        if ($resource_type == 'new-file') {
            return (file_put_contents($file_name, "") !== false);
        }
        return mkdir($file_name);
    }
    /**
     * Renames a resource (image, video, etc) associated with a wiki page
     * belonging to a group.
     *
     * @param string $old_resource_name name of resource before renaming
     * @param string $new_resource_name name of resource after renaming
     * @param int $group_id group identifier of group wiki page belongs to
     * @param int $page_id identifier for page want to delete resource from
     * @param string $sub_path path to a subfolder of default resource folder
     *      if desired
     * @return bool whether the deletion was successful
     */
    public function renameResource($old_resource_name, $new_resource_name,
        $group_id, $page_id, $sub_path = "")
    {
        $folders = $this->getGroupPageResourcesFolders($group_id,
            $page_id, $sub_path);
        if (!$folders) {
            return false;
        }
        list($folder, $thumb_folder) = $folders;
        $old_file_name = "$folder/$old_resource_name";
        $old_thumb_name = "$thumb_folder/$old_resource_name.jpg";
        if (file_exists($old_file_name)) {
            rename($old_file_name, "$folder/$new_resource_name");
        } else {
            return false;
        }
        if (file_exists($old_thumb_name)) {
            rename($old_thumb_name, "$thumb_folder/$new_resource_name.jpg");
        }
        return true;
    }
    /**
     * Moves a file that has been uploaded via a wiki pages resource form
     * to its correct position in the resources folder so it shows up for
     * that page. For images and video (if FFMPEG configued) thumbs are
     * generated. For video if FFMPEG is configured then a schedule is
     * added to the media_convert folder so that the media_updater can produce
     * mp4 and webm files corresponding to the video file.
     *
     * @param string $tmp_name tmp location that uploaded file initially stored
     *  at
     * @param string $file_name file name of file that has been uploaded
     * @param string $mime_type mime type of uploaded file
     * @param int $group_id group identifier of group wiki page belongs to
     * @param int $page_id identifier for page want copy a page resource for
     * @param string $sub_path used to specify sub-folder of default resource
     *      folder to copy to
     */
    public function copyFileToGroupPageResource($tmp_name, $file_name,
        $mime_type, $group_id, $page_id, $sub_path = "")
    {
        $folders = $this->getGroupPageResourcesFolders($group_id, $page_id,
            $sub_path, true);
        if (!$folders) {
            return false;
        }
        list($folder, $thumb_folder) = $folders;
        $file_size = filesize($tmp_name);
        if (!move_uploaded_file($tmp_name, "$folder/$file_name")) {
            return;
        }
        $this->makeThumbStripExif ($file_name, $folder, $thumb_folder,
            $mime_type);
        if (C\nsdefined('FFMPEG') && in_array($mime_type, [
            'video/mp4', 'video/webm', 'video/ogg', 'video/avi',
            'video/quicktime'])) {
            if ($file_size < C\MAX_VIDEO_CONVERT_SIZE) {
                $convert_folder = C\WORK_DIRECTORY. self::CONVERT_FOLDER;
                if (!file_exists($convert_folder) && !mkdir($convert_folder)) {
                    return;
                }
                $num_convert_files = count(glob($convert_folder. "/*.txt"));
                $video_directory =
                    $convert_folder."/". L\crawlHash($file_name) . time();
                mkdir($video_directory);
                if (!file_exists($video_directory) && !mkdir($video_directory)) {
                    return;
                }
                if (file_exists($video_directory)) {
                    $split_file = $video_directory . self::SPLIT_FILE;
                    file_put_contents($split_file, "split this!");
                    $file_info = $video_directory . self::FILE_INFO;
                    file_put_contents($file_info,
                        "$page_id\n$folder\n$thumb_folder\n$file_name");
                }
            }
        }
    }
    /**
     * Reads in and returns as a string the contents of a resource that has been
     * associated to a page.
     *
     * @param string $file_name file name of page resource desired
     * @param int $group_id group identifier of group wiki page belongs to
     * @param int $page_id identifier for page want copy a page resource for
     * @param string $sub_path subpath with the resource folder that should be
     *  used to look up filename in
     */
    public function getPageResource($file_name, $group_id, $page_id,
        $sub_path = "")
    {
        $folders = $this->getGroupPageResourcesFolders($group_id, $page_id,
            $sub_path, true);
        if (!$folders) {
            return false;
        }
        list($folder, $thumb_folder) = $folders;
        return file_get_contents("$folder/$file_name");
    }
    /**
     * Saves the string for an page resource that has been updated
     * to the appropriate folder for that wiki page.
     *
     * @param string $file_name file name of page resource desired
     * @param string $resource_data the data to be saved
     * @param int $group_id group identifier of group wiki page belongs to
     * @param int $page_id identifier for page want copy a page resource for
     * @param string $sub_path subpath with the resource folder that should be
     *  add to resource path and filename
     */
    public function setPageResource($file_name, $resource_data, $group_id,
        $page_id, $sub_path = "")
    {
        $folders = $this->getGroupPageResourcesFolders($group_id, $page_id,
            $sub_path, true);
        if (!$folders) {
            return false;
        }
        list($folder, $thumb_folder) = $folders;
        return (file_put_contents("$folder/$file_name", $resource_data)
            !== false);
    }
    /**
     * Makes a thumbnail for files of a type that thumbs can be generated for
     * and strips exif data on jpegs images (only if PHP has exif functions
     * enabled).
     *
     * @param string $file_name name of file to create thumb for
     * @param string $folder the folder in which the file lives
     * @param string $thumb_folder the folder in which to save thumbs
     * @param string $mime_type the mime type of the file
     * @return bool whether a thumb was made or not
     */
    function makeThumbStripExif ($file_name, $folder, $thumb_folder,
        $mime_type = "")
    {
        if ($mime_type == "") {
            $mime_type = L\mimeType($file_name, true);
        }
        $image_types = ['image/png', 'image/gif', 'image/jpeg',
            'image/bmp'];
        $video_types = [
            'video/mp4', 'video/webm', 'video/ogg', 'video/avi',
            'video/quicktime'];
        if (!in_array($mime_type, $image_types) && !in_array($mime_type,
            $video_types)) {
            return false;
        }
        if (in_array($mime_type, $image_types)) {
            $file_string = file_get_contents("$folder/$file_name");
            $image = @imagecreatefromstring($file_string);
            if (function_exists("exif_read_data")) {
                restore_error_handler();
                $exif = @exif_read_data("$folder/$file_name");
                set_error_handler(C\NS_LIB . "yioop_error_handler");
                if (!empty($exif['Orientation'])) {
                    switch ($exif['Orientation']) {
                        case 8:
                            $image = imagerotate($image, 90, 0);
                            break;
                        case 3:
                            $image = imagerotate($image, 180, 0);
                            break;
                        case 6:
                            $image = imagerotate($image, -90, 0);
                            break;
                    }
                }
                // writing the jpeg with strip exif data
                if (!empty($exif)) {
                    imagejpeg($image, "$folder/$file_name", 100);
                }
            }
            $thumb_string = ImageProcessor::createThumb($image);
            file_put_contents("$thumb_folder/$file_name.jpg",
                $thumb_string);
            clearstatcache("$thumb_folder/$file_name.jpg");
            return true;
        }
        if (C\nsdefined('FFMPEG') && in_array($mime_type, $video_types)) {
            $make_thumb_string =
                C\FFMPEG . " -ss 3 -i \"$folder/$file_name\"".
                " -vframes 1 -map 0:v:0" .
                " -vf \"thumbnail,scale=".C\THUMB_DIM.":".C\THUMB_DIM."\" ".
                "\"$thumb_folder/$file_name.jpg\" 2>&1";
            if (function_exists("exec")) {
                exec($make_thumb_string);
            }
            clearstatcache("$thumb_folder/$file_name.jpg");
            return true;
        }
        return false;
    }
    /**
     * Used to make a hard link from a resource in the provided folder to
     * the current clip folder
     *
     * @param array $clip_folder data about the folder to make a hard link of
     *  file resource in
     * @param string $resource_name what to link
     * @param int $group_id id of group the file resource belongs to
     * @param int $page_id id of page the file resource belongs to
     * @param string $sub_path path within the page resource folder to
     *  the folder that contains the resource to link
     */
    public function linkResourceToClipFolder($clip_folder,
        $resource_name, $group_id, $page_id, $sub_path="")
    {
        $folders = $this->getGroupPageResourcesFolders($group_id, $page_id,
            $sub_path);
        if (!$folders) {
            return false;
        }
        list($folder, ) = $folders;
        $file_path =  "$folder/$resource_name";
        if (!file_exists($file_path)) {
            return false;
        }
        if (empty($clip_folder['GROUP_ID']) || empty($clip_folder['PAGE_ID']) ||
            !isset($clip_folder['SUB_PATH']) ) {
            return false;
        }
        $folders = $this->getGroupPageResourcesFolders($clip_folder['GROUP_ID'],
            $clip_folder['PAGE_ID'], $clip_folder['SUB_PATH']);
        list($clip_folder, ) = $folders;
        if (!is_dir($clip_folder)) {
            return false;
        }
        $clip_path = "$clip_folder/$resource_name";
        return link($file_path, $clip_path);
    }
    /**
     * Gets all the urls of resources belonging to a particular groups wiki
     * page.
     *
     * @param int $group_id group identifier of group wiki page belongs to
     * @param int $page_id identifier for page want to get page resources for
     * @param string $sub_path additional path beneath the default folder used
     *      for the resource folder
     * @return array (url_prefix - prefix to apply to all urls, thum_prefix
     *      prefix to apply to a resource name to get its thumb, list of
     *      resources). Each resource is an pair (name - string
     *      file name of the resource, has_thumb a boolean as to whether the
     *      resource has a thumb)
     */
    public function getGroupPageResourceUrls($group_id, $page_id, $sub_path="")
    {
        $folders = $this->getGroupPageResourcesFolders($group_id, $page_id,
            $sub_path);
        if (!$folders) {
            return false;
        }
        list($folder, $thumb_folder) = $folders;
        $folder_len = strlen($folder) + 1;
        $thumb_len = strlen($thumb_folder) + 1;
        $pre_resources = glob(preg_quote($folder) . "/*");
        $pre_thumbs = glob(preg_quote($thumb_folder) . "/*");
        $thumbs = [];
        foreach ($pre_thumbs as $pre_thumb) {
            $thumbs[] = substr($pre_thumb, $thumb_len);
        }
        $resource_info['default_folder_writable'] = false;
        if (is_writable($folder)) {
            $resource_info['default_folder_writable'] = true;
        }
        $resource_info['url_prefix'] = C\BASE_URL .
            "?c=resource&amp;a=get&amp;f=resources".
            "&amp;g=$group_id&amp;p=$page_id";
        $resource_info['thumb_prefix'] = C\BASE_URL .
            "?c=resource&amp;a=get&amp;".
            "f=resources&amp;g=$group_id&amp;p=$page_id&amp;t=thumb";
        $sub_path = htmlentities(str_replace(".", "", $sub_path));
        if ($sub_path != "" && $sub_path != "/") {
            $resource_info['url_prefix'] .= "&amp;sf=".urlencode($sub_path);
            $resource_info['thumb_prefix'] .= "&amp;sf=".urlencode($sub_path);
        }
        $resource_info['default_thumb'] =  "resources/file-icon.png";
        $resource_info['default_editable_thumb'] =
            "resources/editable-resource.png";
        $resource_info['default_folder_thumb'] = "resources/folder.png";
        $resources = [];
        $time = time();
        foreach ($pre_resources as $pre_resource) {
            $resource = [];
            $name = substr($pre_resource, $folder_len);
            $resource['name'] = $name;
            $resource['size'] = filesize($pre_resource);
            $resource['modified'] = filemtime($pre_resource);
            $resource['has_thumb'] = false;
            $resource['is_dir'] = false;
            $resource['is_compressed'] = false;
            $resource['is_writable'] = false;
            $resource['media_type'] = false;
            if (is_dir($pre_resource)) {
                $resource['is_dir'] = true;
            } else {
                $mime_type = L\mimeType($pre_resource);
                $mime_parts = explode('/', $mime_type);
                if (in_array($mime_parts[0], ['video', 'audio'])) {
                    $resource['media_type'] = $mime_parts[0];
                }
                if (in_array($mime_type, ["application/zip"])) {
                    $resource['is_compressed'] = true;
                }
            }
            if (is_writable($pre_resource)) {
                $resource['is_writable'] = true;
            }
            if (in_array($name.".jpg", $thumbs)) {
                $resource['has_thumb'] = true;
            } else if (!$resource['is_dir'] &&
                time() < $time + C\PAGE_TIMEOUT/2) {
                $resource['has_thumb'] =
                    $this->makeThumbStripExif ($name, $folder, $thumb_folder);
            }
            $resources[] = $resource;
        }
        $resource_info['resources'] = $resources;
        return $resource_info;
    }
    /**
     * Return the url needed to get a resource of a given resource name that
     * belongs to the provided group and page.
     *
     * @param string $csrf_token a token used to prevent CSRF attacks
     * @param int $group_id group identifier of group wiki page belongs to
     * @param int $page_id identifier for page want to get page resources for
     * @param string $resource_name file name of resource
     * @param string $sub_path additional path beneath the default folder used
     *      for the resource folder
     * @return string relative url to get resource
     */
    public function getGroupPageResourceUrl($csrf_token,
        $group_id, $page_id, $resource_name, $sub_path = "")
    {
        $folders = $this->getGroupPageResourcesFolders($group_id, $page_id);
        if (!$folders) {
            return false;
        }
        list($folder, ) = $folders;
        $resource_name = urlencode($resource_name);
        $sub_path = urlencode($sub_path);
        $token_string = ($csrf_token) ? C\CSRF_TOKEN . "=" . $csrf_token :
            "[{token}]";
        if (C\REDIRECTS_ON && $csrf_token) {
            $url = C\BASE_URL ."/wd/resources/$token_string/$group_id/".
                $page_id;
            if ($sub_path) {
                $url .= "/".urlencode($sub_path)."/$resource_name";
            } else {
                $url .= "/$resource_name";
            }
        } else {
            $url = C\BASE_URL ."/?c=resource&amp;a=get&amp;f=resources".
                "&amp;g=$group_id&amp;p=$page_id&amp;n=". $resource_name;
            if (!empty($sub_path)) {
                $url .= "&amp;sf=". $sub_path;
            }
            $url .= "&amp;$token_string";
        }
        return $url;
    }
    /**
     * Returns the number of non-empty wiki pages a group has (across all
     *      locales)
     * @param int $group_id id of group to return the number of wiki pages for
     * @return int number of wiki pages for that group
     */
    public function getGroupPageCount($group_id)
    {
        $sql = "SELECT COUNT(*) AS TOTAL
            FROM GROUP_PAGE WHERE GROUP_ID = ? AND LENGTH(PAGE) > 0";
        $result = $this->db->execute($sql, [$group_id]);
        $row = $this->db->fetchArray($result);
        $total = ($row) ? $row["TOTAL"] : 0;
        return $total;
    }
    /**
     * Returns a list of applicable wiki pages of a group
     *
     * @param int $group_id of group want list of wiki pages for
     * @param string $locale_tag language want wiki page list for
     * @param string $filter string we want to filter wiki page title by
     * @param string $limit first row we want from the result set
     * @param string $num number of rows we want starting from the first row
     *     in the result set
     * @return array a pair ($total, $pages) where $total is the total number
     *     of rows that could be returned if $limit and $num not present
     *     $pages is an array each of whose elements is an array corresponding
     *     to one TITLE and the first 100 chars out of a wiki page.
     */
    public function getPageList($group_id, $locale_tag, $filter, $limit, $num)
    {
        $db = $this->db;
        $filter_parts = preg_split("/\s+/", $filter);
        $like = "";
        $params = [$group_id, $locale_tag];
        foreach ($filter_parts as $part) {
            if ($part != "") {
                $like .= " AND LOWER(TITLE) LIKE LOWER(?) ";
                $params[] = "%$part%";
            }
        }
        $sql = "SELECT COUNT(*) AS TOTAL
            FROM GROUP_PAGE WHERE GROUP_ID = ? AND
            LOCALE_TAG= ? AND LENGTH(PAGE) > 0 $like";
        $result = $db->execute($sql, $params);
        if ($result) {
            $row = $db->fetchArray($result);
        }
        $total = (isset($row) && $row) ? $row["TOTAL"] : 0;
        $pages = [];
        if ($total > 0) {
            $sql = "SELECT TITLE, PAGE AS DESCRIPTION
                FROM GROUP_PAGE WHERE GROUP_ID = ? AND
                LOCALE_TAG= ? AND LENGTH(PAGE) > 0
                $like ORDER BY LOWER(TITLE) ASC ".
                $db->limitOffset($limit, $num);
            $result = $db->execute($sql, $params);
            $i = 0;
            if ($result) {
                $seperator_len = strlen("END_HEAD_VARS");
                $stretch = (C\MOBILE) ? 5 : 9;
                $max_title_len = $stretch * C\NAME_TRUNCATE_LEN;
                while ($pages[$i] = $db->fetchArray($result)) {
                    $head_pos = strpos($pages[$i]['DESCRIPTION'],
                        "END_HEAD_VARS");
                    if ($head_pos) {
                        $head = substr($pages[$i]['DESCRIPTION'], 0, $head_pos);
                        if (preg_match('/page_type\=(.*)/', $head, $matches)) {
                            $pages[$i]['TYPE'] = $matches[1];
                            if (preg_match('/page_alias\=(.+)/', $head,
                                $matches)) {
                                $pages[$i]['ALIAS'] = $matches[1];
                            } elseif ($pages[$i]['TYPE'] == 'page_alias') {
                                $pages[$i]['TYPE'] = "standard";
                            }
                        } else {
                            $pages[$i]['TYPE'] = "standard";
                        }
                        if ($pages[$i]['TYPE'] == 'page_alias') {
                            $pages[$i]['DESCRIPTION'] =
                                $pages[$i]['ALIAS'];
                        } else {
                            $pages[$i]['DESCRIPTION'] = mb_substr(
                                $pages[$i]['DESCRIPTION'], $head_pos +
                                $seperator_len);
                        }
                    } else {
                        $pages[$i]['TYPE'] = "standard";
                    }
                    $ellipsis = (mb_strlen($pages[$i]["DESCRIPTION"]) >
                        self::MIN_SNIPPET_LENGTH) ? "..." : "";
                    $pages[$i]['DESCRIPTION'] = mb_substr(
                        $pages[$i]['DESCRIPTION'], 0,
                        self::MIN_SNIPPET_LENGTH) . $ellipsis;
                    $ellipsis = (mb_strlen($pages[$i]["TITLE"]) >
                        $max_title_len) ? "..." : "";
                    $pages[$i]["SHOW_TITLE"] = mb_substr(
                        $pages[$i]["TITLE"], 0,
                        $max_title_len) . $ellipsis;
                    $i++;
                }
                unset($pages[$i]); //last one will be null
            }
        }
        return [$total, $pages];
    }
    /**
     * Get an array of bots that belong to a group
     *
     * @param string $group_id  the group_id to get bots for
     * @return array of BOTS rows
     */
    public function getGroupBots($group_id){
        $db = $this->db;
        $sql = "SELECT CB.USER_ID, U.USER_NAME, CB.BOT_TOKEN, CB.CALLBACK_URL".
            " FROM USER_GROUP UG, USERS U, CHAT_BOT CB".
            " WHERE UG.GROUP_ID = ? AND UG.USER_ID = U.USER_ID AND" .
            " CB.USER_ID = UG.USER_ID";
        $result = $db->execute($sql,[$group_id]);
        $i = 0;
        while ($bots[$i] = $db->fetchArray($result)) {
            $i++;
        }
        unset($bots[$i]); //last one will be null
        return $bots;
    }
}
