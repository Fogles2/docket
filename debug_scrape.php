<?php

// debug_scrape.php

// Usage (CLI): php debug_scrape.php 01

// Usage (browser): debug_scrape.php?days=01

$days = $argv[1] ?? ($_GET['days'] ?? '01');

require_once __DIR__ . '/scraper.php';

$scraper = new Scraper();

$start = microtime(true);

$records = $scraper->scrapeDocket($days);

$duration = microtime(true) - $start;

$report = [

    'days' => str_pad($days, 2, '0', STR_PAD_LEFT),

    'duration_seconds' => round($duration, 3),

    'total_records_parsed' => is_array($records) ? count($records) : 0,

    'sample_records' => array_slice($records ?: [], 0, 50),

    'scraper_log' => file_exists(__DIR__ . '/scraper.log') ? __DIR__ . '/scraper.log' : null,

    'last_docket_files_pattern' => __DIR__ . '/last_docket_' . str_pad($days, 2, '0', STR_PAD_LEFT) . '_page*.html',

];

header('Content-Type: application/json; charset=utf-8');

echo json_encode($report, JSON_PRETTY_PRINT);


