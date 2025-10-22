<?php
// scraper.php - improved scraper with pagination, pre/table support, logging, per-page JSON
class Scraper {
    private $baseUrl = 'https://weba.claytoncountyga.gov/sjiinqcgi-bin/wsj210r.pgm';
    private $timeout = 30;
    private $maxPages = 200; // safety cap
    private $logFile;

    public function __construct() {
        $this->logFile = __DIR__ . '/scraper.log';
    }

    private function log($msg) {
        $time = date('c');
        @file_put_contents($this->logFile, "[$time] $msg\n", FILE_APPEND | LOCK_EX);
    }

    // Fetch raw HTML for a given url (or construct from days)
    public function fetchRawHtml(string $days = '01', string $url = null) {
        $days = preg_replace('/[^0-9]/', '', $days);
        if ($days === '') $days = '01';
        if ($url === null) {
            $url = $this->baseUrl . '?days=' . str_pad($days, 2, '0', STR_PAD_LEFT);
        }

        $this->log("Fetching URL: $url");

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; CourtDocketAnalyzer/1.0)');
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
            $html = curl_exec($ch);
            $err = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($html === false || $code >= 400) {
                $this->log("HTTP error fetching $url : code=$code err=$err");
                return false;
            }
            return $html;
        }

        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Mozilla/5.0\r\n",
                'timeout' => $this->timeout
            ]
        ];
        $context = stream_context_create($opts);
        $html = @file_get_contents($url, false, $context);
        if ($html === false) {
            $this->log("file_get_contents failed for $url");
            return false;
        }
        return $html;
    }

    // Crawl multiple pages for a given days value and return combined unique records
    public function scrapeDocket(string $days = '01') {
        $days = preg_replace('/[^0-9]/', '', $days);
        if ($days === '') $days = '01';
        $startUrl = $this->baseUrl . '?days=' . str_pad($days, 2, '0', STR_PAD_LEFT);

        $toVisit = [$startUrl];
        $visited = [];
        $allRecords = [];
        $pageIndex = 0;
        $safeDays = str_pad($days, 2, '0', STR_PAD_LEFT);

        while (!empty($toVisit) && count($visited) < $this->maxPages) {
            $url = array_shift($toVisit);
            if (isset($visited[$url])) continue;
            $visited[$url] = true;
            $pageIndex++;

            $html = $this->fetchRawHtml($days, $url);
            if ($html === false) {
                $this->log("Failed to fetch page: $url (skipping)");
                continue;
            }

            // Save raw HTML for debugging with page index
            $pageFile = __DIR__ . "/last_docket_{$safeDays}_page{$pageIndex}.html";
            @file_put_contents($pageFile, $html);

            // Parse records on this page
            $records = $this->parseDocketData($html);

            $this->log("Page {$pageIndex} ({$url}) -> parsed " . count($records) . " records");

            // Save per-page parsed JSON for inspection
            @file_put_contents(__DIR__ . "/last_docket_{$safeDays}_page{$pageIndex}.json", json_encode($records, JSON_PRETTY_PRINT));

            if (!empty($records)) {
                $allRecords = array_merge($allRecords, $records);
            }

            // Discover pagination links and queue them
            $newLinks = $this->discoverPaginationLinks($html, $url, $days);
            $this->log("Discovered " . count($newLinks) . " new pagination links on page {$pageIndex}");
            foreach ($newLinks as $link) {
                if (!isset($visited[$link]) && !in_array($link, $toVisit, true)) {
                    $toVisit[] = $link;
                }
            }
        }

        // De-duplicate records by a composite key: case_number + charge if case_number present,
        // otherwise use md5(charge+defendant+dob) to avoid collapsing entries with empty case_number.
        $unique = [];
        $clean = [];
        foreach ($allRecords as $r) {
            $case = trim($r['case_number'] ?? '');
            $charge = trim($r['charge'] ?? '');
            if ($case !== '') {
                $key = 'case:' . $case . '||charge:' . $charge;
            } else {
                $key = 'hash:' . md5(($r['charge'] ?? '') . '||' . ($r['defendant'] ?? '') . '||' . ($r['dob'] ?? ''));
            }
            if (!isset($unique[$key])) {
                $unique[$key] = true;
                $clean[] = $r;
            }
        }

        $this->log("Scrape complete for days={$safeDays}: pages=" . count($visited) . ", parsed_total=" . count($allRecords) . ", unique=" . count($clean));
        return $clean;
    }

    // Discover pagination links; tries anchors AND form GET actions
    private function discoverPaginationLinks($html, $currentUrl, $expectedDays) {
        $links = [];

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (@$dom->loadHTML($html) === false) {
            return $links;
        }
        $xpath = new DOMXPath($dom);

        // 1) Anchors
        $anchorNodes = $xpath->query('//a[@href]');
        foreach ($anchorNodes as $a) {
            $href = $a->getAttribute('href');
            if (!$href) continue;
            if (stripos($href, 'javascript:') === 0 || stripos($href, 'mailto:') === 0) continue;
            $abs = $this->resolveUrl($href, $currentUrl);
            if ($abs === null) continue;
            // Only consider links that include wsj210r.pgm or the days param
            if (stripos($abs, 'wsj210r.pgm') === false && stripos($abs, 'days=') === false) continue;
            // If days param present, ensure it matches expectedDays
            if (preg_match('/[?&]days=([0-9]{1,2})/i', $abs, $m)) {
                $d = str_pad($m[1], 2, '0', STR_PAD_LEFT);
                if ($d !== str_pad($expectedDays, 2, '0', STR_PAD_LEFT)) continue;
            }
            $links[] = $abs;
        }

        // 2) Forms with GET method pointing to same script (construct example GET URLs)
        $forms = $xpath->query('//form[@action]');
        foreach ($forms as $form) {
            $method = strtoupper($form->getAttribute('method') ?: 'GET');
            if ($method !== 'GET') continue;
            $action = $form->getAttribute('action');
            $absAction = $this->resolveUrl($action, $currentUrl);
            if ($absAction === null) continue;
            // only consider if same script or days present
            if (stripos($absAction, 'wsj210r.pgm') === false && stripos($absAction, 'days=') === false) continue;
            $inputs = $xpath->query('.//input[@name]', $form);
            $params = [];
            foreach ($inputs as $inp) {
                $name = $inp->getAttribute('name');
                $value = $inp->getAttribute('value') ?? '';
                $params[$name] = $value;
            }
            $url = $absAction;
            if (!empty($params)) {
                $url .= (strpos($absAction, '?') === false ? '?' : '&') . http_build_query($params);
            }
            $links[] = $url;
        }

        $links = array_values(array_unique($links));
        return $links;
    }

    // Resolve maybe-relative href to absolute URL
    private function resolveUrl($href, $base) {
        $href = trim($href);
        if ($href === '') return null;
        if (preg_match('/^https?:\\/\\//i', $href)) return $href;
        if (strpos($href, '//') === 0) {
            $parsedBase = parse_url($base);
            $scheme = $parsedBase['scheme'] ?? 'https';
            return $scheme . ':' . $href;
        }
        if (strpos($href, '/') === 0) {
            $p = parse_url($base);
            if (!isset($p['scheme']) || !isset($p['host'])) return null;
            $port = isset($p['port']) ? ":{$p['port']}" : '';
            return "{$p['scheme']}://{$p['host']}{$port}{$href}";
        }
        $p = parse_url($base);
        if ($p === false) return null;
        $scheme = $p['scheme'] ?? 'https';
        $host = $p['host'] ?? '';
        $port = isset($p['port']) ? ":{$p['port']}" : '';
        $path = $p['path'] ?? '/';
        if (substr($path, -1) !== '/') {
            $path = substr($path, 0, strrpos($path, '/') + 1);
        }
        $abs = "{$scheme}://{$host}{$port}{$path}{$href}";
        return $this->normalizePath($abs);
    }

    private function normalizePath($url) {
        $parts = parse_url($url);
        if ($parts === false) return $url;
        $path = $parts['path'] ?? '';
        $segments = explode('/', $path);
        $out = [];
        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '.') continue;
            if ($seg === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $seg;
        }
        $normalizedPath = '/' . implode('/', $out);
        $result = $parts['scheme'] . '://' . $parts['host'] . $normalizedPath;
        if (!empty($parts['query'])) $result .= '?' . $parts['query'];
        if (!empty($parts['fragment'])) $result .= '#' . $parts['fragment'];
        return $result;
    }

    // Parse HTML and return an array of records (more aggressive)
    public function parseDocketData($html) {
        $records = [];
        if (!$html) return $records;

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);

        // 1) If there's a <pre> block (many dockets are plain text), parse it line-by-line
        $preNodes = $xpath->query('//pre');
        if ($preNodes->length > 0) {
            foreach ($preNodes as $pre) {
                $text = trim($pre->textContent);
                if ($text === '') continue;
                $lines = preg_split("/\r\n|\n|\r/", $text);
                // group lines into records - heuristic: blank lines separate records
                $buffer = [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        if (!empty($buffer)) {
                            $block = implode(' ', $buffer);
                            $r = $this->parseBlockText($block);
                            if ($r) $records[] = $r;
                            $buffer = [];
                        }
                    } else {
                        $buffer[] = $line;
                    }
                }
                if (!empty($buffer)) {
                    $block = implode(' ', $buffer);
                    $r = $this->parseBlockText($block);
                    if ($r) $records[] = $r;
                }
            }
        }

        // 2) Try table rows (most structured dockets)
        if (empty($records)) {
            $tables = $xpath->query('//table');
            foreach ($tables as $table) {
                $rows = $xpath->query('.//tr', $table);
                foreach ($rows as $row) {
                    $cells = $xpath->query('.//td|.//th', $row);
                    $rowText = [];
                    foreach ($cells as $cell) {
                        $rowText[] = trim(preg_replace('/\s+/', ' ', $cell->textContent));
                    }
                    $text = trim(implode(' | ', $rowText));
                    if ($text === '') continue;
                    $r = $this->parseBlockText($text);
                    if ($r) $records[] = $r;
                }
            }
        }

        // 3) Paragraphs/divs fallback
        if (empty($records)) {
            $nodes = $xpath->query('//div | //p | //td');
            foreach ($nodes as $node) {
                $text = trim(preg_replace('/\s+/', ' ', $node->textContent));
                if ($text === '') continue;
                // only examine blocks that look like cases
                if (stripos($text, 'charge') !== false || stripos($text, 'case') !== false || stripos($text, 'defendant') !== false) {
                    $r = $this->parseBlockText($text);
                    if ($r) $records[] = $r;
                }
            }
        }

        // 4) Final regex fallback across entire HTML
        if (empty($records)) {
            preg_match_all('/(Case(?:\\s*(?:No\\.?|#|Number))?[:\\s]*[A-Za-z0-9\\-\\/]+).*?Charge[:\\s]*([^\\n\\r<]+)/is', $html, $m, PREG_SET_ORDER);
            foreach ($m as $match) {
                $block = $match[0];
                $r = $this->parseBlockText($block);
                if ($r) $records[] = $r;
            }
        }

        return $records;
    }

    // Parse a block of text and extract fields; returns record array or null
    private function parseBlockText($text) {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if ($text === '') return null;

        // 1) If text looks like a multi-column fixed-width row (lots of multiple spaces), try splitting
        if (preg_match('/\s{2,}/', $text)) {
            $parts = preg_split('/\s{2,}/', $text);
            $case = '';
            $defendant = '';
            $dob = '';
            $sex = '';
            $charge = '';

            // Search parts for recognizable fields
            foreach ($parts as $i => $p) {
                $pTrim = trim($p);
                if ($case === '' && preg_match('/[A-Za-z0-9]{2,}[-\/]?[A-Za-z0-9]*/', $pTrim)) {
                    $case = $pTrim;
                    continue;
                }
                if ($dob === '' && preg_match('/\b(\d{1,2}\/\d{1,2}\/\d{4}|\d{4}-\d{2}-\d{2})\b/', $pTrim, $m)) {
                    $dob = $m[1];
                    continue;
                }
                if ($sex === '' && preg_match('/\b(M|F|Male|Female)\b/i', $pTrim, $m2)) {
                    $sex = strtoupper(substr($m2[1],0,1));
                    continue;
                }
            }

            // Join last columns as charge candidate
            if (!empty($parts)) {
                $n = count($parts);
                $last = trim($parts[$n-1]);
                if (preg_match('/\b(theft|possession|assault|battery|dui|fraud|robbery|weapon|sexual|shoplift|marijuana|cocaine|heroin)\b/i', $last)) {
                    $charge = $last;
                } else {
                    if ($n >= 2) $charge = trim($parts[$n-2] . ' ' . $parts[$n-1]);
                    else $charge = $last;
                }
            }

            // Defendant guess: middle parts not matched as case/dob/sex/charge
            $possibleNames = [];
            foreach ($parts as $p) {
                $pTrim = trim($p);
                if ($pTrim === $case || $pTrim === $dob) continue;
                if ($sex !== '' && stripos($pTrim, $sex) !== false) continue;
                if ($charge !== '' && stripos($charge, $pTrim) !== false) continue;
                $possibleNames[] = $pTrim;
            }
            if (!empty($possibleNames)) {
                $defendant = trim(implode(' ', $possibleNames));
            }

            if ($case !== '' || $charge !== '') {
                return [
                    'case_number' => $case,
                    'defendant' => $defendant,
                    'dob' => $dob,
                    'sex' => $sex,
                    'charge' => $charge,
                ];
            }
        }

        // 2) Fallback heuristics
        $case = $this->matchFirst('/Case\\s*(?:No\\.?|#|Number)?[:\\s]*([A-Za-z0-9\\-\\/]+)/i', $text, 1);
        $defendant = $this->matchFirst('/Defendant[:\\s]*([^\\|\\n\\r]+)/i', $text, 1);
        $dob = $this->matchFirst('/DOB[:\\s]*(\\d{1,2}\\/\\d{1,2}\\/\\d{4})/i', $text, 1);
        if (!$dob) $dob = $this->matchFirst('/(\\d{4}-\\d{2}-\\d{2})/', $text, 1);
        $sex = $this->matchFirst('/Sex[:\\s]*([MFmf]|Male|Female)/i', $text, 1);
        $charge = $this->matchFirst('/Charge[:\\s]*([^\\|\\n\\r]+)/i', $text, 1);

        if (!$charge) {
            if (preg_match('/(theft|possession|assault|battery|dui|fraud|robbery|weapon|sexual|shoplift|marijuana|cocaine|heroin)/i', $text, $m)) {
                $charge = $m[0];
            }
        }

        $sex = $sex ? strtoupper(substr($sex,0,1)) : '';

        if ($case || $charge) {
            return [
                'case_number' => trim($case ?: ''),
                'defendant' => trim($defendant ?: ''),
                'dob' => trim($dob ?: ''),
                'sex' => $sex,
                'charge' => trim($charge ?: ''),
            ];
        }

        return null;
    }

    private function matchFirst($pattern, $text, $group = 1) {
        if (preg_match($pattern, $text, $m)) return isset($m[$group]) ? trim($m[$group]) : null;
        return null;
    }
}
