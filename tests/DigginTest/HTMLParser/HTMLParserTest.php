<?php

namespace DigginTest\HTMLParser;

use Diggin\HTMLParser\HTMLParser;
use Diggin\HTMLParser\XHTML1TransitionalDTD;

class HTMLParserTest extends \PHPUnit_Framework_TestCase
{
    public function testParserDump()
    {
        $parser = new HTMLParser();
        $parser->setRule(XHTML1TransitionalDTD::load());
        $parser->setRoot('html', array('xmlns' => 'http://www.w3.org/1999/xhtml'));
        $parser->setGenericParent('body');
        $parser->parse($this->getUglyHTML());
        $parsed = $parser->dump();

        $this->assertStringStartsWith('<html', $parsed);
    }

    public function getUglyHTML()
    {
        return <<<'HTML'
            <body>
            <h1>AAA<pre>X</h1>
            </body>
HTML;

    }
}