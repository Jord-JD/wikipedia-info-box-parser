<?php

namespace JordJD\WikipediaInfoBoxParser;

use JordJD\DOFileCachePSR6\CacheItemPool;
use JordJD\WikipediaInfoBoxParser\Enums\Format;
use JordJD\WikitextParser\Enums\Format as WikitextFormat;
use JordJD\WikitextParser\Parser;
use JordJD\WikitextParser\Utils;
use Psr\Cache\CacheItemPoolInterface;

class WikipediaInfoBoxParser
{
    private const DEFAULT_ENDPOINT = 'https://en.wikipedia.org/w/api.php';
    private const USER_AGENT = 'jord-jd-wikipedia-infobox-parser/6.1 (+https://github.com/Jord-JD/wikipedia-info-box-parser)';

    /** @var string|null */
    private $article;

    /** @var string */
    private $format = Format::PLAIN_TEXT;

    /** @var string */
    private $templateName = 'Infobox';

    /** @var string */
    private $endpoint = self::DEFAULT_ENDPOINT;

    /** @var CacheItemPoolInterface|null */
    private $cache;

    /** @var bool */
    private $cacheEnabled = true;

    /** @var int|null */
    private $cacheTtl;

    /** @var callable|null */
    private $httpClient;

    /** @var int */
    private $timeoutSeconds;

    /** @var string */
    private $userAgent;

    /** @var int|null */
    private $pageId;

    /** @var string|null */
    private $resolvedTitle;

    public function __construct(
        ?CacheItemPoolInterface $cache = null,
        ?callable $httpClient = null,
        int $timeoutSeconds = 10,
        string $userAgent = self::USER_AGENT
    ) {
        if ($timeoutSeconds < 1) {
            throw new \InvalidArgumentException('The HTTP timeout must be at least one second.');
        }

        if (trim($userAgent) === '' || preg_match('/[\r\n]/', $userAgent)) {
            throw new \InvalidArgumentException('The Wikipedia user agent is invalid.');
        }

        if ($cache === null) {
            $cache = new CacheItemPool();
            $cache->changeConfig([
                'cacheDirectory' => rtrim(sys_get_temp_dir(), '/\\').'/jord-jd-wikipedia-infobox-parser/',
            ]);
        }

        $this->cache = $cache;
        $this->httpClient = $httpClient;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->userAgent = trim($userAgent);
    }

    /**
     * @return $this
     */
    public function setCache(CacheItemPoolInterface $cacheItemPool)
    {
        $this->cache = $cacheItemPool;
        $this->cacheEnabled = true;

        return $this;
    }

    /**
     * @return $this
     */
    public function disableCache()
    {
        $this->cacheEnabled = false;

        return $this;
    }

    /**
     * @return $this
     */
    public function setCacheTtl(?int $seconds)
    {
        if ($seconds !== null && $seconds < 1) {
            throw new \InvalidArgumentException('The cache TTL must be at least one second or null.');
        }

        $this->cacheTtl = $seconds;

        return $this;
    }

    /**
     * @return $this
     */
    public function setArticle(string $article)
    {
        if (trim($article) === '') {
            throw new \InvalidArgumentException('The Wikipedia article title cannot be empty.');
        }

        $this->article = trim($article);

        return $this;
    }

    /**
     * Select a specific template prefix, such as "Infobox person".
     *
     * @return $this
     */
    public function setTemplateName(string $templateName)
    {
        $templateName = trim($templateName);
        if ($templateName === '' || !preg_match('/^[A-Za-z0-9 _-]+$/D', $templateName)) {
            throw new \InvalidArgumentException('The infobox template name is invalid.');
        }

        $this->templateName = $templateName;

        return $this;
    }

    /**
     * @return $this
     */
    public function setFormat(string $format = Format::PLAIN_TEXT)
    {
        if (!in_array($format, [Format::HTML, Format::PLAIN_TEXT], true)) {
            throw new \InvalidArgumentException('Unsupported infobox output format: '.$format.'.');
        }

        $this->format = $format;

        return $this;
    }

    /**
     * Use a trusted MediaWiki Action API endpoint.
     *
     * @return $this
     */
    public function setEndpoint(string $endpoint)
    {
        $endpoint = trim($endpoint);
        $parts = parse_url($endpoint);

        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new \InvalidArgumentException('The MediaWiki API endpoint is invalid.');
        }

        $this->endpoint = rtrim($endpoint, '/');

        return $this;
    }

    public function getPageId(): ?int
    {
        return $this->pageId;
    }

    public function getResolvedTitle(): ?string
    {
        return $this->resolvedTitle;
    }

    /**
     * Retrieve and parse the selected article's first matching infobox.
     *
     * The reserved _categories and _links keys contain complete, deduplicated
     * arrays gathered across MediaWiki continuation pages.
     *
     * @return array<string, mixed>
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function parse(): array
    {
        if ($this->article === null) {
            throw new \LogicException('Set an article before calling parse().');
        }

        $cacheKey = hash('sha256', serialize([
            'wikipedia-infobox-parser-v6.1',
            $this->endpoint,
            $this->article,
            $this->templateName,
            $this->format,
        ]));
        $item = null;

        if ($this->cacheEnabled && $this->cache !== null) {
            $item = $this->cache->getItem($cacheKey);

            if ($item->isHit()) {
                $cached = $item->get();
                if (is_array($cached) && isset($cached['result'], $cached['metadata'])) {
                    $this->pageId = isset($cached['metadata']['page_id']) ? (int) $cached['metadata']['page_id'] : null;
                    $this->resolvedTitle = isset($cached['metadata']['title']) ? (string) $cached['metadata']['title'] : null;

                    return $cached['result'];
                }

                if (is_array($cached)) {
                    return $cached;
                }
            }
        }

        $articleData = $this->fetchArticleData();
        $rawFields = $this->extractInfoboxFields($articleData['content']);
        $result = $this->parseValues($rawFields);
        $result['_categories'] = $articleData['categories'];
        $result['_links'] = $articleData['links'];

        if ($item !== null) {
            $item->set([
                'result' => $result,
                'metadata' => [
                    'page_id' => $this->pageId,
                    'title' => $this->resolvedTitle,
                ],
            ]);

            if ($this->cacheTtl !== null) {
                $item->expiresAfter($this->cacheTtl);
            }

            if (!$this->cache->save($item)) {
                throw new \RuntimeException('Unable to save the parsed infobox to cache.');
            }
        }

        return $result;
    }

    /**
     * @return array{content: string, categories: string[], links: string[]}
     */
    private function fetchArticleData(): array
    {
        $content = null;
        $categories = [];
        $links = [];
        $continuation = [];

        for ($requestNumber = 0; $requestNumber < 100; ++$requestNumber) {
            $parameters = [
                'action' => 'query',
                'cllimit' => 'max',
                'format' => 'json',
                'formatversion' => 2,
                'pllimit' => 'max',
                'prop' => 'revisions|categories|links',
                'redirects' => 1,
                'rvprop' => 'content',
                'rvslots' => 'main',
                'titles' => $this->article,
            ] + $continuation;
            $data = $this->request($parameters);

            if (!isset($data['query']['pages'][0]) || !is_array($data['query']['pages'][0])) {
                throw new \RuntimeException('Wikipedia returned an unexpected article response.');
            }

            $page = $data['query']['pages'][0];
            if (!empty($page['missing'])) {
                throw new \RuntimeException('Wikipedia article not found: '.$this->article.'.');
            }

            $this->pageId = isset($page['pageid']) ? (int) $page['pageid'] : null;
            $this->resolvedTitle = isset($page['title']) && is_string($page['title']) ? $page['title'] : $this->article;

            if ($content === null && isset($page['revisions'][0]['slots']['main']['content']) && is_string($page['revisions'][0]['slots']['main']['content'])) {
                $content = $page['revisions'][0]['slots']['main']['content'];
            }

            foreach (isset($page['categories']) && is_array($page['categories']) ? $page['categories'] : [] as $category) {
                if (isset($category['title']) && is_string($category['title'])) {
                    $categoryName = preg_replace('/^[^:]+:/u', '', $category['title']);
                    if (is_string($categoryName)) {
                        $categories[] = $categoryName;
                    }
                }
            }

            foreach (isset($page['links']) && is_array($page['links']) ? $page['links'] : [] as $link) {
                if (isset($link['title']) && is_string($link['title'])) {
                    $links[] = $link['title'];
                }
            }

            if (!isset($data['continue']) || !is_array($data['continue'])) {
                break;
            }

            $continuation = $data['continue'];
        }

        if ($content === null) {
            throw new \RuntimeException('Wikipedia article content is missing.');
        }

        if ($requestNumber >= 100) {
            throw new \RuntimeException('Wikipedia continuation exceeded the safety limit.');
        }

        return [
            'content' => $content,
            'categories' => array_values(array_unique(array_filter($categories, 'is_string'))),
            'links' => array_values(array_unique($links)),
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    private function request(array $parameters): array
    {
        $body = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $response = $this->fetch($body);
        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Wikipedia returned invalid JSON: '.json_last_error_msg());
        }

        if (!is_array($data)) {
            throw new \RuntimeException('Wikipedia returned an unexpected API response.');
        }

        if (isset($data['error'])) {
            $code = isset($data['error']['code']) ? (string) $data['error']['code'] : 'unknown_error';
            $message = isset($data['error']['info']) ? (string) $data['error']['info'] : 'Unknown API error';
            throw new \RuntimeException('Wikipedia API error '.$code.': '.$message);
        }

        return $data;
    }

    private function fetch(string $body): string
    {
        $headers = [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: '.$this->userAgent,
        ];

        if ($this->httpClient !== null) {
            $response = call_user_func($this->httpClient, $this->endpoint, $body, $headers, $this->timeoutSeconds);

            if (!is_string($response)) {
                throw new \RuntimeException('The Wikipedia HTTP client must return a response body string.');
            }

            return $response;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers)."\r\n",
                'content' => $body,
            ],
        ]);

        $response = @file_get_contents($this->endpoint, false, $context);
        $responseHeaders = isset($http_response_header) ? $http_response_header : [];
        $status = null;

        foreach ($responseHeaders as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', $header, $matches)) {
                $status = (int) $matches[1];
            }
        }

        if ($response === false || $status === null) {
            throw new \RuntimeException('Unable to fetch data from Wikipedia.');
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Wikipedia request failed with HTTP status '.$status.'.');
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function extractInfoboxFields(string $content): array
    {
        $pattern = '/\{\{\s*'.preg_quote($this->templateName, '/').'(?=\s|\||\})/i';
        if (!preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $start = $match[0][1];
        $length = strlen($content);
        $depth = 0;
        $inner = null;

        for ($index = $start; $index < $length - 1; ++$index) {
            $token = substr($content, $index, 2);
            if ($token === '{{') {
                ++$depth;
                ++$index;
                continue;
            }

            if ($token === '}}') {
                --$depth;
                if ($depth === 0) {
                    $inner = substr($content, $start + 2, $index - ($start + 2));
                    break;
                }
                ++$index;
            }
        }

        if ($inner === null) {
            throw new \RuntimeException('The Wikipedia infobox template is unbalanced.');
        }

        $parts = $this->splitTopLevel($inner, '|');
        array_shift($parts);
        $fields = [];

        foreach ($parts as $part) {
            $keyValue = $this->splitFirstTopLevel($part, '=');
            if ($keyValue === null) {
                continue;
            }

            $key = trim($keyValue[0]);
            $value = trim($keyValue[1]);
            if ($key === '' || $value === '') {
                continue;
            }

            $fields[$key] = $value;
        }

        return $fields;
    }

    /**
     * @return string[]
     */
    private function splitTopLevel(string $value, string $separator): array
    {
        $parts = [];
        $buffer = '';
        $templateDepth = 0;
        $linkDepth = 0;
        $length = strlen($value);

        for ($index = 0; $index < $length; ++$index) {
            $token = substr($value, $index, 2);

            if ($token === '{{') {
                ++$templateDepth;
                $buffer .= $token;
                ++$index;
                continue;
            }

            if ($token === '}}' && $templateDepth > 0) {
                --$templateDepth;
                $buffer .= $token;
                ++$index;
                continue;
            }

            if ($token === '[[') {
                ++$linkDepth;
                $buffer .= $token;
                ++$index;
                continue;
            }

            if ($token === ']]' && $linkDepth > 0) {
                --$linkDepth;
                $buffer .= $token;
                ++$index;
                continue;
            }

            if ($value[$index] === $separator && $templateDepth === 0 && $linkDepth === 0) {
                $parts[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $value[$index];
        }

        $parts[] = $buffer;

        return $parts;
    }

    /**
     * @return string[]|null
     */
    private function splitFirstTopLevel(string $value, string $separator)
    {
        $parts = $this->splitTopLevel($value, $separator);
        if (count($parts) < 2) {
            return null;
        }

        return [array_shift($parts), implode($separator, $parts)];
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array<string, string>
     */
    private function parseValues(array $fields): array
    {
        if ($fields === []) {
            return [];
        }

        $batch = '';
        $keys = array_keys($fields);

        foreach (array_values($fields) as $index => $value) {
            $batch .= '<div class="jordjd-infobox-value" data-jordjd-key="'.$index.'">'.$value.'</div>';
        }

        $parser = (new Parser($this->cache, $this->httpClient, $this->timeoutSeconds, $this->userAgent))
            ->setEndpoint($this->endpoint)
            ->setTitle($this->resolvedTitle)
            ->setWikitext($batch)
            ->setFormat(WikitextFormat::HTML);

        if (!$this->cacheEnabled) {
            $parser->disableCache();
        } elseif ($this->cacheTtl !== null) {
            $parser->setCacheTtl($this->cacheTtl);
        }

        $html = $parser->parse();
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8"><div id="jordjd-root">'.$html.'</div>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new \RuntimeException('Unable to parse MediaWiki infobox HTML.');
        }

        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " jordjd-infobox-value ")]');
        if ($nodes === false) {
            throw new \RuntimeException('Unable to locate parsed infobox values.');
        }

        $result = [];

        foreach ($nodes as $node) {
            $rawIndex = $node->getAttribute('data-jordjd-key');
            if (!preg_match('/^\d+$/D', $rawIndex)) {
                continue;
            }

            $index = (int) $rawIndex;
            if (!isset($keys[$index])) {
                continue;
            }

            $value = '';
            foreach ($node->childNodes as $child) {
                $childHtml = $document->saveHTML($child);
                if ($childHtml === false) {
                    throw new \RuntimeException('Unable to read a parsed infobox value.');
                }

                $value .= $childHtml;
            }

            $result[$keys[$index]] = $this->format === Format::PLAIN_TEXT
                ? Utils::stripTagsMaintainWhitespace($value)
                : trim($value);
        }

        if (count($result) !== count($fields)) {
            throw new \RuntimeException('MediaWiki did not return every parsed infobox value.');
        }

        return $result;
    }
}
