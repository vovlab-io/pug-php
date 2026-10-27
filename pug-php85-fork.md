# Making `pug-php/pug` 2.7.7 (+ `js-phpize/js-phpize` 1.14.0) PHP 8.2 → 8.5 compatible

> Briefing for an LLM working on a fork of these packages. It covers the context, the investigation,
> root causes, the exact patch (already verified), how to wire the fork into the app, and how to verify it.
> Written 2026-10-27 from an investigation on the Onire project (`/home/onirique/onire`).

---

## 1. Context

- **App:** Onire, a PHP app built on the `zephyrus/zephyrus` framework plus `zephyrus/foundation`. Views are Pug templates
  (544 `.pug` files under `app/Views`, of which 449 are page templates outside `mixins/`).
- **Template engine:** `pug-php/pug` **2.7.7**. This is the **old v2 line**, a fork of Jade.php with `Jade\*` and `Pug\*` namespaces.
  It is **not** the current Phug-based `pug-php/pug` 3.x.
  - Source: `https://github.com/pug-php/pug.git` @ `02fb8b9872ab6abdb4a74778c9b6709797b8f37c`
  - `composer.json` declares `"php": ">=5.3.0"`, and requires `js-phpize/js-phpize ^1.2` and `nodejs-php-fallback/nodejs-php-fallback ^1.2`.
- **JS expression compiler:** `js-phpize/js-phpize` **1.14.0**, used because the app sets `expressionLanguage => 'js'`.
  - Source: `https://github.com/pug-php/js-phpize.git` @ `a4b904fa673e45f82a25e02d3c1592346ab0d716`
- `nodejs-php-fallback/nodejs-php-fallback` 1.6.0 is also installed. **No changes needed** (no deprecations found).
- **Who requires Pug:** `vendor/zephyrus/zephyrus/composer.json` requires `"pug-php/pug": "2.*"`. The app's own `composer.json`
  does **not** list Pug directly.
- **How Pug is instantiated:** `vendor/zephyrus/zephyrus/src/Zephyrus/Application/ViewBuilder.php`, `buildPug()`:
  ```php
  $options = [
      'basedir' => realpath(ROOT_DIR . '/public'),
      'expressionLanguage' => 'js',
      'upToDateCheck' => $upToDateCheck,
      'cache' => $cacheEnabled ? $cacheDirectory : null   // [pug] cache_enabled / cache_directory in config.ini
  ];
  $this->pug = new Pug($options);
  ```
  `config.ini` has `[pug] cache_enabled = PUG_CACHE_ENABLED` (env var) and `cache_directory = "/var/cache/pug"`.
- **PHP:** dev machine is PHP 8.4.25 with Xdebug 3 in `develop` mode and OPcache. The target is PHP 8.5.
- **How deprecations were silenced so far:** php.ini `error_reporting = E_ALL & ~E_DEPRECATED`. Zephyrus's `ErrorHandler`
  still installs `set_error_handler()` for all levels and returns early when the level isn't in `error_reporting()`.
  `app/kernel.php` also sets `ini_set('xdebug.max_nesting_level', 512); //Fix deeply nested PUG files`.
- **Out of scope:** PDF generation (`app/Models/Pdf/**`, e.g. `app/Models/Pdf/Templates/BaseTemplate.php`) does **not** use Pug.
  It writes HTML with plain PHP and `ob_start()`, which feeds mPDF. `app/Models/Pdf/Builder/Buffer.php` installs its own error handler
  that throws `PdfException` for everything except `E_DEPRECATED`/`E_STRICT`. Nothing in this fork affects it.

## 2. The incident that started this

- **Symptom:** after logging in, every page returned **`ERR_EMPTY_RESPONSE`** in Chrome, with nothing in the Apache access or error logs.
  The login page still worked.
- **Cause:** the PHP worker died (memory exhausted during template rendering) before sending any bytes. The login page uses a small layout.
  Authenticated pages use the full layout, which includes the entire mixin library.
- **Workaround applied:** raised `memory_limit` from 500M to 5000M. The app's memory display showed `463,8 mb / 1,7 gb` (usage / allocated).
- **The user's hypothesis:** the huge number of deprecation notices was causing the memory use.
  **Measurement disproved this** (see §3.2). The real cause is a PHP 8.4 change combined with Pug 2's stream wrapper.

## 3. Findings (all measured, not guessed)

### 3.1 Volume of deprecation notices
Compiling **one** page (`sales/orders/details.pug` + `create.pug`) raises about **31,800** notices from only **27 unique lines**.
Compiling all 449 page templates in one process raises **5,128,666** notices. The top offenders for one page:

```
9112  js-phpize/src/JsPhpize/Lexer/Lexer.php:48        Creation of dynamic property JsPhpize\Lexer\Lexer::$disallow
4179  pug/src/Jade/Parser.php:746                      Creation of dynamic property Jade\Nodes\Tag::$line
4179  pug/src/Jade/Parser.php:797                      Creation of dynamic property Jade\Nodes\Tag::$textOnly
3812  pug/src/Jade/Parser.php:571                      Creation of dynamic property Jade\Nodes\Block::$line
2713  pug/src/Jade/Parser.php:311                      Creation of dynamic property Jade\Nodes\Code::$line
 685  pug/src/Jade/Compiler/MixinVisitor.php:107       Calling get_class() without arguments is deprecated
   1  pug/src/Jade/Compiler/Visitor.php:82             visitDoctype(): Implicitly marking parameter $doctype as nullable
```

The full list of dynamic properties being created:
`Jade\Compiler::$expressionLanguage, $switchNode, $visitedMixin` · `Jade\Lexer::$identRE` · `Jade\Parser::$customKeywords, $_spaces` ·
`Jade\Nodes\Block::$filename, $line, $mode` · `CaseNode::$line` · `Code::$block, $line` · `Comment::$line` · `Doctype::$line` ·
`Each::$line` · `Mixin::$line, $textOnly` · `Tag::$code, $line, $textOnly` · `Text::$line` · `JsPhpize\Lexer\Lexer::$disallow` ·
plus, at render time, `Jade\Stream\Template::$context` (PHP sets it on every stream wrapper instance).

### 3.2 The notices are NOT what uses the memory
Benchmark: compile 2 pages under every combination of handler and `error_reporting`.

| Setup | Time | Peak memory |
|---|---|---|
| Xdebug ON, no handler / counting handler / handler calling `debug_backtrace()` | 2.2–2.4 s | 20 MB in every case |
| Xdebug OFF, same combinations | 0.49–0.54 s | 20 MB in every case |

- The notices cost a little **time** (handler calls) and nothing measurable in **memory**.
- **Xdebug makes compiling about 4.5x slower.** It should not be loaded in production.
- Compiling every template on its own peaks at **16 MB at most**. Each page compiles to **about 1.3–1.5 MB of PHP**, because every page
  inlines the whole mixin library (about 512 functions/closures per page).

### 3.3 Real root cause: the stream wrapper plus PHP 8.4 closure names
When `cache` is `null` (Pug cache off), `Jade::renderWithPhp()` runs the compiled code like this:
```php
$file = $this->options['cache'] ? $this->cache($input) : $this->stream($this->compile($input, $filename));
include $file;
```
and `Jade::stream()` returns:
```php
return $this->options['stream'] . '://data;' . $input;   // the ENTIRE compiled source is the include PATH
```
`Jade\Stream\Template::stream_open($path)` then pulls the source back out of the path with `substr(strstr($path, ';'), 1)`.

**Since PHP 8.4, closure names include their file path and line** (`{closure:/path/file.php:123}`). Verified:
```
closure name sample: {closure:pug.stream://data;<?php return function(){};:1}
```
So **each of the about 512 closures in a compiled page gets a name that contains the full 1.3 MB source**, and more copies follow
(function names, opcache/compiler structures). Measurement on `products/no_stock.pug`, parse only (`<?php return; ?>` prepended so nothing runs):

| How the same 1.3 MB of compiled PHP is included | Extra peak memory | Time |
|---|---|---|
| From a real temporary file | **+14 MB** | 0.03 s |
| Through `pug.stream://data;<source>` | **+2,042 MB** | 1.12 s |

Full render of that page: **2,060 MB peak (original) vs 36 MB (patched)**. The result is the same with Xdebug off and with `error_reporting=0`,
which confirms the deprecations are unrelated.

- This explains why memory "went through the roof" after a PHP upgrade: it is a **PHP 8.4** behaviour change, not 8.2.
- It **only happens when the Pug cache is disabled.** With `cache` set, `Jade::cache()` writes a real `.php` file to the cache dir
  and includes that file path, which is short. The production VM was therefore most likely running with `PUG_CACHE_ENABLED` not `true`.
  **Immediate workaround without any fork:** enable the Pug cache (`PUG_CACHE_ENABLED=true`, and `/var/cache/pug` writable by `www-data`).

### 3.4 Static scan for other PHP 8.2–8.5 deprecations (all three packages)
Runtime testing only covers code paths the app's templates use, so the sources were also grepped for:

| Check | PHP | Result |
|---|---|---|
| Implicitly nullable typed params (`Type $x = null`) | 8.4 | 2 hits, both fixed (`Visitor::visitDoctype`, `Pug\Filter\AbstractFilter::getNodeString`) |
| `get_class()` / `get_parent_class()` without args | 8.3 | 2 hits, both fixed (`MixinVisitor.php:107`, `CompilerUtils.php:78`) |
| `"${var}"` string interpolation | 8.2 | none |
| `utf8_encode` / `utf8_decode` | 8.2 | none |
| Non-canonical casts `(integer) (boolean) (double) (binary)` | 8.5 | none |
| Backtick shell operator | 8.5 | none (backticks found are only regex delimiters, e.g. `preg_match('`^...`')`) |
| `case X;` (semicolon after case) | 8.5 | none |
| `__sleep`/`__wakeup`, `setAccessible`, `mhash`, `strftime`, `FILTER_SANITIZE_STRING`, `$http_response_header`, `each()`, `create_function` | 8.1–8.5 | none |
| `php -l` on every file (with `error_reporting=-1`) | 8.4 | only the 2 implicit-nullable notices above |

There is a PHP 5 code path in `Jade::compile()` (`version_compare(PHP_VERSION, '7.0.0') < 0 || php5compatibility`). It is harmless,
and could be removed if the fork bumps its minimum PHP.

## 4. The fix (already written and verified)

Four kinds of change, plus the memory fix. **11 files, +39/−6 lines.**

1. **Dynamic properties (8.2):** add `#[\AllowDynamicProperties]` to the root classes. The attribute is **inherited by subclasses**
   (`Tag`, `Block`, `Mixin`, …, `Pug\Parser`, `Pug\Compiler`, `Pug\Lexer`), so 5 annotations cover everything:
   `Jade\Nodes\Node`, `Jade\Parser`, `Jade\Lexer`, `Jade\Compiler`, `JsPhpize\Lexer\Lexer`.
   - Why the attribute instead of declaring properties: it changes **no behaviour**. Declaring properties would change
     `property_exists()`, `get_object_vars()`, `foreach ($node as ...)` and `clone` semantics, and the parser and compiler may rely on those.
     PHP < 8 reads `#[...]` as a comment, so it stays backward compatible.
   - Optional later cleanup: declare the properties properly (`public $line; public $filename; public $textOnly; ...`) and drop the attribute.
     Only do that with a test suite in place.
2. **Declared property:** `public $context;` on `Jade\Stream\Template`. PHP assigns `$context` to every stream wrapper instance,
   which raises a dynamic-property deprecation at render time.
3. **`get_class()` without args (8.3):** replace it with `__CLASS__`. They are equivalent: both give the class where the code is
   written (or the class using it, if in a trait).
4. **Implicit nullable (8.4):** `Doctype $doctype = null` becomes `?Doctype $doctype = null`, and `Compiler $compiler = null` becomes `?Compiler $compiler = null`.
   `?T` needs PHP ≥ 7.1, so the fork's `composer.json` `php` constraint should be bumped (e.g. `>=7.1` or `^8.1`).
   No subclasses override these methods (checked).
5. **Memory fix (PHP 8.4 closure naming):** `Jade::stream()` stores the compiled source in a static registry on
   `Jade\Stream\Template`, keyed by `md5($input)`, and puts only the 32-character key in the include path. `stream_open()` looks the key up,
   and falls back to treating the payload as raw source so the old `data;<source>` form keeps working (backward compatible).
   - Notes for future work: the registry lives for the whole request (one entry per distinct compiled template). That is fine for
     PHP-FPM and mod_php. In a long-running worker (Swoole, RoadRunner, ReactPHP), consider `unset(self::$templates[$key])` after
     `stream_open()`, but only if nothing re-includes the same path. An alternative fix is to write the compiled source to a
     temporary file (`tempnam()`) and include that, like the cache path does.

### 4.1 Patch for the `pug-php/pug` fork (apply at repo root: `git apply pug.patch`)
```diff
diff --git a/src/Jade/Compiler.php b/src/Jade/Compiler.php
index 0386767..ead570a 100644
--- a/src/Jade/Compiler.php
+++ b/src/Jade/Compiler.php
@@ -10,6 +10,7 @@ use Jade\Parser\Exception as ParserException;
 /**
  * Class Jade Compiler.
  */
+#[\AllowDynamicProperties]
 class Compiler extends Options
 {
     /**
diff --git a/src/Jade/Compiler/CompilerUtils.php b/src/Jade/Compiler/CompilerUtils.php
index 72ee732..d1f6aa1 100644
--- a/src/Jade/Compiler/CompilerUtils.php
+++ b/src/Jade/Compiler/CompilerUtils.php
@@ -75,7 +75,7 @@ abstract class CompilerUtils extends Indenter
 
         return preg_replace_callback(
             str_replace('%s', $pattern, $regexp),
-            array(get_class(), 'convertVarPathCallback'),
+            array(__CLASS__, 'convertVarPathCallback'),
             $arg
         );
     }
diff --git a/src/Jade/Compiler/MixinVisitor.php b/src/Jade/Compiler/MixinVisitor.php
index 83e9f25..86a930c 100644
--- a/src/Jade/Compiler/MixinVisitor.php
+++ b/src/Jade/Compiler/MixinVisitor.php
@@ -104,7 +104,7 @@ abstract class MixinVisitor extends MixinVisitorUtils
 
         array_unshift($arguments, 'attributes');
         $arguments = $this->splitArguments(implode(',', $arguments));
-        array_walk($arguments, array(get_class(), 'initArgToNull'));
+        array_walk($arguments, array(__CLASS__, 'initArgToNull'));
         $this->visitMixinCodeAndBlock($name, $block, $arguments);
 
         if (is_null($previousVisitedMixin)) {
diff --git a/src/Jade/Compiler/Visitor.php b/src/Jade/Compiler/Visitor.php
index 305ff5e..c10e20a 100644
--- a/src/Jade/Compiler/Visitor.php
+++ b/src/Jade/Compiler/Visitor.php
@@ -79,7 +79,7 @@ abstract class Visitor extends KeywordsCompiler
      *
      * @throws \Exception
      */
-    protected function visitDoctype(Doctype $doctype = null)
+    protected function visitDoctype(?Doctype $doctype = null)
     {
         $doc = (empty($doctype->value) || $doctype === null || !isset($doctype->value))
             ? 'default'
diff --git a/src/Jade/Jade.php b/src/Jade/Jade.php
index 0812a9d..b6e1823 100644
--- a/src/Jade/Jade.php
+++ b/src/Jade/Jade.php
@@ -218,7 +218,9 @@ class Jade extends PugJsEngine
             stream_wrapper_register($this->options['stream'], 'Jade\\Stream\\Template');
         }
 
-        return $this->options['stream'] . '://data;' . $input;
+        // Pass a short key instead of the whole source as the include path: since PHP 8.4,
+        // every closure name embeds its file path, which multiplied memory by the source size.
+        return $this->options['stream'] . '://data;' . \Jade\Stream\Template::register($input);
     }
 
     /**
diff --git a/src/Jade/Lexer.php b/src/Jade/Lexer.php
index 223a68a..7b7e0c7 100644
--- a/src/Jade/Lexer.php
+++ b/src/Jade/Lexer.php
@@ -7,6 +7,7 @@ use Jade\Lexer\Scanner;
 /**
  * Class Jade\Lexer.
  */
+#[\AllowDynamicProperties]
 class Lexer extends Scanner
 {
     /**
diff --git a/src/Jade/Nodes/Node.php b/src/Jade/Nodes/Node.php
index 32ca4f2..e97c6b3 100644
--- a/src/Jade/Nodes/Node.php
+++ b/src/Jade/Nodes/Node.php
@@ -2,6 +2,7 @@
 
 namespace Jade\Nodes;
 
+#[\AllowDynamicProperties]
 abstract class Node
 {
     public function isInline()
diff --git a/src/Jade/Parser.php b/src/Jade/Parser.php
index d94c7ab..8562833 100644
--- a/src/Jade/Parser.php
+++ b/src/Jade/Parser.php
@@ -5,6 +5,7 @@ namespace Jade;
 use Jade\Parser\Exception as ParserException;
 use Jade\Parser\ExtensionsHelper;
 
+#[\AllowDynamicProperties]
 class Parser
 {
     public static $includeNotFound = '.alert.alert-danger Page not found.';
diff --git a/src/Jade/Stream/Template.php b/src/Jade/Stream/Template.php
index f7ea2b7..ebe231c 100644
--- a/src/Jade/Stream/Template.php
+++ b/src/Jade/Stream/Template.php
@@ -9,6 +9,11 @@ namespace Jade\Stream;
  */
 class Template
 {
+    /**
+     * @var resource|null set by PHP on every stream wrapper instance
+     */
+    public $context;
+
     /**
      * @var int
      */
@@ -19,6 +24,26 @@ class Template
      */
     private $data = '';
 
+    /**
+     * @var array compiled sources awaiting inclusion, indexed by key
+     */
+    private static $templates = array();
+
+    /**
+     * Store a compiled source and return the key to use in the stream path.
+     *
+     * @param string $input
+     *
+     * @return string
+     */
+    public static function register($input)
+    {
+        $key = md5($input);
+        self::$templates[$key] = $input;
+
+        return $key;
+    }
+
     /**
      * @param $path
      *
@@ -26,7 +51,8 @@ class Template
      */
     public function stream_open($path)
     {
-        $this->data = substr(strstr($path, ';'), 1);
+        $key = substr(strstr($path, ';'), 1);
+        $this->data = isset(self::$templates[$key]) ? self::$templates[$key] : $key;
 
         return true;
     }
diff --git a/src/Pug/Filter/AbstractFilter.php b/src/Pug/Filter/AbstractFilter.php
index 7c2c4eb..600b462 100644
--- a/src/Pug/Filter/AbstractFilter.php
+++ b/src/Pug/Filter/AbstractFilter.php
@@ -21,7 +21,7 @@ abstract class AbstractFilter implements JadeFilterInterface
      *
      * @return mixed
      */
-    protected function getNodeString(Filter $node, Compiler $compiler = null)
+    protected function getNodeString(Filter $node, ?Compiler $compiler = null)
     {
         return array_reduce($node->block->nodes, function ($result, $line) use ($compiler) {
             return $result . (
```

### 4.2 Patch for the `js-phpize/js-phpize` fork (apply at repo root: `git apply js-phpize.patch`)
```diff
diff --git a/src/JsPhpize/Lexer/Lexer.php b/src/JsPhpize/Lexer/Lexer.php
index c4aef1e..0292eb3 100644
--- a/src/JsPhpize/Lexer/Lexer.php
+++ b/src/JsPhpize/Lexer/Lexer.php
@@ -4,6 +4,7 @@ namespace JsPhpize\Lexer;
 
 use JsPhpize\JsPhpize;
 
+#[\AllowDynamicProperties]
 class Lexer extends Scanner
 {
     /**
```

Both patches were checked with `git apply --check` against pristine copies of the installed 2.7.7 and 1.14.0 sources.
Upstream tags are `2.7.7` and `1.14.0` at the commits listed in §1.

### 4.3 Suggested extra changes in the forks
- `composer.json`: bump `"php"` to at least `>=7.1` (needed by `?Type`). Suggest `^8.1` if dropping old PHP.
- In the `pug-php/pug` fork, point the `js-phpize/js-phpize` requirement at the js-phpize fork (or let the app's root `composer.json`
  provide it through a VCS repository and alias; see §5).
- Optionally make the CI/test matrix run on PHP 8.2, 8.3, 8.4 and 8.5 with `error_reporting=E_ALL` and a handler that **throws on
  `E_DEPRECATED`**, so new deprecations fail the build. The upstream repo has a PHPUnit suite under `tests/`.

## 5. Wiring the forks into Onire
`zephyrus/zephyrus` requires `pug-php/pug: "2.*"` and pug requires `js-phpize/js-phpize: ^1.2`, so the fork branches must be
**aliased** to satisfy those constraints. In Onire's root `composer.json`:

```json
{
  "repositories": [
    { "type": "vcs", "url": "git@github.com:<you>/pug.git" },
    { "type": "vcs", "url": "git@github.com:<you>/js-phpize.git" }
  ],
  "require": {
    "pug-php/pug": "dev-php85 as 2.7.7",
    "js-phpize/js-phpize": "dev-php85 as 1.14.0"
  }
}
```
(Alternatively, tag the forks `2.7.8` and `1.14.1` and require those. Inline aliases only work from the root package.)
Then run `composer update pug-php/pug js-phpize/js-phpize`. Note that `composer.json` `post-install`/`post-update` scripts run
`cp -R vendor/zephyrus/foundation/backpack/* .`.

After deploying:
- Clear `/var/cache/pug/*`, so templates compiled by the old engine aren't reused.
- `memory_limit` can go back to something sane (500M was the old value; a page now peaks at about 36 MB in isolation).
- Optionally remove `E_DEPRECATED` silencing from php.ini and see whether anything else in the app or vendor code is noisy.
- Keep the Pug cache **enabled** in production regardless. It avoids recompiling 1.4 MB of PHP on every request.
- Disable Xdebug in production (about 4.5x slower compiles).

## 6. How the patch was verified (reproduce with these scripts)

Setup used: copies of the vendor packages at `/tmp/pugfix/pug` and `/tmp/pugfix/js-phpize`, patched there.
The app's Composer autoloader was redirected to them with `$loader->set('Jade\\', ...)` etc. (both packages use PSR-0).
Run with `php -n -d extension=mbstring -d extension=tokenizer -d memory_limit=-1 -d error_reporting=-1` (`-n` = no Xdebug/OPcache).

**Compile-output equivalence plus notice count** (`verify.php`):
```php
<?php
// usage: php verify.php <orig|patched> <out_dir> <templates...>
$loader = require '/home/onirique/onire/vendor/autoload.php';
if ($argv[1] === 'patched') {
    $loader->set('Jade\\', '/tmp/pugfix/pug/src'); $loader->set('Pug\\', '/tmp/pugfix/pug/src'); $loader->set('JsPhpize', '/tmp/pugfix/js-phpize/src');
}
$out = $argv[2]; $n = 0; $u = [];
set_error_handler(function ($t, $m, $f, $l) use (&$n, &$u) { $n++; $u["$f:$l $m"] = 1; return true; });
$pug = new Pug\Pug(['basedir' => '/home/onirique/onire/public', 'expressionLanguage' => 'js', 'cache' => null]);
foreach (array_slice($argv, 3) as $f) {
    try { $php = $pug->compile(file_get_contents($f), $f); } catch (Throwable $e) { $php = 'EXC ' . get_class($e) . ' ' . $e->getMessage(); }
    file_put_contents("$out/" . md5($f), $php);
}
echo (new ReflectionClass('Jade\\Parser'))->getFileName(), " notices=$n\n"; foreach ($u as $k => $_) echo "  $k\n";
```
```bash
F=$(find app/Views -name '*.pug' ! -path '*/mixins/*' | tr '\n' ' ')
php ... verify.php orig    /tmp/o $F   # notices=5128666
php ... verify.php patched /tmp/p $F   # notices=0
diff -rq /tmp/o /tmp/p                 # no output: identical compiled PHP for all 449 templates, 0 compile exceptions
```

**Include-memory test** (parse only): compile a page, prepend `<?php return; ?>`, then `include` it once from a temp file and once
through `$pug->stream($php)`, comparing `memory_get_peak_usage(true)` before and after. Original: +14 MB (file) vs +2,042 MB (stream).
Patched: the stream behaves like the file.

**Render test:** `renderWithPhp()` on `products/no_stock.pug` with stub variables (`localize`, `route`, … as closures returning `''`).
Original peak 2,060 MB with 31,139 notices; patched peak 36 MB with 0 Pug notices. The only remaining notices were `Undefined variable
$project_name/$version/$nonce`, which come from the stub data and not from Pug. The render then threw a `TypeError` from
`call_user_func()` at the same point in both versions, also caused by the stubs. Not a regression.

**Closure-name proof:**
```php
$x = include $pug->stream('<?php return function(){};');
echo (new ReflectionFunction($x))->getName();   // {closure:pug.stream://data;<?php return function(){};:1}
```

## 7. Limitations and what still needs checking
- **Not run on PHP 8.5** (only 8.4.25 was available). 8.5 coverage comes from the static scan in §3.4. Run the verification on 8.5,
  with `error_reporting=-1` and a throwing handler.
- **Runtime helpers were only partly exercised.** Compiled templates call into the packages at render time (e.g.
  `\Jade\Compiler::getPropertyFromAnything`, js-phpize helpers such as `Dot.h`, `Plus.h`, `RegExp.h`). The render test used stub data,
  so render-time deprecations with real data (e.g. 8.1's "Passing null to parameter of type string" inside helpers) may still exist.
  Render real pages with real data and `E_DEPRECATED` enabled, and fix any that appear the same way.
- The upstream PHPUnit suite was not run. Run it in the fork on PHP 8.2–8.5.
- **Long-term alternative:** migrate to `pug-php/pug` 3.x (Phug), which is maintained for PHP 8. That is a real migration
  (different engine, option names, Zephyrus `ViewBuilder` integration, possible template syntax differences), not a drop-in
  replacement. The fork described here is the low-risk path.

## 8. Quick facts
- Unique deprecation lines: 27 at compile time plus 1 at render time (`Template::$context`). Static-only finds add 1 more
  (`AbstractFilter::getNodeString`).
- Compiled page size: about 1.3–1.5 MB of PHP, about 512 functions/closures (whole mixin library inlined).
- Memory per uncached render: 2,060 MB before, 36 MB after.
- Compile time per 2 pages: 2.2 s with Xdebug, 0.5 s without.
- Files changed: `pug`: `src/Jade/{Compiler.php, Compiler/CompilerUtils.php, Compiler/MixinVisitor.php, Compiler/Visitor.php, Jade.php,
  Lexer.php, Nodes/Node.php, Parser.php, Stream/Template.php}`, `src/Pug/Filter/AbstractFilter.php`; `js-phpize`: `src/JsPhpize/Lexer/Lexer.php`.
