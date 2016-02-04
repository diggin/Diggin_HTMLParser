<?php

ini_set('include_path', '..'.PATH_SEPARATOR.ini_get('include_path'));

/**
 * ---------------------------------------------------------------------
 * Sample application of HTMLScraping class.
 * Responds well-formed XHTML created from the response of the given URL.
 * May be called from the Ajax program of test_ajax.html.
 * ---------------------------------------------------------------------
 */

if (!isset($_GET['url']) or empty($_GET['url'])) {
    header("$_SERVER[SERVER_PROTOCOL] 400 Bad Request");
    header('Content-Type: text/plain;charset=UTF-8');
    exit('The URL is not specified.');
} else {
    require_once 'HTMLScraping.class.php';
    $s = new HTMLScraping;
    try {
        $xml = $s->getXmlObject($_GET['url']);
    } catch (Exception $e) {
        header("$_SERVER[SERVER_PROTOCOL] 400 Bad Request");
        header('Content-Type: text/plain;charset=UTF-8');
        exit($e->getMessage());
    }
    $s->convertPath($xml, array('a' => 'href'));
    header('Content-Type: application/xml;charset=UTF-8');
    exit($xml->asXML());
}

?>
