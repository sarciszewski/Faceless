<?php

/**
 * Copyright (C) 2014 www.delight.im <info@delight.im>
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
 * along with this program.  If not, see {http://www.gnu.org/licenses/}.
 */

require_once(__DIR__.'/../base.php');
require_once(__DIR__.'/../base_crypto.php');

function isTopicAccepted($actualTopic, $requestedTopics) {
    return is_null($requestedTopics) || in_array($actualTopic, $requestedTopics) || $actualTopic == '';
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // initialization
    $user = init($_GET);
    // force authentication
    $userID = auth($user['username'], $user['password'], false);
    // check if required parameters are set
    if (isset($_GET['mode']) && isset($_GET['page']) && isset($_GET['topicsList'])) {
        // prepare temporary array for messages
        $messages = array();
        // calculate the start index for paging
        $startIndex = intval($_GET['page']) * CONFIG_MESSAGES_PER_PAGE;

        // if we don't know the user's language
        if (!isset($_GET['languageISO3'])) {
            // use English as the default
            $_GET['languageISO3'] = 'ENG';
        }

        // make sure we have a valid list of accepted topics
        if ($_GET['topicsList'] == '' || !is_string($_GET['topicsList'])) {
            $topicsList = NULL;
        }
        else {
            $topicsList = explode(',', $_GET['topicsList']);
        }

        // get the messages either from the personal feed or from one's own favorites
        if ($_GET['mode'] == 'friends') {
            $items = Database::select("SELECT a.message_id, a.degree, b.color_hex, b.pattern_id, b.text_encrypted, b.message_secret, b.favorites_count, b.comments_count, b.country_iso3, b.time_published, b.user_id, b.topic, b.deleted FROM feeds AS a JOIN messages AS b ON a.message_id = b.id WHERE a.user_id = ".intval($userID)." ORDER BY b.time_published DESC LIMIT ".$startIndex.", ".CONFIG_MESSAGES_PER_PAGE);
        }
        else if ($_GET['mode'] == 'popular') {
            $items = Database::select("SELECT a.id AS message_id, IF(b.degree IS NULL, 3, b.degree) AS degree, a.color_hex, a.pattern_id, a.text_encrypted, a.message_secret, a.favorites_count, a.comments_count, a.country_iso3, a.time_published, a.user_id, a.topic, a.deleted FROM messages AS a LEFT JOIN feeds AS b ON a.id = b.message_id AND b.user_id = ".intval($userID)." WHERE a.language_iso3 = ".Database::escape($_GET['languageISO3'])." ORDER BY a.score DESC LIMIT ".$startIndex.", ".CONFIG_MESSAGES_PER_PAGE);
        }
        else if ($_GET['mode'] == 'latest') {
            $items = Database::select("SELECT a.id AS message_id, IF(b.degree IS NULL, 3, b.degree) AS degree, a.color_hex, a.pattern_id, a.text_encrypted, a.message_secret, a.favorites_count, a.comments_count, a.country_iso3, a.time_published, a.user_id, a.topic, a.deleted FROM messages AS a LEFT JOIN feeds AS b ON a.id = b.message_id AND b.user_id = ".intval($userID)." WHERE a.language_iso3 = ".Database::escape($_GET['languageISO3'])." AND a.time_published < ".time()." ORDER BY a.time_published DESC LIMIT ".$startIndex.", ".CONFIG_MESSAGES_PER_PAGE);
        }
        else if ($_GET['mode'] == 'favorites') {
            $items = Database::select("SELECT a.message_id, a.degree, b.color_hex, b.pattern_id, b.text_encrypted, b.message_secret, b.favorites_count, b.comments_count, b.country_iso3, b.time_published, b.user_id, b.topic, b.deleted FROM favorites AS a JOIN messages AS b ON a.message_id = b.id WHERE a.user_id = ".intval($userID)." ORDER BY a.time_added DESC LIMIT ".$startIndex.", ".CONFIG_MESSAGES_PER_PAGE);
        }
        else if ($_GET['mode'] == 'subscriptions') {
            $items = Database::select("SELECT a.message_id, a.degree, b.color_hex, b.pattern_id, b.text_encrypted, b.message_secret, b.favorites_count, b.comments_count, b.country_iso3, b.time_published, b.user_id, b.topic, b.deleted FROM subscriptions AS a JOIN messages AS b ON a.message_id = b.id WHERE a.user_id = ".intval($userID)." AND a.counter > 0 LIMIT ".$startIndex.", ".CONFIG_MESSAGES_PER_PAGE);
        }
        else {
            respond(array('status' => 'bad_request'));
            // prevent IDE warnings
            exit;
        }

        // return the messages
        foreach ($items as $item) {
            // apply the content filter for higher relevance through the following requirements:
            // + content has an accepted topic or
            // + content is from the <Friends> feed or
            // + content is from the <Favorites> feed or
            // + content is from the subscriptions
            // + the authenticating user is the author of the content themself
            if (isTopicAccepted($item['topic'], $topicsList) || $_GET['mode'] == 'friends' || $_GET['mode'] == 'favorites' || $_GET['mode'] == 'subscriptions' || $item['user_id'] == $userID) {
                // if the content either has not been deleted (flagged through reports) or the authenticating user is the author of the content themself
                if ($item['deleted'] == 0 || $item['user_id'] == $userID) {
                    // try to decrypt the content
                    $textDecrypted = decrypt($item['text_encrypted'], $item['message_secret']);

                    // if the content has just been successfully decrypted
                    if ($textDecrypted !== false) {
                        $messages[] = array(
                            'id' => base64_encode($item['message_id']),
                            'degree' => $item['degree'],
                            'colorHex' => $item['color_hex'],
                            'patternID' => $item['pattern_id'],
                            'text' => $textDecrypted,
                            'topic' => $item['topic'],
                            'favoritesCount' => $item['favorites_count'],
                            'commentsCount' => $item['comments_count'],
                            'countryISO3' => $item['country_iso3'],
                            'time' => $item['time_published']
                        );
                    }
                }
            }
        }

        // only if this is the first page
        if ($startIndex == 0) {
            // get the number of new subscription updates
            $subscriptionUpdates = Database::selectFirst("SELECT COUNT(*) FROM subscriptions WHERE user_id = ".intval($userID)." AND counter > 0");
            $subscriptionUpdates = isset($subscriptionUpdates['COUNT(*)']) ? $subscriptionUpdates['COUNT(*)'] : 0;
        }
        // for subsequent pages
        else {
            // don't return any number for the subscription updates
            $subscriptionUpdates = -1;
        }

        respond(array(
            'status' => 'ok',
            'messages' => $messages,
            'subscriptionUpdates' => $subscriptionUpdates
        ));
    }
    else {
        respond(array('status' => 'bad_request'));
    }
}
else {
	respond(array('status' => 'bad_request'));
}

?>