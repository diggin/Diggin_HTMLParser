<?php

ini_set('include_path', '..'.PATH_SEPARATOR.ini_get('include_path'));

/**
 * ---------------------------------------------------------------------
 * Sample application of HTMLToFeed class.
 * Responds RSS feed generated from the content of test_feed.html.
 * ---------------------------------------------------------------------
 */

require_once 'HTMLToFeed.class.php';

class HTMLToFeed_Extended extends HTMLToFeed
{
    function analyze()
    {
        /* Find out the fully-qualified URL of test_feed.html */
        $target = new Net_URL('test_feed.html');
        $url = $target->getURL();

        /* Static values */
        $this->channel = new HTMLToFeed_Channel;
        $this->channel->title = 'Sample RSS feed';
        $this->channel->link = $url;
        $this->channel->description = 'This is a sample RSS feed created from a bogus HTML.';
        $this->channel->language = 'ja';
        try {
            $xml = $this->getXmlObject($url);
        } catch (Exception $e) {
            exit($e->getMessage());
        }

        /* Retrieve and parse LI elements */
        if ($li_elements = $xml->body->ul->li) {
            $this->convertPath($li_elements, array('a' => 'href'));
            foreach ($li_elements as $li) {
                $item = new HTMLToFeed_Item;
                $item->title = (string) $li->a;
                $item->link = $item->guid = (string) $li->a['href'];
                if (preg_match('|(\d{4})/(\d{1,2})/(\d{1,2})|s', $item->title, $matches)) {
                    $item->pubDate = strtotime("$matches[1]-$matches[2]-$matches[3]");
                }
                $this->channel->items[] = $item;
            }
        }
        $this->sortMultiArray($this->channel->items, 'pubDate');
    }
}

/* Explicit assignment of the default timezone is recommended */
date_default_timezone_set('Asia/Tokyo');

/*
 * The parameter of the constructor is the directory
 * for putting the the output cache (You may change this to actually available value)
 * The parameter of getFeed() is the lifetime of the cache (in seconds).
 */
$rss = new HTMLToFeed_Extended('/tmp/');
$rss->getFeed(3600);

?>
