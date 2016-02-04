<?php

/**
 * ---------------------------------------------------------------------
 * Sample extended (abstract) class of HTMLScraping class
 * for generating RSS feed from a HTML.
 * ---------------------------------------------------------------------
 */

require_once 'HTMLScraping.class.php';

abstract class HTMLToFeed extends HTMLScraping
{
    /**
     * Abstract method to be overridden.
     * See test_feed.php for an example.
     *
     * @return void
     */
    protected abstract function analyze();

    /**
     * Response controller.
     *
     * @param  integer $cache_lifetime
     * @param  boolean $respond_to_conditional_request
     * @param  string  $format (rss1|rss2)
     * @return void
     */
    public function getFeed($cache_lifetime = 0, $respond_to_conditional_request = true, $format = 'rss2', $format_output = false)
    {
        $cache_lifetime = (int) $cache_lifetime;
        $use_cache = !empty($this->cacheDir) and $cache_lifetime > 0;
        if ($use_cache) {
            $cache = new Cache_Lite(array('cacheDir' => $this->cacheDir, 'lifeTime' => $cache_lifetime));
            $cache_id = $_SERVER['REQUEST_URI'];
            if ($respond_to_conditional_request) {
                $this->emulateLastModified($cache_id, $cache_lifetime);
            }
        }
        if (!$use_cache or false === $feed = $cache->get($cache_id)) {
            $this->analyze();
            $doc = $this->buildFeed($format, $format_output);
            $feed = $doc->saveXML();
            if ($use_cache) {
                $cache->save($feed, $cache_id);
            }
        }
        header('Content-Type: application/xml;charset=UTF-8');
        echo $feed;
        exit;
    }

    /**
     * Return DOM object contains RSS feed data.
     *
     * @param  string  $format (rss1|rss2)
     * @param  boolean $format_output
     * @return object
     */
    protected function buildFeed($format = 'rss2', $format_output = false)
    {
        switch ($format) {
        case 'rss1':
            return $this->buildRss1($format_output);
            break;
        case 'rss2':
        default:
            return $this->buildRss2($format_output);
            break;
        }
        return $doc;
    }

    /**
     * Return DOM object contains RSS 1.0 fomat feed data.
     *
     * @param  boolean $format_output
     * @return object
     */
    protected function buildRss1($format_output = false)
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = $format_output;
        $ns = array(
            'xml' => 'http://www.w3.org/XML/1998/namespace',
            'rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
            'rss' => 'http://purl.org/rss/1.0/',
            'dc' => 'http://purl.org/dc/elements/1.1/',
            'content' => 'http://purl.org/rss/1.0/modules/content/'
        );
        $root = $doc->appendChild($doc->createElementNS($ns['rdf'], 'rdf:RDF'));
        if (!empty($this->channel->language)) {
            $root->setAttributeNS($ns['xml'], 'xml:lang', $this->channel->language);
        }
        $channel_element = $root->appendChild($doc->createElementNS($ns['rss'], 'channel'));
        $channel_element->setAttributeNS($ns['rdf'], 'rdf:about', $this->channel->link);
        $channel_element->appendChild($doc->createElementNS($ns['rss'], 'title', $this->channel->title));
        $channel_element->appendChild($doc->createElementNS($ns['rss'], 'link', $this->channel->link));
        $channel_element->appendChild($doc->createElementNS($ns['rss'], 'description', $this->channel->description));
        $channel_element->appendChild($doc->createElementNS($ns['dc'], 'dc:date', date(DATE_W3C, time())));
        if (!empty($this->channel->language)) {
            $channel_element->appendChild($doc->createElementNS($ns['dc'], 'dc:language', $this->channel->language));
        }
        if ($this->channel->image and !empty($this->channel->image->url)) {
            $image = $channel_element->appendChild($doc->createElementNS($ns['rss'], 'image'));
            $image->setAttributeNS($ns['rdf'], 'rdf:resource', $this->channel->image->url);
            $image_element = $root->appendChild($doc->createElementNS($ns['rss'], 'image'));
            $image_element->setAttributeNS($ns['rdf'], 'rdf:about', $this->channel->image->url);
            $image_element->appendChild($doc->createElementNS($ns['rss'], 'url', $this->channel->image->url));
            $image_element->appendChild($doc->createElementNS($ns['rss'], 'title',
                !empty($this->channel->image->title)? $this->channel->image->title: $this->channel->title
            ));
            $image_element->appendChild($doc->createElementNS($ns['rss'], 'link',
                !empty($this->channel->image->link)? $this->image->channel->link: $this->channel->link
            ));
        }
        $items_element = $channel_element->appendChild($doc->createElementNS($ns['rss'], 'items'));
        $rdf_seq_element = $items_element->appendChild($doc->createElementNS($ns['rdf'], 'rdf:Seq'));
        foreach ($this->channel->items as $item) {
            $rdf_li = $rdf_seq_element->appendChild($doc->createElementNS($ns['rdf'], 'rdf:li'));
            $rdf_li->setAttributeNS($ns['rdf'], 'rdf:resource', $item->guid);
            $item_element = $root->appendChild($doc->createElementNS($ns['rss'], 'item'));
            $item_element->setAttributeNS($ns['rdf'], 'rdf:about', $item->guid);
            $item_element->appendChild($doc->createElementNS($ns['rss'], 'title', $item->title));
            $item_element->appendChild($doc->createElementNS($ns['rss'], 'link', $item->link));
            if (!empty($item->description)) {
                $plaintext = strip_tags($item->description);
                if (function_exists('mb_strimwidth')) {
                    $plaintext = mb_strimwidth($plaintext, 0, 500, '...', 'UTF-8');
                }
                $description = $item_element->appendChild($doc->createElementNS($ns['rss'], 'description'));
                $description->appendChild($doc->createTextNode($plaintext));
                $content_encoded = $item_element->appendChild($doc->createElementNS($ns['content'], 'content:encoded'));
                $content_encoded->appendChild($doc->createCDATASection($item->description));
            }
            if (!empty($item->category)) {
                $item_element->appendChild($doc->createElementNS($ns['dc'], 'dc:subject', $item->category));
            }
            if (!empty($item->pubDate)) {
                $item_element->appendChild($doc->createElementNS($ns['dc'], 'dc:date', date(DATE_W3C, $item->pubDate)));
            }
        }
        return $doc;
    }

    /**
     * Return DOM object contains RSS 2.0 fomat feed data.
     *
     * @param  boolean $format_output
     * @return object
     */
    protected function buildRss2($format_output = false)
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = $format_output;
        $root = $doc->appendChild($doc->createElement('rss'));
        $root->setAttribute('version', '2.0');
        $channel_element = $root->appendChild($doc->createElement('channel'));
        $channel_element->appendChild($doc->createElement('title', $this->channel->title));
        $channel_element->appendChild($doc->createElement('link', $this->channel->link));
        $channel_element->appendChild($doc->createElement('description', $this->channel->description));
        $channel_element->appendChild($doc->createElement('lastBuildDate', date(DATE_RFC822, time())));
        $channel_element->appendChild($doc->createElement('docs', 'http://blogs.law.harvard.edu/tech/rss'));
        if (!empty($this->channel->language)) {
            $channel_element->appendChild($doc->createElement('language', $this->channel->language));
        }
        if (!empty($this->channel->copyright)) {
            $channel_element->appendChild($doc->createElement('description', $this->channel->copyright));
        }
        if ($this->channel->image and !empty($this->channel->image->url)) {
            $image_element = $channel_element->appendChild($doc->createElement('image'));
            $image_element->appendChild($doc->createElement('url', $this->channel->image->url));
            $image_element->appendChild($doc->createElement('title',
                !empty($this->channel->image->title)? $this->channel->image->title: $this->channel->title
            ));
            $image_element->appendChild($doc->createElement('link',
                !empty($this->channel->image->link)? $this->channel->image->link: $this->channel->link
            ));
        }
        foreach ($this->channel->items as $item) {
            $item_element = $channel_element->appendChild($doc->createElement('item'));
            if (!empty($item->title)) {
                $item_element->appendChild($doc->createElement('title', $item->title));
            }
            if (!empty($item->link)) {
                $item_element->appendChild($doc->createElement('link', $item->link));
            }
            if (!empty($item->description)) {
                $description = $item_element->appendChild($doc->createElement('description'));
                $description->appendChild($doc->createCDATASection($item->description));
            }
            if (!empty($item->category)) {
                $item_element->appendChild($doc->createElement('category', $item->category));
            }
            if (!empty($item->guid)) {
                $guid = $item_element->appendChild($doc->createElement('guid', $item->guid));
                $guid->setAttribute('isPermaLink', 'false');
            }
            if (!empty($item->pubDate)) {
                $item_element->appendChild($doc->createElement('pubDate', date(DATE_RFC822, $item->pubDate)));
            }
        }
        return $doc;
    }

    /**
     * Utility to sort multi-dimensional array.
     * Use this method for sorting $this->channel->items if necessary.
     *
     * @param  array   $array
     * @param  string  $sortby
     * @param  integer $sorttype (SORT_REGULAR|SORT_NUMERIC|SORT_STRING)
     * @param  integer $sortorder (SORT_ASC|SORT_DESC)
     * @return void
     */
    final protected function sortMultiArray(&$array, $sortby, $sorttype = SORT_NUMERIC, $sortorder = SORT_DESC)
    {
        $keys = array();
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $keys[$key] = $value[$sortby];
            } elseif (is_object($value)) {
                $keys[$key] = $value->$sortby;
            }
        }
        array_multisort($keys, $sorttype, $sortorder, $array);
    }
}

class HTMLToFeed_Channel
{
    public $title = '';
    public $link = '';
    public $description = '';
    public $language = '';
    public $copyright = '';
    public $image = null;
    public $items = array();
    public function __set($name, $value)
    {
    }
}

class HTMLToFeed_Item
{
    public $title = '';
    public $link = '';
    public $description = '';
    public $category = '';
    public $guid = '';
    public $pubDate = 0;
    public function __set($name, $value)
    {
    }
}

class HTMLToFeed_Image
{
    public $url = '';
    public $title = '';
    public $link = '';
    public function __set($name, $value)
    {
    }
}

?>
