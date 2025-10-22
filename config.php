<?php
// Error reporting (adjust for production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// App settings
define('DB_FILE', __DIR__ . '/docket.db');
define('DOCKET_URL', 'https://weba.claytoncountyga.gov/sjiinqcgi-bin/wsj210r.pgm');
date_default_timezone_set('America/New_York');

// Security headers (sent by PHP)
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");