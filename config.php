<?php
/**
 * This file contains the API keys needed to access the Twitter via oAuth
 *
 * Before you can use this example, you'll need to replace the two values below with your own
 * keys. To do this, go to http://twitter.com/oauth_clients and register your application.
 * Then, copy the value under the heading 'Consumer key' into TWITTER_API_KEY_PUBLIC and the
 * value from 'Consumer secret' into TWITTER_API_KEY_PRIVATE. 
 *
 */

define ('TWITTER_API_KEY_PUBLIC', '');
define ('TWITTER_API_KEY_PRIVATE', '');

if (TWITTER_API_KEY_PUBLIC === '')
    die('You need to edit config.php to add your own API keys before you can use this example');

?>
