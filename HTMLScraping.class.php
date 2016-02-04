<?php

/**
 * ---------------------------------------------------------------------
 * HTMLScraping class
 * ---------------------------------------------------------------------
 * PHP versions 5 (5.1.3 and later)
 * ---------------------------------------------------------------------
 * LICENSE: This source file is subject to the GNU Lesser General Public
 * License as published by the Free Software Foundation;
 * either version 2.1 of the License, or any later version
 * that is available through the world-wide-web at the following URI:
 * http://www.gnu.org/licenses/lgpl.html
 * If you did not have a copy of the GNU Lesser General Public License
 * and are unable to obtain it through the web, please write to
 * the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA
 * ---------------------------------------------------------------------
 */

require_once 'HTTP/Request.php';
require_once 'HTTP/Request/Listener.php';

/**
 * HTMLScraping class
 *
 * @version    0.2 (alpha) issued May 29, 2007
 * @author     ucb.rcdtokyo http://www.rcdtokyo.com/ucb/
 * @license    GNU LGPL v2.1+ http://www.gnu.org/licenses/lgpl.html
 */
class HTMLScraping
{
    /*
     * Default User-Agent header value.
     * @var string
     */
    public $httpUserAgent = 'Mozilla/4.0 (compatible; MSIE 5.0; PEAR HTTP_Request class)';

    /*
     * Directory where to put the cache files.
     * @var string
     */
    protected $cacheDir = '';

    /*
     * @var array
     */
    private $backup = array();

    /*
     * @var integer
     */
    private $backup_count = 0;

    /**
     * Constructor
     *
     * $cache_dir is the directory where to put the cache files.
     * Nothing will be cached at all if not specified.
     * $gc_max_lifetime is the number of seconds after which cache file will be deleted
     * on the cache clean-up. 86,400 seconds (one day) by default.
     * $gc_divisor is the denominator for calculating the probability
     * of the cache clean-up. 100 (1% chance) by default.
     *
     * @param  string  $cache_dir
     * @param  string  $gc_max_lifetime
     * @param  string  $gc_divisor
     * @return void
     */
    final public function __construct($cache_dir = '', $gc_max_lifetime = 86400, $gc_divisor = 100)
    {
        if (!empty($cache_dir) and is_dir($cache_dir)) {
            require_once 'Cache/Lite.php';
            $this->cacheDir = $cache_dir;
            /*
             * Cache_Lite::$_cacheDir must have a trailing slash.
             */
            if (strlen($this->cacheDir) -1 != strrpos($this->cacheDir, '/')) {
                $this->cacheDir .= '/';
            }
            $gc_max_lifetime = (int) $gc_max_lifetime;
            $gc_divisor = (int) $gc_divisor;
            if ($gc_max_lifetime > 0 and $gc_divisor > 0) {
                $this->clearCache($gc_max_lifetime, $gc_divisor);
            }
        }
    }

    /**
     * Return SimpleXML object
     * created from the responded entity body of the given URL.
     * Throw exception if error.
     *
     * @param  string  $url
     * @param  integer $cache_lifetime
     * @param  boolean $conditional_request
     * @param  array   $headers
     * @param  array   $post
     * @return object
     */
    final public function getXmlObject($url, $cache_lifetime = 0, $conditional_request = false, $headers = array(), $post = array())
    {
        try {
            $data = $this->getXhtml($url, $cache_lifetime, $conditional_request, $headers, $post);
        } catch (Exception $e) {
            throw $e;
        }
        /*
         * Remove default namespace.
         * This is because that SimpleXMLElement->registerXPathNamespace() may cause
         * a problem under some circumstances (confirmed with PHP 5.1.6 so far).
         * So you do not need to use SimpleXMLElement->registerXPathNamespace()
         * when you use SimpleXMLElement->xpath().
         */
        $data['body'] = preg_replace('/\sxmlns="[^"]+"/', '', $data['body']);
        /*
         * Replace every '&' with '&amp;'
         * for XML parser not to break on non-predefined entities.
         * So you may need to replace '&amp;' with '&'
         * to have the original HTML string from returned SimpleXML object.
         */
        $data['body'] = str_replace('&', '&amp;', $data['body']);
        try {
            $xml_object = @new SimpleXMLElement($data['body']);
        } catch (Exception $e) {
            throw $e;
        }
        if ($bases = $xml_object->xpath('//base[@href]')) {
            $bases[0]['href'] = $this->getAbsoluteUrl((string) $bases[0]['href'], $data['url']);
        } else {
            if (!$xml_object->head) {
                $xml_object->addChild('head');
            }
            $base = $xml_object->head->addChild('base');
            $base->addAttribute('href', $data['url']);
        }
        return $xml_object;
    }

    /**
     * Return XHTML string based on SimpleXML element.
     *
     * @param  object  $element
     * @return string
     */
    final public function dumpElement($element)
    {
        return str_replace('&amp;', '&', $element->asXML());
    }

    /**
     * Convert tag attributes of SimpleXML object to be fully qualified URL.
     *
     * @param  object  $xml_object
     * @param  array   $tags
     * @param  string  $base_url
     * @return void
     */
    final public function convertPath($xml_object, $tags, $base_url = '')
    {
        if (empty($base_url) and $bases = $xml_object->xpath('//base[@href]')) {
            $base_url = (string) $bases[0]['href'];
        }
        if (!empty($base_url) and preg_match('/^https?:\/\/[\w\-\.]+/', $base_url)) {
            foreach ($tags as $tag => $attrib) {
                if (false !== $target = $xml_object->xpath("//{$tag}[@$attrib]")) {
                    foreach ($target as $value) {
                        $value[$attrib] = $this->getAbsoluteUrl(
                            (string) $value[$attrib],
                            $base_url
                        );
                    }
                }
            }
        }
    }

    /**
     * Perform a response to If-Modified-Since/If-None-Match request if use cache.
     *
     * @param  string  $cache_id
     * @param  integer $cache_lifetime
     * @return void
     */
    final public function emulateLastModified($cache_id, $cache_lifetime)
    {
        $cache_lifetime = (int) $cache_lifetime;
        if (!empty($this->cacheDir) and $cache_lifetime > 0) {
            $cache = new Cache_Lite(array('cacheDir' => $this->cacheDir, 'lifeTime' => $cache_lifetime));
            if (false !== $mod = $cache->get("{$cache_id}-mod")) {
                $mod = unserialize($mod);
                if ((isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) and $mod['date'] <= strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']))
                    or (isset($_SERVER['HTTP_IF_NONE_MATCH']) and $mod['etag'] == $_SERVER['HTTP_IF_NONE_MATCH'])) {
                    header("$_SERVER[SERVER_PROTOCOL] 304 Not Modified");
                    header("Etag: $mod[etag]");
                    exit;
                }
            } else {
                $mod['date'] = time();
                $mod['etag'] = '"'.md5("$cache_id$mod[date]").'"';
                $cache->save(serialize($mod), "{$cache_id}-mod");
            }
            header('Last-Modified: '.gmdate(DATE_RFC1123, $mod['date']));
            header("Etag: $mod[etag]");
        }
    }

    /**
     * Return array contains formated XHTML string
     * created from the responded HTML of the given URL.
     * array[code] => HTTP status code
     * array[headers] => HTTP headers
     * array[headers] => formated XHTML string made from the entity body
     * Throw exception if error.
     *
     * @param  string  $url
     * @param  integer $cache_lifetime
     * @param  boolean $conditional_request
     * @param  array   $headers
     * @param  array   $post
     * @return array
     */
    final public function getXhtml($url, $cache_lifetime = 0, $conditional_request = false, $headers = array(), $post = array())
    {
        /*
         * \x21\x23-\x3b\x3d\x3f-\x5a\x5c\x5f\x61-\x7a\x7c\x7e
         */
        if (!preg_match('/^https?:\/\/\w[\w\-\.]+/i', $url)) {
            throw new Exception("Not a valid or fully qualified HTTP URL.");
        }
        $data = false;
        $cache_lifetime = (int) $cache_lifetime;
        $use_cache = !empty($this->cacheDir) and $cache_lifetime > 0;
        if ($use_cache) {
            $cache = new Cache_Lite(array('cacheDir' => $this->cacheDir, 'lifeTime' => $cache_lifetime));
            $params = array();
            foreach ($headers as $key => $value) {
                if (!empty($value)) {
                    $params[] = urlencode($key).'='.urlencode($value);
                }
            }
            foreach ($post as $key => $value) {
                $params[] = urlencode($key).'='.urlencode($value);
            }
            $cache_id = "$url?".implode('&', $params);
            if (false !== $data = $cache->get($cache_id)) {
                $data = unserialize($data);
            }
        }
        /*
         * Access to the URL if not cached
         * or if the cache has either Last-Modified or Etag header
         * and conditional request is specified.
         */
        if ($conditional_request and (!isset($data['headers']['last-modified']) or !isset($data['headers']['etag']))) {
            $conditional_request = false;
        }
        if (!$data or $conditional_request) {
            if (isset($data['headers']['last-modified'])
                and (!isset($headers['last-modified']) or empty($headers['last-modified']))) {
                $headers['last-modified'] = $data['headers']['last-modified'];
            }
            if (isset($data['headers']['etag'])
                and (!isset($headers['etag']) or empty($headers['etag']))) {
                $headers['etag'] = $data['headers']['etag'];
            }
            try {
                $response = $this->getHttpResponse($url, $headers, $post);
            } catch (Exception $e) {
                if (!$data) {
                    throw $e;
                }
            }
            /*
             * Use cache if the responded HTTP status code is 304.
             * If 200, format the responded HTML of the given URL to XHTML.
             */
            if (!$data or (isset($response['code']) and $response['code'] != 304)) {
                $data =& $response;
                /*
                 * If status code was 200 and Content-Type was not (X)HTML,
                 * the status code was forcibly altered to 204.
                 * @see HTTP_Request_Listener_Extended->update().
                 */
                if ($data['code'] != 200 and $data['code'] != 204) {
                    throw new Exception("Responded HTTP Status Code is $data[code].");
                } elseif (isset($data['headers']['content-type'])
                    and !preg_match('/^(?:text|application)\/x?html\b/', $data['headers']['content-type'])) {
                    throw new Exception("Responded Content-Type is {$data['headers']['content-type']}");
                } elseif (empty($data['body'])) {
                    throw new Exception("Responded entity body is empty.");
                } elseif (!preg_match('/<\w+[^>]*?>/', $data['body'], $matches)) {
                    throw new Exception("Responded entity body does not contain a markup symbol.");
                } elseif (false !== strpos($matches[0], "\x0")) {
                    throw new Exception("Responded entity body contains NULL.");
                }
                /*
                 * Remove BOM and NULLs.
                 */
                $data['body'] = preg_replace('/^\xef\xbb\xbf/', '' , $data['body']);
                $data['body'] = str_replace("\x0", '', $data['body']);
                /*
                 * Initialize the backups.
                 */
                $this->backup = array();
                $this->backup_count = 0;
                /*
                 * Removing SCRIPT and STYLE is recommended.
                 * The following substitute code will capsulate the content of the tags in CDATA.
                 * If use it, be sure that some JavaScript method such as document.write
                 * is not compliant with XHTML/XML.
                 */
                $tags = array('script', 'style');
                foreach ($tags as $tag) {
                    $data['body'] = preg_replace("/<$tag\b[^>]*?>.*?<\/$tag\b[^>]*?>/si", '' , $data['body']);
                    /*
                    $data['body'] = preg_replace_callback(
                        "/(<$tag\b[^>]*?>)(.*?)(<\/$tag\b[^>]*?>)/si",
                        create_function('$matches', '
                            $content = trim($matches[2]);
                            if (empty($content)
                                or preg_match("/^<!\[CDATA\[.*?\]\]>$/s", $content)) {
                                return $matches[0];
                            } else {
                                $content = preg_replace("/^<!-+/", "", $content);
                                $content = preg_replace("/-+>$/", "", $content);
                                $content = preg_replace("/\s*\/\/$/s", "", trim($content));
                                return "$matches[1]<![CDATA[\n$content\n]]>$matches[3]";
                            }
                        '),
                        $data['body']
                    );
                    */
                }
                /*
                 * Backup CDATA sections for later process.
                 */
                $data['body'] = preg_replace_callback(
                    '/<!\[CDATA\[.*?\]\]>/s', array($this, 'backup'), $data['body']
                );
                /*
                 * Comment section must not contain two or more adjacent hyphens.
                 */
                $data['body'] = preg_replace_callback(
                    '/<!--(.*?)-->/si',
                    create_function('$matches', '
                        return "<!-- ".preg_replace("/-{2,}/", "-", $matches[1])." -->";
                    '),
                    $data['body']
                );
                /*
                 * Backup comment sections for later process.
                 */
                $data['body'] = preg_replace_callback(
                    '/<!--.*?-->/s', array($this, 'backup'), $data['body']
                );
                /*
                 * Process tags that is potentially dangerous for XML parsers.
                 */
                $data['body'] = preg_replace_callback(
                    '/(<textarea\b[^>]*?>)(.*?)(<\/textarea\b[^>]*?>)/si',
                    create_function('$matches', '
                        return $matches[1].str_replace("<", "&lt;", $matches[2]).$matches[3];
                    '),
                    $data['body']
                );
                $data['body'] = preg_replace_callback(
                    '/<xmp\b[^>]*?>(.*?)<\/xmp\b[^>]*?>/si',
                    create_function('$matches', '
                        return "<pre>".str_replace("<", "&lt;", $matches[1])."</pre>";
                    '),
                    $data['body']
                );
                $data['body'] = preg_replace_callback(
                    '/<plaintext\b[^>]*?>(.*)$/si',
                    create_function('$matches', '
                        return "<pre>".str_replace("<", "&lt;", $matches[1])."</pre>";
                    '),
                    $data['body']
                );
                /*
                 * Remove DTD declarations, wrongly placed comments etc.
                 * This must be done before removing DOCTYPE.
                 */
                $data['body'] = preg_replace('/<!(?!DOCTYPE)[^>]*?>/si', '', $data['body']);
                /*
                 * XML and DOCTYPE declaration will be replaced.
                 */
                $data['body'] = preg_replace('/<!DOCTYPE\b[^>]*?>/si', '', $data['body']);
                $data['body'] = preg_replace('/<\?xml\b[^>]*?\?>/si', '', $data['body']);
                if (preg_match('/^\s*$/s', $data['body'])) {
                    throw new Exception('The entity body became empty after preprocessing.');
                }
                /*
                 * Detect character encoding and convert to UTF-8.
                 */
                $encoding = false;
                if (isset($data['headers']['content-type'])) {
                    $encoding = $this->getCharsetFromCType($data['headers']['content-type']);
                }
                if (!$encoding and preg_match_all('/<meta\b[^>]*?>/si', $data['body'], $matches)) {
                    foreach ($matches[0] as $value) {
                        if (strtolower($this->getAttribute('http-equiv', $value)) == 'content-type'
                            and false !== $encoding = $this->getAttribute('content', $value)) {
                            $encoding = $this->getCharsetFromCType($encoding);
                            break;
                        }
                    }
                }
                /*
                 * Use mbstring to convert character encoding if available.
                 * Otherwise use iconv (iconv may try to detect character encoding automatically).
                 * Do not trust the declared encoding and do conversion even if UTF-8.
                 */
                if (extension_loaded('mbstring')) {
                    if (!$encoding) {
                        @mb_detect_order('ASCII, JIS, UTF-8, EUC-JP, SJIS');
                        if (false === $encoding = @mb_preferred_mime_name(@mb_detect_encoding($data['body']))) {
                            throw new Exception('Failed detecting character encoding.');
                        }
                    }
                    @mb_convert_variables('UTF-8', $encoding, $data, $this->backup);
                } else {
                    if (false === $data['body'] = @iconv($encoding, 'UTF-8', $data['body'])) {
                        throw new Exception('Failed converting character encoding.');
                    }
                    foreach ($this->backup as $key => $value) {
                        if (false === $this->backup[$key] = @iconv($encoding, 'UTF-8', $value)) {
                            throw new Exception('Failed converting character encoding.');
                        }
                    }
                }
                /*
                 * Restore CDATAs and comments.
                 */
                for ($i = 0; $i < $this->backup_count; $i++) {
                    $data['body'] = str_replace("<restore count=\"$i\" />", $this->backup[$i], $data['body']);
                }
                /*
                 * Use Tidy to format HTML if available.
                 * Otherwise, use HTMLParser class (is slower and consumes much memory).
                 */
                if (extension_loaded('tidy')) {
                    $tidy = new tidy;
                    $tidy->parseString($data['body'], array('output-xhtml' => true), 'UTF8');
                    $tidy->cleanRepair();
                    $data['body'] = $tidy->html();
                } else {
                    require_once 'HTMLParser.class.php';
                    $parser = new HTMLParser;
                    $format_rule = require 'xhtml1-transitional_dtd.inc.php';
                    $parser->setRule($format_rule);
                    $parser->setRoot('html', array('xmlns' => 'http://www.w3.org/1999/xhtml'));
                    $parser->setGenericParent('body');
                    $parser->parse($data['body']);
                    $data['body'] = $parser->dump();
                }
                /*
                 * Valid XHTML DOCTYPE declaration (with DTD URI) is required
                 * for SimpleXMLElement->asXML() method to produce proper XHTML tags.
                 */
                $declarations = '<?xml version="1.0" encoding="UTF-8"?>';
                $declarations .= '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" ';
                $declarations .= '"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">';
                $data['body'] = "$declarations$data[body]";
                if ($use_cache) {
                    $cache->save(serialize($data), $cache_id);
                }
            }
        }
        return $data;
    }

    /**
     * Return array contains the response of the given URL.
     * array[code] => HTTP status code
     * array[headers] => HTTP headers
     * array[headers] => Entity body
     * Throw exception if error.
     *
     * @param  string  $url
     * @param  array   $headers
     * @param  array   $post
     * @return array
     */
    private function getHttpResponse($url, $headers = array(), $post = array())
    {
        $url = str_replace('&amp;', '&', trim($url));
        $req = new HTTP_Request($url, array('allowRedirects' => true, 'maxRedirects' => 5));
        /*
         * @see HTTP_Request_Listener_Extended
         */
        $listener = new HTTP_Request_Listener_Extended;
        $req->attach($listener);
        if (!isset($headers['user-agent'])) {
            $headers['user-agent'] = $this->httpUserAgent;
        }
        foreach ($headers as $key => $value) {
            if (!empty($value)) {
                $req->addHeader($key, $value);
            }
        }
        if (!empty($post)) {
            $req->setMethod('POST');
            foreach ($post as $key => $value) {
                $req->addPostData($key, $value);
            }
        }
        $result = $req->sendRequest();
        $is_error = false;
        if (PEAR::isError($result)) {
            $is_error = true;
            $error_message = $result->getMessage();
            /*
             * $error_message could be empty if the error was raised
             * when fsockopen() returns false in Net_Socket::connect()
             */
            if (empty($error_message)) {
                $error_message = "Failed connecting to the server.";
            /*
             * HTTP_Request raises 'Malformed response' error
             * if request path is empty (e.g. http://www.example.com).
             * This bug still exists in its automatic redirection mechanism
             * in CVS rev. 1.55 (latest as of May 18, 2007).
             */
            } elseif ($error_message == 'Malformed response.') {
                $url = $req->getURL(null);
                if (false !== $urls = @parse_url($url) and !isset($urls['path'])) {
                    $req->setURL($url);
                    $result = $req->sendRequest();
                    if (PEAR::isError($result)) {
                        $error_message = $result->getMessage();
                        if (empty($error_message)) {
                            $error_message = "Failed connecting to the server.";
                        }
                    } else {
                        $is_error = false;
                    }
                }
            }
        }
        if ($is_error) {
            throw new Exception($error_message);
        }
        return array(
            /*
             * NULL parameter of HTTP_Request->getUrl() is a dummy
             * for a bug in older version HTTP_Request.
             */
            'url' => $req->getUrl(null),
            'code' => $req->getResponseCode(),
            'headers' => $req->getResponseHeader(),
            'body' => $req->getResponseBody()
        );
    }

    /**
     * @param  string  $url
     * @param  string  $base_url
     * @return string
     */
    private function getAbsoluteUrl($url, $base_url)
    {
        if (preg_match('/^[\w\+\-\.]+:/', $url) or false === $bases = @parse_url($base_url)) {
            return $url;
        } elseif (0 === strpos($url, '/')) {
            return "$bases[scheme]://$bases[host]".(isset($bases['port'])? ":$bases[port]": '').$url;
        } else {
            if (!isset($bases['path'])) {
                $bases['path'] = '/';
            }
            return "$bases[scheme]://$bases[host]".(isset($bases['port'])? ":$bases[port]": '').
                Net_URL::resolvePath(substr($bases['path'], 0, strrpos($bases['path'], '/') +1).$url);
        }
    }

    /**
     * @param  string  $string
     * @return mixed
     */
    private function getCharsetFromCType($string)
    {
        $array = explode(';', $string);
        /* array_walk($array, create_function('$item', 'return trim($item);')); */
        if (isset($array[1])) {
            $array = explode('=', $array[1]);
            if (isset($array[1])) {
                $charset = trim($array[1]);
                if (preg_match('/^UTF-?8$/i', $charset)) {
                    return 'UTF-8';
                } elseif (function_exists('mb_preferred_mime_name')) {
                    return @mb_preferred_mime_name($charset);
                } else {
                    return $charset;
                }
            }
        }
        return false;
    }

    /**
     * @param  string  $name
     * @param  string  $string
     * @return mixed
     */
    private function getAttribute($name, $string)
    {
        $search = "'[\s\'\"]\b".$name."\b\s*=\s*([^\s\'\">]+|\'[^\']+\'|\"[^\"]+\")'si";
        if (preg_match($search, $string, $matches)) {
            return preg_replace('/^\s*[\'\"](.+)[\'\"]\s*$/s', '$1', $matches[1]);
        } else {
            return false;
        }
    }

    /**
     * @param  array   $matches
     * @return string
     */
    private function backup($matches)
    {
        $this->backup[] = $matches[0];
        $replace = "<restore count=\"{$this->backup_count}\" />";
        $this->backup_count++;
        return $replace;
    }

    /**
     * @param  integer $gc_max_lifetime
     * @param  integer $gc_divisor
     * @return void
     */
    private function clearCache($gc_max_lifetime, $gc_divisor)
    {
        if (rand(1, $gc_divisor) == 1) {
            $files = @scandir($this->cacheDir);
            foreach ($files as $file) {
                if (0 === strpos($file, 'cache_')
                    and !is_dir("{$this->cacheDir}$file")
                    and @filemtime("{$this->cacheDir}$file") < time() - $gc_max_lifetime) {
                    @unlink("{$this->cacheDir}$file");
                }
            }
        }
    }
}

class HTTP_Request_Listener_Extended extends HTTP_Request_Listener
{
    function update(&$subject, $event, $data = null)
    {
        switch ($event) {
        case 'gotHeaders':
            /*
             * Force HTTP_Request not to proceed reading the entity body
             * if the Content-Type is not acceptable.
             */
            if ($subject->_code == 200
                and isset($data['content-type'])
                and !preg_match('/^(?:text|application)\/x?html\b/', $data['content-type'])) {
                $subject->_code = 204;
            }
            break;
        }
    }
}

?>
