<?php

require_once __DIR__ . '/../../../../vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use HAB\Pica\Record\Record;
use HAB\Pica\Reader\PicaNormReader;
use HAB\Pica\Writer\PicaXmlWriter;

define('PSI_TEMPLATE', 'http://opac.lbs-braunschweig.gbv.de/DB=2/PLAIN=Y/CHARSET=UTF8/PLAINTTLCHARSET=UTF8/PPN?PPN=%s');
define('PICA_TEMPLATE', 'http://uri.hab.de/instance/proxy/opac-de-23/%s.xml');

function terminate (Request $request, Response $response) {
    $response->prepare($request);
    $response->send();
    exit();
}

function load ($ident) {
    $content = @file_get_contents(sprintf(PSI_TEMPLATE, $ident));
    if ($content) {
        $reader = new PicaNormReader();
        $reader->open($content);
        return $reader->read();
    }
}

function transform ($sourceUri, $templateUri) {
    $source = new DOMDocument();
    if (@$source->load($sourceUri) === false) {
        return false;
    }
    $xslt = new DOMDocument();
    if (@$xslt->load($templateUri, LIBXML_NOENT) === false ) {
        return false;
    }
    $proc = new XSLTProcessor();
    if (@$proc->importStylesheet($xslt) === false) {
        return false;
    }
    $result = $proc->transformToDoc($source);
    return $result->saveXml($result->documentElement);
}

$request = Request::createFromGlobals();

$route = basename($request->server->get('REQUEST_URI'));
if (!preg_match('@^[0-9]{8}[0-9X]\.[a-z]+@', $route)) {
    $response = new Response('<h1>400 Bad Request</h1>', 400, array('Content-Type' => 'text/html'));
    terminate($request, $response);
}

list($ident, $format) = explode('.', $route);

$record = load($ident);

if (!$record) {
    $response = new Response('<h1>404 Not Found</h1>', 404, array('Content-Type' => 'text/html'));
    terminate($request, $response);
}

switch ($format) {
    case 'xml':
        $writer = new PicaXmlWriter();
        $content = $writer->write($record);
        $response = new Response($content, 200, array('Content-Type' => 'application/xml'));
        break;
    case 'rdf':
        $type = (string)$record->getFirstMatchingField('002@/00')->getNthSubfield(0, '0');
        if ($type[0] === 'T') {
            $sourceUri = sprintf(PICA_TEMPLATE, $ident);
            $templateUri = __DIR__ . '/../../../../src/xslt/pica2skos.xsl';
            $content = transform($sourceUri, $templateUri);
            if ($content) {
                $response = new Response($content, 200, array('Content-Type' => 'application/rdf+xml'));
                break;
            }
        }
        // Fall through
    default:
        $response = new Response('<h1>406 Not Acceptable</h1>', 406, array('Content-Type' => 'text/html'));
}

terminate($request, $response);