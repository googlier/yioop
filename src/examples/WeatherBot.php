<?php
/**
 *  SeekQuarry/Yioop --
 *  Open Source Pure PHP Search Engine, Crawler, and Indexer
 *
 *  Copyright (C) 2009 - 2017  Chris Pollett chris@pollett.org
 *
 *  LICENSE:
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 *  END LICENSE
 *
 * @author Harika Nukala harika.nukala@sjsu.edu
 * @package seek_quarry
 * @subpackage examples
 * @license http://www.gnu.org/licenses/ GPL3
 * @link http://www.seekquarry.com/
 * @copyright 2009 - 2017
 * @filesource
 */
namespace seekquarry\yioop\examples\weatherbot;

/**
 * This class demonstrates a simple Weather Chat Bot using the Yioop
 * ChatBot APIs for Yioop Discussion Groups.
 * To use this bot:
 * (1) Move this file to some folder of a web server you have access to.
 *     Denote by some_url the url of this folder. If you point your
 *     browser at this folder you should see a message that begins with:
 *     There was a configuration issue with your query.
 * (2) Create a new Yioop User.
 * (3) Under Manage Accounts, click on the lock symbol next to Account Details
 * (4) Check the Bot User check bot, click save.
 * (5) Two form variables should appear: Bot Unique Token and Bot Callback URL.
 *      Fill in a value for Bot Unique Token that matches the value set
 *      for ACCESS_TOKEN in the code within the WeatherBot class.
 *      Fill in some_url (as defined in step (1)) for the value of Bot Callback
 *      URL
 * (6) Add the the user you created in Yioop to the group that you would like
 *     the bot to service. Let the name of this user be user_name.
 * (7) Talk to your bot in yioop in this groups by commenting on an
 *     already existing thread with a message beginning with @user_name.
 */
class WeatherBot
{
    /**
     * Url of site that this bot gets weather information from
     */
    const WEATHER_URL = "http://query.yahooapis.com/v1/public/yql";
    /**
     * Token given when setting up the bot in Yioop  for callback requests
     * This bots checks that a request from a Yioop Intance  sends
     * a timestamp as well as the hash of this timestamp with the bot_token
     * and post data and that these match the expected values
     */
    const ACCESS_TOKEN = "bot_token";
    /**
     * Number of seconds that the passed timestamp can differ from the current
     * time on the WeatherBot machine.
     */
    const TIME_WINDOW = 60;
    /**
     * This is the method called to get the WeatherBot to handle an incoming
     * HTTP request, and echo a weather realted message
     */
    function processRequest()
    {
        $result = "There was a configuration issue with your query.";
        if ($this->checkBotToken() && !empty($_REQUEST['post']) &&
            !empty($_REQUEST['bot_name'])) {
            $location = filter_var($_REQUEST['post'], \FILTER_SANITIZE_STRING);
            $location = trim(mb_strtolower($location));
            $result = $this->getWeather($location);
            if (empty($result)) {
                $result = "I failed to find the weather for that location.\n".
                    "I respond to queries in the format:\n" .
                    " @{$_REQUEST['bot_name']} some_location";
            }
        }
        echo $result;
    }
    /**
     * This method is used to check a request that it comes from a site
     * that knows the bot_token in use by this WeatherBot.
     */
    function checkBotToken()
    {
        if (!empty($_REQUEST['bot_token'])) {
            $token_parts = explode("*", $_REQUEST['bot_token']);
            $post = empty($_REQUEST["post"]) ? "" : $_REQUEST["post"];
            $hash = hash("sha256", self::ACCESS_TOKEN . $token_parts[1].
                $post);
            if (isset($token_parts[1]) &&
                abs(time() - $token_parts[1]) < self::TIME_WINDOW) {
                // second check avoids timing attacks, works for > php 5.6
                if ((!function_exists('hash_equals') &&
                    $hash == $token_parts[0]) ||
                    hash_equals($hash, $token_parts[0])) {
                    return true;
                }
            }
        }
        return false;
    }
    /**
     * Get weather information about a location
     *
     * @param string $location the location to get weather updates for
     * @return string weather information
     */
    function getWeather($location)
    {
        $yql_query = "select * from weather.forecast where woeid in
            (select woeid from geo.places(1) where text='" . $location
            ."')";
        $url = self::WEATHER_URL . "?q=" .
            urlencode($yql_query) . "&format=json";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $data = curl_exec($ch);
        curl_close($ch);
        $result = @json_decode($data);
        $temp = empty($result->query->results->channel->item->condition->temp) ?
            "" : $result->query->results->channel->item->condition->temp;
        $text = empty($result->query->results->channel->item->condition->text) ?
            "" : mb_strtolower(
            $result->query->results->channel->item->condition->text);
        if (empty($temp) || empty($text)) {
            return "";
        }
        return "The weather is $temp and $text in $location.";
    }
}
$bot = new WeatherBot();
$bot->processRequest();

