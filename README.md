# Wikipedia Info Box Parser

This library parses the info boxes on Wikipedia pages 
into an associative array.

## Installation

The Wikipedia Info Box Parser package can be easily
installed with Composer. Simply run the following
command from the root of your project.

```bash
composer require jord-jd/wikipedia-info-box-parser
```

## Usage

See the example usage below.

```php
use JordJD\WikipediaInfoBoxParser\Enums\Format;
use JordJD\WikipediaInfoBoxParser\WikipediaInfoBoxParser;

require_once __DIR__.'/../vendor/autoload.php';

$infobox = (new WikipediaInfoBoxParser())
    ->setArticle('PHP')
    ->setFormat(Format::PLAIN_TEXT)
//  ->setFormat(Format::HTML)
    ->parse();

var_dump($infobox);
```

```php
array(24) {
  ["logo"]=>
  string(12) "PHP-logo.svg"
  ["logo size"]=>
  string(5) "100px"
  ["caption"]=>
  string(32) "PHP: PHP Hypertext Preprocessors"
  ["file ext"]=>
  string(68) ".php, .phtml, .php3, .php4, .php5, .php7, .phps, .php-s, .pht, .phar"
  ["paradigm"]=>
  string(63) "Imperative, functional, object-oriented, procedural, reflective"
  ["released"]=>
  string(30) "1995; 24 years ago (1995)[1]"
  ["designer"]=>
  string(14) "Rasmus Lerdorf"
  ["developer"]=>
  string(43) "The PHP Development Team, Zend Technologies"
  ["Old Name"]=>
  string(24) "Personal Home Page (PHP)"
  ["latest release version"]=>
  string(8) "7.3.7[1]"
  ["latest release date"]=>
  string(40) "July 4, 2019; 10 days ago (2019-07-04)"
  ["latest preview version"]=>
  string(16) "7.4.0 alpha 3[1]"
  ["latest preview date"]=>
  string(40) "July 11, 2019; 3 days ago (2019-07-11)"
  ["typing"]=>
  string(13) "Dynamic, weak"
  ["[[ gradual typingGradual]]<ref>{{cite weburl"]=>
  string(178) "https://secure.php.net/manual/en/functions.arguments.php#functions.arguments.type-declaration.strict%7Ctitle=PHP: Function arguments - Manual|website=secure.php.net}}&lt;/ref&gt;"
  ["implementations"]=>
  string(45) "Zend Engine, HHVM, Phalanger, Quercus, Parrot"
  ["influenced"]=>
  string(4) "Hack"
  ["programming language"]=>
  string(34) "C (primarily; some components C++)"
  ["operating system"]=>
  string(18) "Unix-like, Windows"
  ["license"]=>
  string(59) "PHP License (most of Zend engine under Zend Engine License)"
  ["website"]=>
  string(11) "www.php.net"
  ["wikibooks"]=>
  string(15) "PHP Programming"
  ["_categories"]=>
  array(19) {
    [0]=>
    string(21) "Programming languages"
    [1]=>
    string(26) "Articles with example code"
    [2]=>
    string(33) "Class-based programming languages"
    [3]=>
    string(23) "Cross-platform software"
    [4]=>
    string(29) "Dynamic programming languages"
    [5]=>
    string(39) "Dynamically typed programming languages"
    [6]=>
    string(19) "Filename extensions"
    [7]=>
    string(31) "Free compilers and interpreters"
    [8]=>
    string(32) "High-level programming languages"
    [9]=>
    string(20) "Internet terminology"
    [10]=>
    string(37) "Object-oriented programming languages"
    [11]=>
    string(12) "PHP software"
    [12]=>
    string(3) "PHP"
    [13]=>
    string(32) "Procedural programming languages"
    [14]=>
    string(37) "Programming languages created in 1995"
    [15]=>
    string(19) "Scripting languages"
    [16]=>
    string(30) "Software using the PHP license"
    [17]=>
    string(35) "Text-oriented programming languages"
    [18]=>
    string(13) "1995 software"
  }
  ["_links"]=>
  array(500) {
    [0]=>
    string(23) ""Hello, World!" program"
    [1]=>
    string(6) "32-bit"
    [2]=>
    string(6) "64-bit"
    [3]=>
    string(26) "APL (programming language)"
    [4]=>
    string(7) "ASP.NET"
    [5]=>
    string(20) "Abstract Syntax Tree"
    [6]=>
    string(15) "Abstract method"
    [7]=>
    string(13) "Abstract type"
    [8]=>
    string(21) "Academic Free License"
    [9]=>
    string(7) "ActiveX"
    [10]=>
    string(19) "Active Server Pages"
    [11]=>
    string(11) "Adobe Flash"
    [12]=>
    string(18) "Ajax (programming)"
    [13]=>
    string(21) "Alternative PHP Cache"
    [14]=>
    string(35) "Alternative terms for free software"
    [15]=>
    string(19) "Amazon Web Services"
    [16]=>
    string(12) "Andi Gutmans"
    [17]=>
    string(15) "Andrei Zmievski"
    /* ... */
  }
}

```