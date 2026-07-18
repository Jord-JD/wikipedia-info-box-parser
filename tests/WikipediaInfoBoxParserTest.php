<?php

namespace JordJD\WikipediaInfoBoxParser\Tests;

use JordJD\WikipediaInfoBoxParser\Enums\Format;
use JordJD\WikipediaInfoBoxParser\WikipediaInfoBoxParser;
use PHPUnit\Framework\TestCase;

class WikipediaInfoBoxParserTest extends TestCase
{
    public function testNestedInfoboxIsBatchedAndContinuationIsCollected()
    {
        $requests = [];
        $client = function ($endpoint, $body, $headers, $timeout) use (&$requests) {
            parse_str($body, $parameters);
            $requests[] = $parameters;

            if (isset($parameters['action']) && $parameters['action'] === 'parse') {
                return json_encode(['parse' => ['text' =>
                    '<div class="mw-parser-output">'
                    .'<div class="jordjd-infobox-value" data-jordjd-key="0">PHP</div>'
                    .'<div class="jordjd-infobox-value" data-jordjd-key="1">0</div>'
                    .'<div class="jordjd-infobox-value" data-jordjd-key="2"><a href="https://php.net/?a=1">PHP = site</a></div>'
                    .'<div class="jordjd-infobox-value" data-jordjd-key="3">First<br>second label</div>'
                    .'</div>'
                ]]);
            }

            if (isset($parameters['plcontinue'])) {
                return json_encode(['query' => ['pages' => [[
                    'pageid' => 24131,
                    'title' => 'PHP',
                    'categories' => [['title' => 'Category:Programming languages']],
                    'links' => [['title' => 'Zend Engine']],
                ]]]]);
            }

            return json_encode([
                'continue' => [
                    'clcontinue' => '24131|Programming_languages',
                    'plcontinue' => '24131|0|Zend_Engine',
                    'continue' => '||revisions',
                ],
                'query' => ['pages' => [[
                    'pageid' => 24131,
                    'title' => 'PHP',
                    'revisions' => [['slots' => ['main' => ['content' => $this->infoboxWikitext()]]]],
                    'categories' => [['title' => 'Category:PHP']],
                    'links' => [['title' => 'Rasmus Lerdorf']],
                ]]],
            ]);
        };

        $parser = (new WikipediaInfoBoxParser(null, $client, 8, 'php:test:v1'))
            ->disableCache()
            ->setArticle('PHP');
        $result = $parser->parse();

        $this->assertSame('PHP', $result['name']);
        $this->assertSame('0', $result['zero']);
        $this->assertSame('PHP = site', $result['nested']);
        $this->assertSame("First\nsecond label", $result['multiline']);
        $this->assertSame(['PHP', 'Programming languages'], $result['_categories']);
        $this->assertSame(['Rasmus Lerdorf', 'Zend Engine'], $result['_links']);
        $this->assertSame(24131, $parser->getPageId());
        $this->assertSame('PHP', $parser->getResolvedTitle());

        $parseRequests = array_filter($requests, function ($request) {
            return isset($request['action']) && $request['action'] === 'parse';
        });
        $this->assertCount(1, $parseRequests);
        $this->assertCount(3, $requests);
        $this->assertSame('1', (string) $requests[0]['redirects']);
    }

    public function testHtmlOutputReturnsFieldInnerHtml()
    {
        $client = $this->singlePageClient(
            '{{Infobox|name=[[PHP]]}}',
            '<div class="jordjd-infobox-value" data-jordjd-key="0"><a href="/wiki/PHP">PHP</a></div>'
        );

        $result = (new WikipediaInfoBoxParser(null, $client))
            ->disableCache()
            ->setArticle('PHP')
            ->setFormat(Format::HTML)
            ->parse();

        $this->assertSame('<a href="/wiki/PHP">PHP</a>', $result['name']);
    }

    public function testMissingAndUnbalancedArticlesFailClearly()
    {
        $missing = function () {
            return '{"query":{"pages":[{"ns":0,"title":"Missing","missing":true}]}}';
        };

        try {
            (new WikipediaInfoBoxParser(null, $missing))->disableCache()->setArticle('Missing')->parse();
            $this->fail('Missing articles should throw.');
        } catch (\RuntimeException $exception) {
            $this->assertTrue(strpos($exception->getMessage(), 'not found') !== false);
        }

        $unbalanced = $this->singlePageClient('{{Infobox|name={{Nested}}', '');
        try {
            (new WikipediaInfoBoxParser(null, $unbalanced))->disableCache()->setArticle('Bad')->parse();
            $this->fail('Unbalanced infoboxes should throw.');
        } catch (\RuntimeException $exception) {
            $this->assertTrue(strpos($exception->getMessage(), 'unbalanced') !== false);
        }
    }

    public function testInvalidConfigurationFailsClearly()
    {
        $parser = new WikipediaInfoBoxParser(null, function () { return '{}'; });
        $cases = [
            function () use ($parser) { $parser->parse(); },
            function () use ($parser) { $parser->setArticle(' '); },
            function () use ($parser) { $parser->setFormat('markdown'); },
            function () use ($parser) { $parser->setTemplateName('{{bad}}'); },
            function () use ($parser) { $parser->setCacheTtl(0); },
            function () use ($parser) { $parser->setEndpoint('file:///etc/passwd'); },
        ];

        foreach ($cases as $case) {
            try {
                $case();
                $this->fail('Invalid configuration should throw.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            } catch (\LogicException $exception) {
                $this->assertTrue(strpos($exception->getMessage(), 'Set an article') !== false);
            }
        }
    }

    public function testApiFailuresAndIncompleteRenderedValuesFailClearly()
    {
        $apiError = function () {
            return '{"error":{"code":"badtitle","info":"Bad title"}}';
        };

        try {
            (new WikipediaInfoBoxParser(null, $apiError))->disableCache()->setArticle('Bad')->parse();
            $this->fail('API errors should throw.');
        } catch (\RuntimeException $exception) {
            $this->assertTrue(strpos($exception->getMessage(), 'badtitle') !== false);
        }

        $incomplete = $this->singlePageClient('{{Infobox|first=One|second=Two}}',
            '<div class="jordjd-infobox-value" data-jordjd-key="0">One</div>'
        );

        try {
            (new WikipediaInfoBoxParser(null, $incomplete))->disableCache()->setArticle('Incomplete')->parse();
            $this->fail('Incomplete rendered values should throw.');
        } catch (\RuntimeException $exception) {
            $this->assertTrue(strpos($exception->getMessage(), 'every parsed infobox value') !== false);
        }
    }

    private function infoboxWikitext()
    {
        return "{{Infobox programming language\n"
            ."| name = PHP\n"
            ."| zero = 0\n"
            ."| nested = {{URL|https://php.net/?a=1|PHP = site}}\n"
            ."| multiline = First\nsecond [[Link|label]]\n"
            ."| empty =\n"
            ."}}\nArticle body";
    }

    private function singlePageClient($wikitext, $parsedHtml)
    {
        return function ($endpoint, $body) use ($wikitext, $parsedHtml) {
            parse_str($body, $parameters);

            if (isset($parameters['action']) && $parameters['action'] === 'parse') {
                return json_encode(['parse' => ['text' => $parsedHtml]]);
            }

            return json_encode(['query' => ['pages' => [[
                'pageid' => 1,
                'title' => 'Example',
                'revisions' => [['slots' => ['main' => ['content' => $wikitext]]]],
            ]]]]);
        };
    }
}
