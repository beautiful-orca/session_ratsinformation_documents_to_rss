<?php
// Sessionnet Duisburg Ratsinformation → Atom Feed Adapter
// Fetches documents and "Beratungen" (consultations) from Sessionnet Duisburg
// Usage: Access this script directly (e.g., /session_ratsinformation_documents_to_rss.php)
// Optional: ?max-entries=20 to limit the number of entries (default: 20)
// Optional: ?no-cache=1 to bypass the cache for this request

const FEED_URL = 'https://sessionnet.owl-it.de/duisburg/bi/do0040.asp';
const FEED_TITLE = 'Ratsinformation Duisburg';
const BASE_URL = 'https://sessionnet.owl-it.de/duisburg/bi/';

// Configurable via URL parameter: ?max-entries=20
$maxEntries = isset($_GET['max-entries']) ? max(1, (int)$_GET['max-entries']) : 20;

// --- Cache configuration ---
// Cache directory (created if missing)
define('CACHE_DIR', sys_get_temp_dir() . '/sessionnet_duisburg_cache');
// How long cached pages stay valid, in seconds.
// The main listing page changes more often than detail/Beratungen pages,
// which rarely change once published, so they get different TTLs.
define('CACHE_TTL_LIST', 15 * 60);        // 15 minutes for the main list page
define('CACHE_TTL_DETAIL', 24 * 60 * 60); // 24 hours for detail & Beratungen pages
// ?no-cache=1 bypasses reading the cache for this request (still refreshes it)
$noCache = isset($_GET['no-cache']) && $_GET['no-cache'] == '1';

/**
 * Ensure the cache directory exists.
 */
function ensureCacheDir(): void {
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0775, true);
    }
}

/**
 * Build a cache file path for a given URL.
 */
function cacheFilePath(string $url): string {
    return CACHE_DIR . '/' . sha1($url) . '.cache';
}

/**
 * Read a cached value if present and not expired.
 * Returns null if there's no valid cache entry.
 */
function cacheGet(string $key, int $ttl): ?string {
    $path = cacheFilePath($key);
    if (!is_file($path)) {
        return null;
    }
    if (time() - filemtime($path) > $ttl) {
        return null;
    }
    $data = @file_get_contents($path);
    return $data !== false ? $data : null;
}

/**
 * Write a value to the cache (atomically, to be safe under concurrent requests).
 */
function cacheSet(string $key, string $value): void {
    ensureCacheDir();
    $path = cacheFilePath($key);
    $tmpPath = $path . '.' . uniqid('', true) . '.tmp';
    if (@file_put_contents($tmpPath, $value) !== false) {
        @rename($tmpPath, $path);
    } else {
        @unlink($tmpPath);
    }
}

/**
 * Opportunistically remove expired cache files.
 * Runs on a small fraction of requests to keep overhead low.
 */
function cacheCleanup(int $maxAge): void {
    if (!is_dir(CACHE_DIR)) {
        return;
    }
    if (mt_rand(1, 100) > 5) {
        return; // only run cleanup ~5% of the time
    }
    foreach (glob(CACHE_DIR . '/*.cache') ?: [] as $file) {
        if (time() - filemtime($file) > $maxAge) {
            @unlink($file);
        }
    }
}

/**
 * Clean text for XML output
 */
function cleanText(string $text): string {
    $text = str_replace(
        ['Ã¤', 'Ã¶', 'Ã¼', 'Ã', 'Ã©', 'Ã¨', 'Ã', 'lÃ¤', 'lÃ¶', 'lÃ¼', 'LÃ¤', 'LÃ¶', 'LÃ¼', 'Ã¶', 'Ã¼', 'Ã¤'],
        ['ä', 'ö', 'ü', 'Á', 'é', 'è', 'À', 'lä', 'lö', 'lü', 'Lä', 'Lö', 'Lü', 'ö', 'ü', 'ä'],
        $text
    );
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    return htmlspecialchars($text, ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Clean and normalize URL
 */
function cleanHref(string $href): string {
    $href = urldecode($href);
    if (!preg_match('/^https?:\/\//i', $href)) {
        $href = BASE_URL . ltrim($href, '/');
    }
    return $href;
}

/**
 * Fetch HTML content from URL, transparently using a local cache.
 *
 * @param string $url        URL to fetch
 * @param int    $ttl        Cache lifetime in seconds for this URL
 * @param bool   $bypassCache If true, skip reading from cache (still writes fresh result)
 */
function fetchHtml(string $url, int $ttl = CACHE_TTL_DETAIL, bool $bypassCache = false): string {
    if (!$bypassCache) {
        $cached = cacheGet($url, $ttl);
        if ($cached !== null) {
            return $cached;
        }
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (compatible; FreshRSS-Adapter/1.0)',
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: de,en-US;q=0.7,en;q=0.3',
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $html = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        // Fall back to a stale cache entry (if any) rather than failing outright
        $stale = cacheGet($url, PHP_INT_MAX);
        if ($stale !== null) {
            return $stale;
        }
        throw new RuntimeException('cURL error: ' . $error);
    }

    if ($httpCode !== 200) {
        $stale = cacheGet($url, PHP_INT_MAX);
        if ($stale !== null) {
            return $stale;
        }
        throw new RuntimeException("HTTP error: $httpCode");
    }

    if (empty($html)) {
        $stale = cacheGet($url, PHP_INT_MAX);
        if ($stale !== null) {
            return $stale;
        }
        throw new RuntimeException('Empty response from URL: ' . $url);
    }

    $html = mb_convert_encoding($html, 'UTF-8', mb_detect_encoding($html, 'UTF-8, ISO-8859-1', true));

    cacheSet($url, $html);
    cacheCleanup(max(CACHE_TTL_LIST, CACHE_TTL_DETAIL) * 3);

    return $html;
}

/**
 * Fetch and parse "Beratungen" (consultations) for a given __kvonr
 */
function fetchBeratungen(string $kvonr, bool $bypassCache = false): array {
    $beratungenUrl = BASE_URL . 'vo0053.asp?__kvonr=' . $kvonr;
    $beratungen = [];

    try {
        $html = fetchHtml($beratungenUrl, CACHE_TTL_DETAIL, $bypassCache);
        $dom = new DOMDocument();
        @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new DOMXPath($dom);

        // Extract all consultation cards
        $cards = $xpath->query('//div[contains(@class, "card") and contains(@class, "card-light")]');
        foreach ($cards as $card) {
            // Extract title (date, session, type) and ignore the badge span
            $titleNode = $xpath->query('.//button[contains(@class, "btn-link")]', $card);
            $title = '';
            if ($titleNode->length > 0) {
                $button = $titleNode->item(0);
                // Clone the button to avoid modifying the original DOM
                $buttonClone = $button->cloneNode(true);
                // Remove badge spans (e.g., "2 Dok.")
                $badges = $xpath->query('.//span[contains(@class, "smc-badges")]', $buttonClone);
                foreach ($badges as $badge) {
                    $badge->parentNode->removeChild($badge);
                }
                $title = trim($buttonClone->nodeValue);
            }

            // Extract session URL ("Zur Sitzung ...")
            $sessionLinkNode = $xpath->query('.//a[contains(@title, "Details anzeigen")]/@href', $card);
            $sessionUrl = $sessionLinkNode->length > 0 ? cleanHref($sessionLinkNode->item(0)->nodeValue) : '';

            // Extract documents for this Beratung
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
    } catch (Exception $e) {
        // Silently fail if "Beratungen" tab is unavailable
    }

    return $beratungen;
}

/**
 * Fetch and parse detail page for metadata
 */
function fetchDetailPage(string $url, bool $bypassCache = false): array {
    $metadata = [
        'betreff' => '',
        'vorlage' => '',
        'aktenzeichen' => '',
        'art' => '',
        'beratungen' => [],
    ];

    try {
        $html = fetchHtml($url, CACHE_TTL_DETAIL, $bypassCache);
        $dom = new DOMDocument();
        @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new DOMXPath($dom);

        // Extract __kvonr from the URL (e.g., vo0050.asp?__kvonr=20139490)
        $kvonr = '';
        if (preg_match('/__kvonr=(\d+)/', $url, $matches)) {
            $kvonr = $matches[1];
        }

        // Fetch "Beratungen" if __kvonr is available
        if (!empty($kvonr)) {
            $metadata['beratungen'] = fetchBeratungen($kvonr, $bypassCache);
        }

        // Extract Betreff
        $betreffNode = $xpath->query('//div[contains(@class,"vobetr") and not(contains(@class,"_title"))]');
        if ($betreffNode->length > 0) {
            $metadata['betreff'] = trim($betreffNode->item(0)->nodeValue);
        }

        // Extract Vorlage
        $vorlageNode = $xpath->query('//div[contains(@class,"voname") and not(contains(@class,"_title"))]');
        if ($vorlageNode->length > 0) {
            $metadata['vorlage'] = trim($vorlageNode->item(0)->nodeValue);
        }

        // Extract Aktenzeichen
        $aktenzeichenNode = $xpath->query('//div[contains(@class,"voakz") and not(contains(@class,"_title"))]');
        if ($aktenzeichenNode->length > 0) {
            $metadata['aktenzeichen'] = trim($aktenzeichenNode->item(0)->nodeValue);
        }

        // Extract Art
        $artNode = $xpath->query('//div[contains(@class,"vovaname") and not(contains(@class,"_title"))]');
        if ($artNode->length > 0) {
            $metadata['art'] = trim($artNode->item(0)->nodeValue);
        }
    } catch (Exception $e) {
        // Silently fail if detail page fetch fails
    }

    return $metadata;
}

/**
 * Parse news items from HTML using XPath
 */
function parseItems(string $html, int $maxEntries, bool $bypassCache = false): array {
    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new DOMXPath($dom);

    $items = [];
    $rows = $xpath->query('//tr[contains(@class,"smc-t-r-l")]');

    $count = 0;
    foreach ($rows as $row) {
        if ($count >= $maxEntries) {
            break;
        }

        try {
            // --- Extract title ---
            $titleAttr = $xpath->query('descendant::td[contains(@class,"dovorgang")]/a/@title', $row);
            $title = '';
            if ($titleAttr->length > 0) {
                $fullTitle = $titleAttr->item(0)->nodeValue;
                $colonPos = strpos($fullTitle, ': ');
                $title = $colonPos !== false ? substr($fullTitle, $colonPos + 2) : $fullTitle;
            }

            // --- Extract link ---
            $linkNode = $xpath->query('descendant::td[contains(@class,"dovorgang")]/a/@href', $row);
            $link = $linkNode->length > 0 ? $linkNode->item(0)->nodeValue : '';

            // --- Extract date ---
            $dateNode = $xpath->query('descendant::ul[contains(@class,"smc-detail-list")]/li[1]', $row);
            $date = $dateNode->length > 0 ? trim($dateNode->item(0)->nodeValue) : '';

            // --- Extract tags ---
            $tagNode = $xpath->query('descendant::td[contains(@class,"doart")]', $row);
            $tag = $tagNode->length > 0 ? trim($tagNode->item(0)->nodeValue) : '';

            // --- Extract all documents from xxdocs column ---
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

            // --- Fetch detail page metadata (including Beratungen) ---
            $detailMetadata = [];
            if (!empty($link)) {
                $detailUrl = cleanHref($link);
                $detailMetadata = fetchDetailPage($detailUrl, $bypassCache);
            }

            // Build content with metadata, documents, and Beratungen
            $content = '<div class="metadata" style="line-height: 1.2;">';
            $metadataLines = [];
            if (!empty($detailMetadata['betreff'])) {
                $metadataLines[] = '<strong>Betreff:</strong> ' . cleanText($detailMetadata['betreff']);
            }
            if (!empty($detailMetadata['vorlage'])) {
                $metadataLines[] = '<strong>Vorlage:</strong> ' . cleanText($detailMetadata['vorlage']);
            }
            if (!empty($detailMetadata['aktenzeichen'])) {
                $metadataLines[] = '<strong>Aktenzeichen:</strong> ' . cleanText($detailMetadata['aktenzeichen']);
            }
            if (!empty($detailMetadata['art'])) {
                $metadataLines[] = '<strong>Art:</strong> ' . cleanText($detailMetadata['art']);
            }
            $content .= implode('<br>', $metadataLines);
            $content .= '</div>' . PHP_EOL;

            // Add documents list
            if (!empty($documents)) {
                $content .= '<div class="documents"><strong>Dokumente:</strong><ul>' . PHP_EOL;
                foreach ($documents as $doc) {
                    $content .= sprintf(
                        '  <li><a href="%s">%s</a> <span class="date">(%s)</span></li>' . PHP_EOL,
                        htmlspecialchars($doc['url'], ENT_XML1),
                        $doc['name'],
                        $doc['date']
                    );
                }
                $content .= '</ul></div>' . PHP_EOL;
            }

            // Add Beratungen (consultations) list
            if (!empty($detailMetadata['beratungen'])) {
                $content .= '<div class="beratungen"><strong>Beratungen:</strong><ul>' . PHP_EOL;
                foreach ($detailMetadata['beratungen'] as $beratung) {
                    $content .= sprintf(
                        '  <li><a href="%s">%s</a>' . PHP_EOL,
                        htmlspecialchars($beratung['url'], ENT_XML1),
                        $beratung['title']
                    );
                    // Add documents as subpoints if available
                    if (!empty($beratung['documents'])) {
                        $content .= '<ul>' . PHP_EOL;
                        foreach ($beratung['documents'] as $doc) {
                            $content .= sprintf(
                                '    <li><a href="%s">%s</a></li>' . PHP_EOL,
                                htmlspecialchars($doc['url'], ENT_XML1),
                                $doc['name']
                            );
                        }
                        $content .= '</ul>' . PHP_EOL;
                    }
                    $content .= '</li>' . PHP_EOL;
                }
                $content .= '</ul></div>' . PHP_EOL;
            }

            // Only add items with a title and link
            if (!empty($title) && !empty($link)) {
                $items[] = [
                    'title' => cleanText($title),
                    'content' => $content,
                    'link' => cleanHref($link),
                    'date' => $date,
                    'tag' => cleanText($tag),
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
 * Convert parsed items to Atom feed XML
 */
function toAtom(array $items): string {
    $now = date('c');
    $feedUrlXml = htmlspecialchars(FEED_URL, ENT_XML1);
    $feedTitleXml = htmlspecialchars(FEED_TITLE, ENT_XML1);

    $entries = '';
    foreach ($items as $item) {
        $date = $now;
        if (!empty($item['date'])) {
            $cleanDate = preg_replace('/[^\d.]/', '', $item['date']);
            $dateObj = DateTime::createFromFormat('d.m.Y', $cleanDate);
            if ($dateObj === false) {
                $dateObj = DateTime::createFromFormat('Y-m-d', $cleanDate);
                if ($dateObj === false) {
                    $dateObj = DateTime::createFromFormat('d/m/Y', $cleanDate);
                }
            }
            if ($dateObj !== false) {
                $date = $dateObj->format('c');
            }
        }

        $linkXml = htmlspecialchars($item['link'], ENT_XML1);
        $titleXml = $item['title'];

        $entry = "  <entry>\n"
            . "    <id>{$linkXml}</id>\n"
            . "    <title>{$titleXml}</title>\n"
            . "    <link href=\"{$linkXml}\"/>\n"
            . "    <updated>{$date}</updated>\n";

        if (!empty($item['tag'])) {
            $tagXml = htmlspecialchars($item['tag'], ENT_XML1);
            $entry .= "    <category term=\"{$tagXml}\"/>\n";
        }

        $entry .= "    <content type=\"html\"><![CDATA[" . $item['content'] . "]]></content>\n"
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

// --- Main ---
try {
    $html = fetchHtml(FEED_URL, CACHE_TTL_LIST, $noCache);
    $items = parseItems($html, $maxEntries, $noCache);
    $atom = toAtom($items);

    header('Content-Type: application/atom+xml; charset=utf-8');
    echo $atom;
} catch (RuntimeException $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'Error: ' . $e->getMessage();
}