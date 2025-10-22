<?php
// Small index with links to all docket pages
require_once 'config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Court Docket Analyzer — Index</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="container">
    <h1>Court Docket Analyzer</h1>
    <p>Select a docket range to view or update:</p>
    <ul>
        <li><a href="docket_24.php">24 Hour Docket</a></li>
        <li><a href="docket_48.php">48 Hour Docket</a></li>
        <li><a href="docket_5day.php">5 Day Docket</a></li>
        <li><a href="docket_15day.php">15 Day Docket</a></li>
        <li><a href="docket_30day.php">30 Day Docket</a></li>
    </ul>
    <p>Use the "Update Now" button on any page to fetch and save the latest data for that range.</p>
</div>
</body>
</html>