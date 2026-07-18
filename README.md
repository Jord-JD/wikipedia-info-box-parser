# Wikipedia Infobox Parser

Parse a Wikipedia article's first matching infobox into an associative array,
including complete category and article-link lists.

## Installation

```bash
composer require jord-jd/wikipedia-info-box-parser
```

## Usage

```php
use JordJD\WikipediaInfoBoxParser\WikipediaInfoBoxParser;

$parser = (new WikipediaInfoBoxParser())
    ->setArticle('PHP');

$infobox = $parser->parse();

echo $infobox['designer'];
print_r($infobox['_categories']);
print_r($infobox['_links']);
echo $parser->getResolvedTitle();
echo $parser->getPageId();
```

The parser uses balanced template and link delimiters, so nested templates,
multiline values, embedded pipes, and equals signs remain part of the correct
field. Values equal to `0` are retained. All values are rendered together in a
single MediaWiki parse request instead of one request per field.

### HTML values

Plain text is returned by default. To retain MediaWiki's rendered HTML:

```php
use JordJD\WikipediaInfoBoxParser\Enums\Format;

$infobox = $parser
    ->setFormat(Format::HTML)
    ->parse();
```

### Selecting a template or Wikipedia edition

`Infobox` matches normal variants such as `Infobox person`. Select a more
specific prefix or a trusted alternate MediaWiki endpoint when needed:

```php
$infobox = (new WikipediaInfoBoxParser())
    ->setEndpoint('https://de.wikipedia.org/w/api.php')
    ->setTemplateName('Infobox Programmiersprache')
    ->setArticle('PHP')
    ->parse();
```

The reserved `_categories` and `_links` arrays follow every MediaWiki
continuation page and are deduplicated. Missing articles, malformed templates,
API errors, bad JSON, and HTTP failures throw clear exceptions.

## Caching and HTTP transport

Results are cached indefinitely in a system temporary directory by default.
You can provide any PSR-6 pool, set a TTL, or disable caching:

```php
$parser = (new WikipediaInfoBoxParser($yourPsr6Cache))
    ->setCacheTtl(3600);

$uncached = (new WikipediaInfoBoxParser())
    ->disableCache();
```

The optional second constructor argument is a callable receiving the endpoint,
POST body, headers, and timeout. It is also shared with the batched wikitext
renderer and must return the response body as a string.

The package supports PHP 7.1 through current PHP 8.x releases, Wikitext Parser
3.1+, and DO File Cache PSR-6 v3 or v4.
