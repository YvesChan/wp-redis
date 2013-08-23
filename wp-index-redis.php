<?php

/*
    Author: Jim Westergren & Jeedo Aquino
    File: index-with-redis.php
    Updated: 2012-10-25

    Refactor by YvesChan
    Updated: 2013-8-20

    This is a redis caching system for wordpress.
    see more here: www.jimwestergren.com/wordpress-with-redis-as-a-frontend-cache/

    Originally written by Jim Westergren but improved by Jeedo Aquino.

    some caching mechanics are different from jim's script which is summarized below:

    - cached pages do not expire not unless explicitly deleted or reset
    - appending a ?c=y to a url deletes the entire cache of the domain, only works when you are logged in
    - appending a ?r=y to a url deletes the cache of that url
    - submitting a comment deletes the cache of that page
    - refreshing (f5) a page deletes the cache of that page
    - includes a debug mode, stats are displayed at the bottom most part after </html>

    for setup and configuration see more here:

    www.jeedo.net/lightning-fast-wordpress-with-nginx-redis/

    use this script at your own risk. i currently use this albeit a slightly modified version
    to display a redis badge whenever a cache is displayed.

*/

// controller vars here
$debug = 1;			// set to 1 if you wish to see execution time and cache actions

$start = microtime();   // start timing page exec


// init vars & check HTTP environment
$domain = $_SERVER['HTTP_HOST'];
$url = "http://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
$array = parse_url($url);
$dkey = 'cache';
$ukey = md5($array['path']);      // filter query_string, reduce dumplicated url
// check if logged in to wp
$cookie = var_export($_COOKIE, true);
$loggedin = strpos("wordpress_logged_in", $cookie);
// check if page isn't a comment submission or reload request from client
(isset($_SERVER['HTTP_CACHE_CONTROL']) && $_SERVER['HTTP_CACHE_CONTROL'] == 'max-age=0') ? $reload = 1 : $reload = 0;
// check user-agent for robot
(stripos($_SERVER['HTTP_USER_AGENT'], "bot") || stripos($_SERVER['HTTP_USER_AGENT'], "spider")) ? $robot = 1 : $robot = 0;
// check feed
strpos($url, '/feed/') ? $feed = 1 : $feed = 0;
// check json request(for mobile device)
strpos($url, 'json=') ? $json = 1 : $json = 0;

// from wp
define('WP_USE_THEMES', true);


// conditions below will not be cached
if ($feed || $robot || $json) {
    require('./wp-blog-header.php');
    exit(0);
}


// init predis
include("predis.php");
$server = array(
    'host' => '127.0.0.1',
    'port' => 6379,
    'database' => 3
);
$redis = new Predis\Client($server);

// check if a cache of the page exists
if ($redis->hexists($dkey, $ukey)) {
    if ($reload) {
        require('./wp-blog-header.php');
        $redis->hdel($dkey, $ukey);
        $msg = 'cache of page deleted';
    }
    else {
        echo $redis->hget($dkey, $ukey);
        if (!$debug) exit(0);
        $cached = 1;
        $msg = 'this is a cache';
    }
// cache the page
} else {

    // turn on output buffering
    ob_start();
    require('./wp-blog-header.php');

    // get contents of output buffer
    $html = ob_get_contents();

    // clean output buffer
    ob_end_clean();
    echo $html;

    // Store to cache only if the page exist and is not a search result.
    if (!is_404() && !is_search()) {
        // store html contents to redis cache
        $redis->hset($dkey, $ukey, $html);
        $redis->hset('log', $ukey, $array['path']);
        $msg = 'cache is set';
    }
}

$end = microtime(); // get end execution time

// show messages if debug is enabled
if ($debug) {
    echo '<!--'.$msg.': ';
    echo t_exec($start, $end).'-->';
}


// time diff
function t_exec($start, $end) {
    $t = (getmicrotime($end) - getmicrotime($start));
    return round($t,5);
}

// get time
function getmicrotime($t) {
    list($usec, $sec) = explode(" ",$t);
    return ((float)$usec + (float)$sec);
}

?>
