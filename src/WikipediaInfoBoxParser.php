<?php

namespace JordJD\WikipediaInfoBoxParser;

use JordJD\DOFileCachePSR6\CacheItemPool;
use JordJD\WikipediaInfoBoxParser\Enums\Format;
use JordJD\WikitextParser\Parser;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use stdClass;

class WikipediaInfoBoxParser
{
    private $article;
    private $format = Format::PLAIN_TEXT;

    private $endpoint = 'https://en.wikipedia.org/w/api.php';
    private $contentQueryString = '?format=json&action=query&prop=revisions&rvprop=content&rvslots=main&titles=';
    private $linksQueryString = '?action=query&prop=links&pllimit=max&format=json&titles=';

    /** @var CacheItemPoolInterface */
    private $cache = null;

    public function __construct()
    {
        $cacheItemPool = new CacheItemPool();
        $cacheItemPool->changeConfig([
            'cacheDirectory' => __DIR__.'/../cache/',
        ]);

        $this->setCache($cacheItemPool);
    }

    public function setCache(CacheItemPoolInterface $cacheItemPool)
    {
        $this->cache = $cacheItemPool;
        return $this;
    }

    /**
     * Sets the Wikipedia article you wish to parse the info box for.
     *
     * @param mixed $article
     * @return WikipediaInfoBoxParser
     */
    public function setArticle(string $article) : WikipediaInfoBoxParser
    {
        $this->article = $article;
        return $this;
    }

    /**
     * Sets the format you wish the values to parsed into.
     *
     * @see Format
     *
     * @param mixed $format
     * @return WikipediaInfoBoxParser
     */
    public function setFormat(string $format = Format::PLAIN_TEXT) : WikipediaInfoBoxParser
    {
        $this->format = $format;
        return $this;
    }

    private function buildContentUrl()
    {
        return $this->endpoint . $this->contentQueryString . urlencode($this->article);
    }

    private function buildLinksUrl()
    {
        return $this->endpoint.$this->linksQueryString.urlencode($this->article);
    }

    /**
     * Retrieves the article, parses the content, and returns an associative array of the info box content.
     *
     * An `_categories` element will contain an array of any categories this article is a part of.
     * An `_links` element will contain an array of the articles this article links to.
     *
     * @return array
     * @throws InvalidArgumentException
     */
    public function parse() : array
    {
        $cacheKey = sha1(serialize(['infobox', $this->article, $this->format]));

        $item = $this->cache->getItem($cacheKey);

        if ($item->isHit()) {
            return $item->get();
        }

        $url = $this->buildContentUrl();

        $data = json_decode(file_get_contents($url), true);
        $pages = $data['query']['pages'];
        $page = reset($pages);
        $content = $page['revisions'][0]['slots']['main']['*'];

        preg_match_all('/{{Infobox(.*?)\R}}/sm', $content, $matches);

        $result = [];

        if (isset($matches[1]) && isset($matches[1][0])) {
            $match = $matches[1][0];

            $lines = explode("\n", $match);

            foreach($lines as $line) {
                $parsedLine = $this->parseLine($line);
                if ($parsedLine) {
                    $result[$parsedLine->key] = $parsedLine->value;
                }
            }
        }

        $result['_categories'] = $this->extractCategories($content);
        $result['_links'] = $this->getLinks();

        $item->set($result);
        $this->cache->save($item);

        return $result;
    }

    private function parseLine(string $line) : ?stdClass
    {
        $line = trim($line);
        $parts = explode('=', $line, 2);

        if (count($parts)!==2) {
            return null;
        }

        $result = new stdClass();
        $result->key = trim(str_replace('|', '', $parts[0]));
        $result->value = $this->parseValue($parts[1]);

        if (!$result->key || !$result->value) {
            return null;
        }

        return $result;
    }

    private function parseValue(string $value) : string
    {
        return (new Parser())
            ->setCache($this->cache)
            ->setWikitext($value)
            ->setFormat($this->format)
            ->parse();
    }

    private function extractCategories(string $content) : array
    {
        $categories = [];

        $lines = explode("\n", $content);

        $categoryStartStr = '[[Category:';
        $categoryEndStr = ']]';

        foreach ($lines as $line) {
            if (stripos($line, $categoryStartStr) !== 0) {
                continue;
            }
            $endPosition = stripos($line, $categoryEndStr);
            $categoryNameLength = $endPosition - strlen($categoryStartStr);
            $categoryName = substr($line, strlen($categoryStartStr), $categoryNameLength);
            $categories[] = $categoryName;
        }

        return $categories;
    }

    private function getLinks() : array
    {
        $url = $this->buildLinksUrl();

        $data = json_decode(file_get_contents($url), true);
        $pages = $data['query']['pages'];
        $page = reset($pages);

        if (!isset($page['links'])) {
            return [];
        }

        return array_map(function($link) {
            return $link['title'];
        }, $page['links']);

    }
}