<?php
// Common docket page. Set $days (string) and $title before including this file.
if (!isset($days)) {
    die('No days parameter. This page must be included from a wrapper that sets $days.');
}
require_once 'config.php';
require_once 'database.php';
require_once 'scraper.php';
require_once 'categorizer.php';

$db = new Database();
$db->init();

$scraper = new Scraper();
$categorizer = new Categorizer();

$message = '';
$sessionSummary = ['crimes' => [], 'ages' => [], 'sex' => []];
$sessionCount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_now'])) {
    $raw = $scraper->scrapeDocket($days);
    if ($raw === false) {
        $message = "Scrape failed for {$days} day(s). Check server logs and last_docket_{$days}.html.";
    } elseif (empty($raw)) {
        $message = "Scraper returned 0 records for {$days} day(s). Check last_docket_{$days}.html to inspect page HTML.";
        // Save zero results case
    } else {
        $enriched = $categorizer->enrichRecords($raw);
        $saved = $db->saveData($enriched, $days);
        $message = $saved ? "Saved " . count($enriched) . " records for {$days} day(s)." : "Save completed but errors occurred (check logs).";
        $sessionSummary = $categorizer->categorizeSummary($raw);
        $sessionCount = count($enriched);
    }
}

// Stats from DB filtered by this source days
$crimeStats = $db->getCrimeStats($days);
$ageStats = $db->getAgeStats($days);
$sexStats = $db->getSexStats($days);
$lastUpdate = $db->getLastUpdate($days);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo htmlspecialchars($title ?? "Docket {$days} days"); ?></title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div class="container">
    <h1><?php echo htmlspecialchars($title ?? "Docket: {$days} day(s)"); ?></h1>

    <div class="controls">
        <form method="post" style="display:inline-block;">
            <button type="submit" name="update_now" class="btn">Update Now (fetch <?php echo htmlspecialchars($days); ?>)</button>
        </form>
        <div class="last-update">
            Last saved record date:
            <?php if ($lastUpdate): ?>
                <strong><?php echo htmlspecialchars($lastUpdate); ?></strong>
            <?php else: ?>
                <strong>Never</strong>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <section class="stats-container">
        <div class="stats-box">
            <h2>Session Summary (this fetch)</h2>
            <?php if ($sessionCount === 0): ?>
                <div class="stat-item">No session data</div>
            <?php else: ?>
                <div class="stat-item">
                    <span class="label">Records parsed in session</span>
                    <span class="count"><?php echo (int)$sessionCount; ?></span>
                </div>
                <?php if (!empty($sessionSummary['crimes'])): ?>
                    <?php foreach ($sessionSummary['crimes'] as $k => $v): ?>
                        <div class="stat-item">
                            <span class="label"><?php echo htmlspecialchars($k); ?></span>
                            <span class="count"><?php echo (int)$v; ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="stats-box">
            <h2>Crime Categories (DB for <?php echo htmlspecialchars($days); ?>)</h2>
            <?php if (empty($crimeStats)): ?>
                <div class="stat-item">No data</div>
            <?php else: ?>
                <?php foreach ($crimeStats as $c): ?>
                    <div class="stat-item">
                        <span class="label"><?php echo htmlspecialchars($c['type']); ?></span>
                        <span class="count"><?php echo (int)$c['count']; ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="stats-box">
            <h2>Age Distribution (DB for <?php echo htmlspecialchars($days); ?>)</h2>
            <?php if (empty($ageStats)): ?>
                <div class="stat-item">No data</div>
            <?php else: ?>
                <?php foreach ($ageStats as $a): ?>
                    <div class="stat-item">
                        <span class="label"><?php echo htmlspecialchars($a['range']); ?></span>
                        <span class="count"><?php echo (int)$a['count']; ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="stats-box">
            <h2>Gender Distribution (DB for <?php echo htmlspecialchars($days); ?>)</h2>
            <?php if (empty($sexStats)): ?>
                <div class="stat-item">No data</div>
            <?php else: ?>
                <?php foreach ($sexStats as $s): ?>
                    <div class="stat-item">
                        <span class="label"><?php echo htmlspecialchars($s['type']); ?></span>
                        <span class="count"><?php echo (int)$s['count']; ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="notes">
        <p>Raw HTML saved to <code>last_docket_<?php echo htmlspecialchars($days); ?>.html</code> after each scrape. Check web server logs for errors.</p>
    </section>
</div>
</body>
</html>