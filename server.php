<?php
// Sessionnet Duisburg Ratsinformation - RSS Feed Adapter
// Fetches documents and "Beratungen" (consultations) from Sessionnet Duisburg
// Usage: Access this script directly (e.g., /server.php)
// Optional: ?max-entries=20 to limit the number of entries (default: 20)
// Optional: ?no-cache=1 to bypass the cache for this request

const FEED_URL = 'https://sessionnet.owl-it.de/duisburg/bi/do0040.asp';
const FEED_TITLE = 'Ratsinformation Duisburg';
const BASE_URL = 'https://sessionnet.owl-it.de/duisburg/bi/';

// Configurable via URL parameter: ?max-entries=20
$maxEntries = isset($_GET['max-entries']) ? max(1, (int)$_GET['max-entries']) : 20;

// --- Cache configuration ---
define('CACHE_DIR', sys_get_temp_dir() . '/sessionnet_duisburg_cache');
// The main listing page changes more often than detail/Beratungen pages,
// which rarely change once published, so they get different TTLs.
define('CACHE_TTL_LIST', 15 * 60);        // 15 minutes for the main list page
define('CACHE_TTL_DETAIL', 24 * 60 * 60); // 24 hours for detail & Beratungen pages
// ?no-cache=1 bypasses reading the cache for this request (still refreshes it)
$noCache = isset($_GET['no-cache']) && $_GET['no-cache'] == '1';

// --- Concurrency configuration ---
// How many HTTP requests to run in parallel when warming the cache for
// detail / Beratungen pages. Keep this modest to be polite to the upstream server.
define('MAX_CONCURRENT_REQUESTS', 8);

// Whole-feed output cache: the fully-assembled Atom XML, keyed by max-entries.
// On a hit this skips everything — no list fetch, no detail/Beratungen fetches,
// no DOM parsing, no XML assembly. TTL matches the list page's own TTL, since
// the assembled feed can't be fresher than the list page it was built from.
define('CACHE_TTL_FEED', CACHE_TTL_LIST);

// ---------------------------------------------------------------------
// Cache helpers
// ---------------------------------------------------------------------

function ensureCacheDir(): bool {
    if (is_dir(CACHE_DIR)) {
        return is_writable(CACHE_DIR);
    }
    // Race-safe: another request may create it between the check and mkdir.
    if (@mkdir(CACHE_DIR, 0775, true) || is_dir(CACHE_DIR)) {
        return is_writable(CACHE_DIR);
    }
    return false;
}

function cacheFilePath(string $url): string {
    return CACHE_DIR . '/' . sha1($url) . '.cache';
}

/**
 * Read a cached value if present and not expired. Null if missing/expired/corrupt.
 * Treats any failure to read as a cache miss rather than propagating an error —
 * caching is a performance optimization, never a hard dependency.
 */
function cacheGet(string $key, int $ttl): ?string {
    if ($ttl <= 0) {
        return null;
    }
    $path = cacheFilePath($key);
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $mtime = @filemtime($path);
    if ($mtime === false || (time() - $mtime) > $ttl) {
        return null;
    }
    $data = @file_get_contents($path);
    if ($data === false || $data === '') {
        return null;
    }
    return $data;
}

/**
 * Write a value to the cache (atomically). Returns whether the write succeeded;
 * callers treat a failed write as non-fatal (the value simply isn't cached).
 */
function cacheSet(string $key, string $value): bool {
    if (!ensureCacheDir()) {
        return false;
    }
    $path = cacheFilePath($key);
    $tmpPath = $path . '.' . uniqid('', true) . '.tmp';
    $written = @file_put_contents($tmpPath, $value);
    if ($written === false || $written !== strlen($value)) {
        @unlink($tmpPath);
        return false;
    }
    if (!@rename($tmpPath, $path)) {
        @unlink($tmpPath);
        return false;
    }
    return true;
}

/**
 * Opportunistically remove expired cache files (runs on a small % of requests).
 * Best-effort: any filesystem error for an individual file is skipped, not fatal.
 */
function cacheCleanup(int $maxAge): void {
    if (!is_dir(CACHE_DIR) || $maxAge <= 0) {
        return;
    }
    if (mt_rand(1, 100) > 5) {
        return;
    }
    $now = time();
    foreach (glob(CACHE_DIR . '/*.cache') ?: [] as $file) {
        $mtime = @filemtime($file);
        if ($mtime === false || ($now - $mtime) > $maxAge) {
            @unlink($file);
        }
    }
}

// ---------------------------------------------------------------------
// Text / URL helpers
// ---------------------------------------------------------------------

// Precomputed replacement table for fixing common UTF-8-as-Latin1 mojibake.
// strtr() with a map is faster than chained str_replace calls.
// Longer/more specific sequences are listed first; strtr() always matches
// the longest key at each position, so order here doesn't actually matter,
// but each key must be unique.
const MOJIBAKE_MAP = [
    'lÃ¤' => 'lä', 'lÃ¶' => 'lö', 'lÃ¼' => 'lü',
    'LÃ¤' => 'Lä', 'LÃ¶' => 'Lö', 'LÃ¼' => 'Lü',
    'Ã¤' => 'ä', 'Ã¶' => 'ö', 'Ã¼' => 'ü', 'Ã©' => 'é', 'Ã¨' => 'è',
];

function cleanText(string $text): string {
    $text = strtr($text, MOJIBAKE_MAP);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    return htmlspecialchars($text, ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

function cleanHref(string $href): string {
    $href = urldecode($href);
    if (!preg_match('/^https?:\/\//i', $href)) {
        $href = BASE_URL . ltrim($href, '/');
    }
    return $href;
}

// ---------------------------------------------------------------------
// HTTP fetching (single + concurrent, both cache-aware)
// ---------------------------------------------------------------------

/**
 * Build a cURL handle with shared, sane defaults.
 * @throws RuntimeException if curl_init fails (e.g. curl extension misconfigured)
 */
function newCurlHandle(string $url): CurlHandle {
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed for URL: ' . $url);
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (compatible; FreshRSS-Adapter/1.0)',
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: de,en-US;q=0.7,en;q=0.3',
        ],
        CURLOPT_ENCODING => '', // ask for/accept gzip/deflate automatically
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TCP_KEEPALIVE => 1,
    ]);
    return $ch;
}

/**
 * Normalize fetched HTML to UTF-8. Falls back to a safe default source
 * encoding if detection is inconclusive, rather than passing false into
 * mb_convert_encoding (which would throw a TypeError).
 */
function normalizeFetchedHtml(string $html): string {
    $detected = mb_detect_encoding($html, 'UTF-8, ISO-8859-1', true);
    $sourceEncoding = $detected !== false ? $detected : 'ISO-8859-1';
    $converted = @mb_convert_encoding($html, 'UTF-8', $sourceEncoding);
    return $converted !== false ? $converted : $html;
}

/**
 * Fetch a single URL, transparently using the local cache.
 */
function fetchHtml(string $url, int $ttl = CACHE_TTL_DETAIL, bool $bypassCache = false): string {
    if (!$bypassCache) {
        $cached = cacheGet($url, $ttl);
        if ($cached !== null) {
            return $cached;
        }
    }

    $ch = newCurlHandle($url);
    $html = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error || $httpCode !== 200 || empty($html)) {
        $stale = cacheGet($url, PHP_INT_MAX);
        if ($stale !== null) {
            return $stale;
        }
        $reason = $error ?: ($httpCode !== 200 ? "HTTP error: $httpCode" : 'Empty response');
        throw new RuntimeException($reason . ' for URL: ' . $url);
    }

    $html = normalizeFetchedHtml($html);
    cacheSet($url, $html);

    return $html;
}

/**
 * Fetch many URLs in parallel (bounded concurrency), using the cache for
 * any URL that already has a fresh entry. Only cache misses hit the network,
 * and they do so concurrently rather than one-by-one.
 *
 * Returns [url => html]. URLs that ultimately fail fall back to a stale
 * cache entry if one exists, or are simply omitted from the result.
 */
function fetchHtmlBatch(array $urls, int $ttl, bool $bypassCache = false): array {
    $urls = array_values(array_unique($urls));
    $results = [];
    $toFetch = [];

    foreach ($urls as $url) {
        if (!$bypassCache) {
            $cached = cacheGet($url, $ttl);
            if ($cached !== null) {
                $results[$url] = $cached;
                continue;
            }
        }
        $toFetch[] = $url;
    }

    if (empty($toFetch)) {
        return $results;
    }

    $mh = curl_multi_init();
    if ($mh === false) {
        // Fall back to sequential fetching rather than failing the whole batch.
        foreach ($toFetch as $url) {
            try {
                $results[$url] = fetchHtml($url, $ttl, $bypassCache);
            } catch (RuntimeException $e) {
                // Skip; caller treats a missing URL as "no data available".
            }
        }
        return $results;
    }
    curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, max(1, MAX_CONCURRENT_REQUESTS));

    $handles = [];      // resource id => ['url' => string]
    $queue = $toFetch;  // remaining URLs to enqueue
    $active = 0;

    // Prime the pool up to the concurrency limit
    $enqueue = function () use (&$queue, &$handles, &$active, $mh) {
        while ($active < MAX_CONCURRENT_REQUESTS && !empty($queue)) {
            $url = array_shift($queue);
            try {
                $ch = newCurlHandle($url);
            } catch (RuntimeException $e) {
                continue; // skip URLs whose handle couldn't be created
            }
            curl_multi_add_handle($mh, $ch);
            $id = (int) $ch;
            $handles[$id] = ['url' => $url];
            $active++;
        }
    };
    $enqueue();

    // Safety valve: bound total iterations so a misbehaving curl_multi
    // implementation (or a stuck handle) can't spin this loop forever.
    $maxIterations = (count($toFetch) + MAX_CONCURRENT_REQUESTS) * 200;
    $iterations = 0;

    $running = null;
    do {
        $iterations++;
        if ($iterations > $maxIterations) {
            break;
        }

        curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 1.0);
        }

        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $id = (int) $ch;
            $url = $handles[$id]['url'] ?? null;

            if ($url !== null) {
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                $content = curl_multi_getcontent($ch);

                if (!$error && $httpCode === 200 && !empty($content)) {
                    $html = normalizeFetchedHtml($content);
                    cacheSet($url, $html); // best-effort; failure just means no cache hit next time
                    $results[$url] = $html;
                } else {
                    $stale = cacheGet($url, PHP_INT_MAX);
                    if ($stale !== null) {
                        $results[$url] = $stale;
                    }
                }
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($handles[$id]);
            $active--;

            // Keep the pool full while there's more work queued
            $enqueue();
        }
    } while (($running > 0 || !empty($queue)) && $iterations <= $maxIterations);

    curl_multi_close($mh);

    return $results;
}

// ---------------------------------------------------------------------
// Parsing
// ---------------------------------------------------------------------

/**
 * Parse a document into a DOMXPath. Centralized so loadHTML flags stay
 * consistent and errors are always suppressed the same way. Falls back to
 * an empty document (rather than throwing) if the HTML is unparseable, so
 * a single malformed page degrades to "no data found" instead of a fatal error.
 */
function makeXPath(string $html): DOMXPath {
    $dom = new DOMDocument();
    if (trim($html) !== '') {
        $priorSetting = libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($priorSetting);
    }
    return new DOMXPath($dom);
}

/**
 * Parse "Beratungen" (consultations) HTML (already fetched) for one kvonr.
 */
function parseBeratungenHtml(string $html): array {
    $beratungen = [];
    $xpath = makeXPath($html);

    $cards = $xpath->query('//div[contains(@class, "card") and contains(@class, "card-light")]');
    foreach ($cards as $card) {
        $titleNode = $xpath->query('.//button[contains(@class, "btn-link")]', $card);
        $title = '';
        if ($titleNode->length > 0) {
            $buttonClone = $titleNode->item(0)->cloneNode(true);
            $badges = $xpath->query('.//span[contains(@class, "smc-badges")]', $buttonClone);
            foreach ($badges as $badge) {
                $badge->parentNode->removeChild($badge);
            }
            $title = trim($buttonClone->nodeValue);
        }

        $sessionLinkNode = $xpath->query('.//a[contains(@title, "Details anzeigen")]/@href', $card);
        $sessionUrl = $sessionLinkNode->length > 0 ? cleanHref($sessionLinkNode->item(0)->nodeValue) : '';

        $beratungDocuments = [];
        $docContainers = $xpath->query('.//div[contains(@class, "smc-dg-ds-1")]', $card);
        foreach ($docContainers as $docContainer) {
            $nameLink = $xpath->query('.//div[contains(@class, "smc-el-h")]/a', $docContainer);
            if ($nameLink->length > 0) {
                $docName = trim($nameLink->item(0)->nodeValue);
                $docHref = $nameLink->item(0)->getAttribute('href');
                if (!empty($docName) && !empty($docHref)) {
                    $beratungDocuments[] = [
                        'name' => cleanText($docName),
                        'url' => cleanHref($docHref),
                    ];
                }
            }
        }

        if (!empty($title)) {
            $beratungen[] = [
                'title' => cleanText($title),
                'url' => $sessionUrl,
                'documents' => $beratungDocuments,
            ];
        }
    }

    return $beratungen;
}

/**
 * Parse a detail page (already fetched) for metadata fields (excluding Beratungen,
 * which is fetched/parsed separately and merged in afterwards).
 */
function parseDetailHtml(string $html): array {
    $metadata = ['betreff' => '', 'vorlage' => '', 'aktenzeichen' => '', 'art' => ''];
    $xpath = makeXPath($html);

    $fieldMap = [
        'betreff' => 'vobetr',
        'vorlage' => 'voname',
        'aktenzeichen' => 'voakz',
        'art' => 'vovaname',
    ];
    foreach ($fieldMap as $key => $class) {
        $node = $xpath->query("//div[contains(@class,\"$class\") and not(contains(@class,\"_title\"))]");
        if ($node->length > 0) {
            $metadata[$key] = trim($node->item(0)->nodeValue);
        }
    }

    return $metadata;
}

/**
 * Parse basic row-level data from the main listing page (title, link, date,
 * tag, inline documents). Does not touch detail/Beratungen pages — those are
 * resolved afterwards in a batched, concurrent pass.
 */
function parseListRows(string $html, int $maxEntries): array {
    $xpath = makeXPath($html);
    $rows = $xpath->query('//tr[contains(@class,"smc-t-r-l")]');

    $items = [];
    $count = 0;
    foreach ($rows as $row) {
        if ($count >= $maxEntries) {
            break;
        }

        try {
            $titleAttr = $xpath->query('descendant::td[contains(@class,"dovorgang")]/a/@title', $row);
            $title = '';
            if ($titleAttr->length > 0) {
                $fullTitle = $titleAttr->item(0)->nodeValue;
                $colonPos = strpos($fullTitle, ': ');
                $title = $colonPos !== false ? substr($fullTitle, $colonPos + 2) : $fullTitle;
            }

            $linkNode = $xpath->query('descendant::td[contains(@class,"dovorgang")]/a/@href', $row);
            $link = $linkNode->length > 0 ? $linkNode->item(0)->nodeValue : '';

            $dateNode = $xpath->query('descendant::ul[contains(@class,"smc-detail-list")]/li[1]', $row);
            $date = $dateNode->length > 0 ? trim($dateNode->item(0)->nodeValue) : '';

            $tagNode = $xpath->query('descendant::td[contains(@class,"doart")]', $row);
            $tag = $tagNode->length > 0 ? trim($tagNode->item(0)->nodeValue) : '';

            $documents = [];
            $docContainers = $xpath->query('descendant::td[contains(@class,"xxdocs")]//div[contains(@class,"smc-dg-ds-1")]', $row);
            foreach ($docContainers as $docContainer) {
                $nameLink = $xpath->query('.//div[contains(@class,"smc-el-h")]/a', $docContainer);
                if ($nameLink->length > 0) {
                    $docName = trim($nameLink->item(0)->nodeValue);
                    $docHref = $nameLink->item(0)->getAttribute('href');
                    $docDateNode = $xpath->query('.//ul[contains(@class,"smc-detail-list")]/li[1]', $docContainer);
                    $docDate = $docDateNode->length > 0 ? trim($docDateNode->item(0)->nodeValue) : $date;

                    if (!empty($docName) && !empty($docHref)) {
                        $documents[] = [
                            'name' => cleanText($docName),
                            'url' => cleanHref($docHref),
                            'date' => $docDate,
                        ];
                    }
                }
            }

            if (!empty($title) && !empty($link)) {
                $items[] = [
                    'title' => cleanText($title),
                    'link' => cleanHref($link),
                    'date' => $date,
                    'tag' => cleanText($tag),
                    'documents' => $documents,
                ];
                $count++;
            }
        } catch (Exception $e) {
            continue;
        }
    }

    return $items;
}

/**
 * Given the basic list items, resolve detail-page and Beratungen metadata for
 * all of them concurrently, then build the final content HTML for each item.
 */
function enrichItemsWithDetails(array $items, bool $bypassCache): array {
    // --- Phase 1: fetch all detail pages concurrently (cache-aware) ---
    $detailUrls = array_column($items, 'link');
    $detailHtmlByUrl = fetchHtmlBatch($detailUrls, CACHE_TTL_DETAIL, $bypassCache);

    // Parse each detail page and extract its kvonr (needed for Beratungen)
    $detailMetaByUrl = [];
    $kvonrByUrl = [];
    foreach ($detailHtmlByUrl as $url => $html) {
        $detailMetaByUrl[$url] = parseDetailHtml($html);
        if (preg_match('/__kvonr=(\d+)/', $url, $m)) {
            $kvonrByUrl[$url] = $m[1];
        }
    }

    // --- Phase 2: fetch all Beratungen pages concurrently (cache-aware) ---
    $beratungenUrlByKvonr = [];
    foreach ($kvonrByUrl as $kvonr) {
        $beratungenUrlByKvonr[$kvonr] = BASE_URL . 'vo0053.asp?__kvonr=' . $kvonr;
    }
    $beratungenHtmlByUrl = fetchHtmlBatch(array_values($beratungenUrlByKvonr), CACHE_TTL_DETAIL, $bypassCache);

    $beratungenByKvonr = [];
    foreach ($beratungenUrlByKvonr as $kvonr => $burl) {
        if (isset($beratungenHtmlByUrl[$burl])) {
            $beratungenByKvonr[$kvonr] = parseBeratungenHtml($beratungenHtmlByUrl[$burl]);
        }
    }

    // --- Phase 3: build final content for each item ---
    foreach ($items as &$item) {
        $url = $item['link'];
        $meta = $detailMetaByUrl[$url] ?? ['betreff' => '', 'vorlage' => '', 'aktenzeichen' => '', 'art' => ''];
        $kvonr = $kvonrByUrl[$url] ?? null;
        $beratungen = $kvonr !== null ? ($beratungenByKvonr[$kvonr] ?? []) : [];

        $item['content'] = buildItemContent($meta, $item['documents'], $beratungen);
    }
    unset($item);

    return $items;
}

/**
 * Build the HTML content block for a single feed entry.
 */
function buildItemContent(array $meta, array $documents, array $beratungen): string {
    $parts = [];

    $parts[] = '<div class="metadata" style="line-height: 1.2;">';
    $metadataLines = [];
    if (!empty($meta['betreff'])) {
        $metadataLines[] = '<strong>Betreff:</strong> ' . cleanText($meta['betreff']);
    }
    if (!empty($meta['vorlage'])) {
        $metadataLines[] = '<strong>Vorlage:</strong> ' . cleanText($meta['vorlage']);
    }
    if (!empty($meta['aktenzeichen'])) {
        $metadataLines[] = '<strong>Aktenzeichen:</strong> ' . cleanText($meta['aktenzeichen']);
    }
    if (!empty($meta['art'])) {
        $metadataLines[] = '<strong>Art:</strong> ' . cleanText($meta['art']);
    }
    $parts[] = implode('<br>', $metadataLines);
    $parts[] = '</div>' . PHP_EOL;

    if (!empty($documents)) {
        $docLines = ['<div class="documents"><strong>Dokumente:</strong><ul>'];
        foreach ($documents as $doc) {
            $docLines[] = sprintf(
                '  <li><a href="%s">%s</a> <span class="date">(%s)</span></li>',
                htmlspecialchars($doc['url'], ENT_XML1),
                $doc['name'],
                htmlspecialchars($doc['date'] ?? '', ENT_XML1, 'UTF-8')
            );
        }
        $docLines[] = '</ul></div>';
        $parts[] = implode(PHP_EOL, $docLines) . PHP_EOL;
    }

    if (!empty($beratungen)) {
        $bLines = ['<div class="beratungen"><strong>Beratungen:</strong><ul>'];
        foreach ($beratungen as $beratung) {
            $bLines[] = sprintf('  <li><a href="%s">%s</a>', htmlspecialchars($beratung['url'], ENT_XML1), $beratung['title']);
            if (!empty($beratung['documents'])) {
                $bLines[] = '<ul>';
                foreach ($beratung['documents'] as $doc) {
                    $bLines[] = sprintf('    <li><a href="%s">%s</a></li>', htmlspecialchars($doc['url'], ENT_XML1), $doc['name']);
                }
                $bLines[] = '</ul>';
            }
            $bLines[] = '</li>';
        }
        $bLines[] = '</ul></div>';
        $parts[] = implode(PHP_EOL, $bLines) . PHP_EOL;
    }

    return implode('', $parts);
}

// ---------------------------------------------------------------------
// Atom output
// ---------------------------------------------------------------------

/**
 * Safely wrap content in a CDATA section, splitting any literal "]]>"
 * sequence the content might contain (which would otherwise prematurely
 * terminate the CDATA block and corrupt the XML).
 */
function wrapCdata(string $content): string {
    $escaped = str_replace(']]>', ']]]]><![CDATA[>', $content);
    return '<![CDATA[' . $escaped . ']]>';
}

function toAtom(array $items): string {
    $now = date('c');
    $feedUrlXml = htmlspecialchars(FEED_URL, ENT_XML1);
    $feedTitleXml = htmlspecialchars(FEED_TITLE, ENT_XML1);

    $entries = '';
    foreach ($items as $item) {
        $date = $now;
        if (!empty($item['date'])) {
            $cleanDate = preg_replace('/[^\d.]/', '', $item['date']);
            $dateObj = DateTime::createFromFormat('d.m.Y', $cleanDate)
                ?: DateTime::createFromFormat('Y-m-d', $cleanDate)
                ?: DateTime::createFromFormat('d/m/Y', $cleanDate);
            // createFromFormat can return an object for malformed-but-partially-
            // matching input (e.g. "31.02.2026"); getLastErrors() catches that.
            if ($dateObj instanceof DateTime) {
                $errors = DateTime::getLastErrors();
                $hasIssues = $errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);
                if (!$hasIssues) {
                    $date = $dateObj->format('c');
                }
            }
        }

        $link = $item['link'] ?? '';
        $title = $item['title'] ?? '';
        $content = $item['content'] ?? '';
        if ($link === '' || $title === '') {
            // Defensive: skip any item that lost its required fields somewhere
            // upstream rather than emitting an invalid <entry>.
            continue;
        }

        $linkXml = htmlspecialchars($link, ENT_XML1);
        $titleXml = $title; // already passed through cleanText() upstream

        $entry = "  <entry>\n"
            . "    <id>{$linkXml}</id>\n"
            . "    <title>{$titleXml}</title>\n"
            . "    <link href=\"{$linkXml}\"/>\n"
            . "    <updated>{$date}</updated>\n";

        if (!empty($item['tag'])) {
            $tagXml = htmlspecialchars($item['tag'], ENT_XML1);
            $entry .= "    <category term=\"{$tagXml}\"/>\n";
        }

        $entry .= "    <content type=\"html\">" . wrapCdata($content) . "</content>\n"
            . "  </entry>\n";

        $entries .= $entry;
    }

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <id>{$feedUrlXml}</id>
  <title>{$feedTitleXml}</title>
  <link href="{$feedUrlXml}"/>
  <updated>{$now}</updated>
  <generator uri="https://github.com/FreshRSS/FreshRSS" version="1.0">FreshRSS Adapter</generator>
  {$entries}</feed>
XML;
}

/**
 * Sanity-check the XML we just built before caching/serving it, so a
 * parsing bug surfaces as a clear 500 instead of a broken feed download.
 */
function assertValidFeedXml(string $xml): void {
    if (trim($xml) === '') {
        throw new RuntimeException('Assembled feed is empty.');
    }
    $priorSetting = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $dom = new DOMDocument();
    $ok = $dom->loadXML($xml, LIBXML_NONET);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($priorSetting);

    if (!$ok) {
        $firstError = $errors[0]->message ?? 'unknown XML error';
        throw new RuntimeException('Assembled feed is not well-formed XML: ' . trim($firstError));
    }
    if ($dom->documentElement === null || $dom->documentElement->localName !== 'feed') {
        throw new RuntimeException('Assembled feed is missing its root <feed> element.');
    }
}

// ---------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------

try {
    // --- Whole-feed cache: skip everything on a hit ---
    // Keyed by max-entries since that changes the assembled output.
    $feedCacheKey = 'feed:max-entries=' . $maxEntries;
    $atom = $noCache ? null : cacheGet($feedCacheKey, CACHE_TTL_FEED);

    if ($atom === null) {
        $listHtml = fetchHtml(FEED_URL, CACHE_TTL_LIST, $noCache);
        $items = parseListRows($listHtml, $maxEntries);
        $items = enrichItemsWithDetails($items, $noCache);
        $atom = toAtom($items);
        cacheSet($feedCacheKey, $atom);
    }

    cacheCleanup(max(CACHE_TTL_LIST, CACHE_TTL_DETAIL, CACHE_TTL_FEED) * 3);

    // --- HTTP-level conditional caching (ETag / Last-Modified) ---
    // Lets FreshRSS (or any conditional-GET-aware reader) get a 304 with no
    // body at all when it already has the current version, instead of
    // re-downloading and re-parsing the full feed on every poll.
    $etag = '"' . sha1($atom) . '"';
    $lastModifiedTs = filemtime(cacheFilePath($feedCacheKey)) ?: time();
    $lastModified = gmdate('D, d M Y H:i:s', $lastModifiedTs) . ' GMT';

    header('Content-Type: application/atom+xml; charset=utf-8');
    header('Cache-Control: public, max-age=' . CACHE_TTL_FEED);
    header('ETag: ' . $etag);
    header('Last-Modified: ' . $lastModified);

    $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? null;
    $clientSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? null;
    $notModified = ($clientEtag !== null && trim($clientEtag) === $etag)
        || ($clientSince !== null && strtotime($clientSince) !== false && strtotime($clientSince) >= $lastModifiedTs);

    if ($notModified) {
        http_response_code(304);
        exit;
    }

    echo $atom;
} catch (RuntimeException $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'Error: ' . $e->getMessage();
}