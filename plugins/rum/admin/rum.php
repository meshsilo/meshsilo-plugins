<?php
/**
 * Real User Monitoring Dashboard
 *
 * Displays Core Web Vitals and performance metrics collected from users.
 */

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/auth.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . route('login'));
    exit;
}

$pageTitle = 'Real User Monitoring';
$activePage = 'admin';
$adminPage = 'rum';

$db = getDB();

// Check if RUM tables exist
$tablesExist = false;
try {
    if (DB_TYPE === 'sqlite') {
        $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='rum_metrics'");
    } else {
        $result = $db->query("SHOW TABLES LIKE 'rum_metrics'");
    }
    $tablesExist = $result->fetch() !== false;
} catch (Exception $e) {
    // Tables don't exist
}

$stats = [];
$recentMetrics = [];
$errors = [];
$webVitals = [];

if ($tablesExist) {
    // Get overall statistics
    $stats = $db->query("
        SELECT
            COUNT(*) as total_records,
            AVG(lcp) as avg_lcp,
            AVG(fid) as avg_fid,
            AVG(cls) as avg_cls,
            AVG(ttfb) as avg_ttfb,
            AVG(page_load) as avg_page_load,
            MIN(created_at) as first_record,
            MAX(created_at) as last_record
        FROM rum_metrics
    ")->fetch(PDO::FETCH_ASSOC);

    // Get Web Vitals distribution
    $webVitals = [
        'lcp' => [
            'good' => $db->query("SELECT COUNT(*) FROM rum_metrics WHERE lcp IS NOT NULL AND lcp <= 2500")->fetchColumn(),
            'needs_improvement' => $db->query("SELECT COUNT(*) FROM rum_metrics WHERE lcp IS NOT NULL AND lcp > 2500 AND lcp <= 4000")->fetchColumn(),
            'poor' => $db->query("SELECT COUNT(*) FROM rum_metrics WHERE lcp IS NOT NULL AND lcp > 4000")->fetchColumn(),
        ],
        'fid' => [
            'good' => $db->query("SELECT COUNT(*) FROM rum_metrics WHERE fid IS NOT NULL AND fid <= 100")->fetchColumn(),
            'needs_improvement' => $db->query("SELECT COUNT(*) FROM rum_metrics WHERE fid IS NOT NULL AND fid > 100 AND fid <= 300")->fetchColumn(),
            'poor' => $db->query("SELECT COUNT(*) FROM rum_metrics WHERE fid IS NOT NULL AND fid > 300")->fetchColumn(),
        ],
        'cls' => [
            'good' => $db->query("SELECT COUNT(*) FROM rum_metrics WHERE cls IS NOT NULL AND cls <= 0.1")->fetchColumn(),
            'needs_improvement' => $db->query("SELECT COUNT(*) FROM rum_metrics WHERE cls IS NOT NULL AND cls > 0.1 AND cls <= 0.25")->fetchColumn(),
            'poor' => $db->query("SELECT COUNT(*) FROM rum_metrics WHERE cls IS NOT NULL AND cls > 0.25")->fetchColumn(),
        ],
    ];

    // Get recent metrics
    $recentMetrics = $db->query("
        SELECT url, lcp, fid, cls, ttfb, page_load, connection_type, created_at
        FROM rum_metrics
        ORDER BY created_at DESC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Get recent errors
    $errors = $db->query("
        SELECT url, message, source, line_number, created_at
        FROM rum_errors
        ORDER BY created_at DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Rating helpers
function getLcpRating($value) {
    if ($value === null) return 'none';
    if ($value <= 2500) return 'good';
    if ($value <= 4000) return 'needs-improvement';
    return 'poor';
}

function getFidRating($value) {
    if ($value === null) return 'none';
    if ($value <= 100) return 'good';
    if ($value <= 300) return 'needs-improvement';
    return 'poor';
}

function getClsRating($value) {
    if ($value === null) return 'none';
    if ($value <= 0.1) return 'good';
    if ($value <= 0.25) return 'needs-improvement';
    return 'poor';
}

include __DIR__ . '/../../../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/../../../includes/admin-sidebar.php'; ?>

    <div class="admin-content">
        <div class="admin-header">
            <h1>Real User Monitoring</h1>
            <p>Core Web Vitals and performance metrics from real users</p>
        </div>

        <?php if (!$tablesExist): ?>
        <div class="alert alert-info">
            <strong>No Data Yet</strong><br>
            RUM data will appear here once users visit your site with RUM enabled.
            <br><br>
            To enable RUM, go to <a href="<?= route('admin.settings') ?>">Settings</a> and enable "Real User Monitoring".
        </div>
        <?php else: ?>

        <!-- Overview Stats -->
        <div class="rum-stats-grid">
            <div class="rum-stat-card">
                <div class="rum-stat-value"><?= number_format($stats['total_records'] ?? 0) ?></div>
                <div class="rum-stat-label">Page Views Tracked</div>
            </div>
            <div class="rum-stat-card">
                <div class="rum-stat-value <?= getLcpRating($stats['avg_lcp']) ?>"><?= $stats['avg_lcp'] ? round($stats['avg_lcp']) . 'ms' : 'N/A' ?></div>
                <div class="rum-stat-label">Avg LCP (Largest Contentful Paint)</div>
            </div>
            <div class="rum-stat-card">
                <div class="rum-stat-value <?= getFidRating($stats['avg_fid']) ?>"><?= $stats['avg_fid'] ? round($stats['avg_fid']) . 'ms' : 'N/A' ?></div>
                <div class="rum-stat-label">Avg FID (First Input Delay)</div>
            </div>
            <div class="rum-stat-card">
                <div class="rum-stat-value <?= getClsRating($stats['avg_cls']) ?>"><?= $stats['avg_cls'] !== null ? number_format($stats['avg_cls'], 3) : 'N/A' ?></div>
                <div class="rum-stat-label">Avg CLS (Cumulative Layout Shift)</div>
            </div>
        </div>

        <!-- Web Vitals Distribution -->
        <div class="rum-card">
            <h2>Core Web Vitals Distribution</h2>
            <p>Based on Google's thresholds for good user experience</p>

            <div class="vitals-grid">
                <div class="vital-card">
                    <h3>LCP (Largest Contentful Paint)</h3>
                    <p class="vital-description">Measures loading performance. Good: &le;2.5s</p>
                    <div class="vital-bar">
                        <?php
                        $lcpTotal = array_sum($webVitals['lcp']);
                        if ($lcpTotal > 0):
                            $lcpGood = round(($webVitals['lcp']['good'] / $lcpTotal) * 100);
                            $lcpNi = round(($webVitals['lcp']['needs_improvement'] / $lcpTotal) * 100);
                            $lcpPoor = round(($webVitals['lcp']['poor'] / $lcpTotal) * 100);
                        ?>
                        <div class="bar-segment good" style="width: <?= $lcpGood ?>%" title="Good: <?= $lcpGood ?>%"></div>
                        <div class="bar-segment needs-improvement" style="width: <?= $lcpNi ?>%" title="Needs Improvement: <?= $lcpNi ?>%"></div>
                        <div class="bar-segment poor" style="width: <?= $lcpPoor ?>%" title="Poor: <?= $lcpPoor ?>%"></div>
                        <?php endif; ?>
                    </div>
                    <div class="vital-legend">
                        <span class="good">Good: <?= $webVitals['lcp']['good'] ?></span>
                        <span class="needs-improvement">Needs Work: <?= $webVitals['lcp']['needs_improvement'] ?></span>
                        <span class="poor">Poor: <?= $webVitals['lcp']['poor'] ?></span>
                    </div>
                </div>

                <div class="vital-card">
                    <h3>FID (First Input Delay)</h3>
                    <p class="vital-description">Measures interactivity. Good: &le;100ms</p>
                    <div class="vital-bar">
                        <?php
                        $fidTotal = array_sum($webVitals['fid']);
                        if ($fidTotal > 0):
                            $fidGood = round(($webVitals['fid']['good'] / $fidTotal) * 100);
                            $fidNi = round(($webVitals['fid']['needs_improvement'] / $fidTotal) * 100);
                            $fidPoor = round(($webVitals['fid']['poor'] / $fidTotal) * 100);
                        ?>
                        <div class="bar-segment good" style="width: <?= $fidGood ?>%" title="Good: <?= $fidGood ?>%"></div>
                        <div class="bar-segment needs-improvement" style="width: <?= $fidNi ?>%" title="Needs Improvement: <?= $fidNi ?>%"></div>
                        <div class="bar-segment poor" style="width: <?= $fidPoor ?>%" title="Poor: <?= $fidPoor ?>%"></div>
                        <?php endif; ?>
                    </div>
                    <div class="vital-legend">
                        <span class="good">Good: <?= $webVitals['fid']['good'] ?></span>
                        <span class="needs-improvement">Needs Work: <?= $webVitals['fid']['needs_improvement'] ?></span>
                        <span class="poor">Poor: <?= $webVitals['fid']['poor'] ?></span>
                    </div>
                </div>

                <div class="vital-card">
                    <h3>CLS (Cumulative Layout Shift)</h3>
                    <p class="vital-description">Measures visual stability. Good: &le;0.1</p>
                    <div class="vital-bar">
                        <?php
                        $clsTotal = array_sum($webVitals['cls']);
                        if ($clsTotal > 0):
                            $clsGood = round(($webVitals['cls']['good'] / $clsTotal) * 100);
                            $clsNi = round(($webVitals['cls']['needs_improvement'] / $clsTotal) * 100);
                            $clsPoor = round(($webVitals['cls']['poor'] / $clsTotal) * 100);
                        ?>
                        <div class="bar-segment good" style="width: <?= $clsGood ?>%" title="Good: <?= $clsGood ?>%"></div>
                        <div class="bar-segment needs-improvement" style="width: <?= $clsNi ?>%" title="Needs Improvement: <?= $clsNi ?>%"></div>
                        <div class="bar-segment poor" style="width: <?= $clsPoor ?>%" title="Poor: <?= $clsPoor ?>%"></div>
                        <?php endif; ?>
                    </div>
                    <div class="vital-legend">
                        <span class="good">Good: <?= $webVitals['cls']['good'] ?></span>
                        <span class="needs-improvement">Needs Work: <?= $webVitals['cls']['needs_improvement'] ?></span>
                        <span class="poor">Poor: <?= $webVitals['cls']['poor'] ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Page Views -->
        <div class="rum-card">
            <h2>Recent Page Views</h2>
            <div class="rum-table-container">
                <table class="rum-table">
                    <thead>
                        <tr>
                            <th>URL</th>
                            <th>LCP</th>
                            <th>FID</th>
                            <th>CLS</th>
                            <th>TTFB</th>
                            <th>Page Load</th>
                            <th>Connection</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentMetrics as $metric): ?>
                        <tr>
                            <td class="url-cell" title="<?= htmlspecialchars($metric['url']) ?>"><?= htmlspecialchars(substr($metric['url'], 0, 50)) ?><?= strlen($metric['url']) > 50 ? '...' : '' ?></td>
                            <td class="<?= getLcpRating($metric['lcp']) ?>"><?= $metric['lcp'] ? round($metric['lcp']) . 'ms' : '-' ?></td>
                            <td class="<?= getFidRating($metric['fid']) ?>"><?= $metric['fid'] ? round($metric['fid']) . 'ms' : '-' ?></td>
                            <td class="<?= getClsRating($metric['cls']) ?>"><?= $metric['cls'] !== null ? number_format($metric['cls'], 3) : '-' ?></td>
                            <td><?= $metric['ttfb'] ? round($metric['ttfb']) . 'ms' : '-' ?></td>
                            <td><?= $metric['page_load'] ? round($metric['page_load']) . 'ms' : '-' ?></td>
                            <td><?= htmlspecialchars($metric['connection_type'] ?? '-') ?></td>
                            <td><?= date('M j H:i', strtotime($metric['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentMetrics)): ?>
                        <tr><td colspan="8" class="empty">No page views recorded yet</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- JavaScript Errors -->
        <?php if (!empty($errors)): ?>
        <div class="rum-card">
            <h2>JavaScript Errors</h2>
            <div class="rum-table-container">
                <table class="rum-table">
                    <thead>
                        <tr>
                            <th>Message</th>
                            <th>Source</th>
                            <th>Line</th>
                            <th>URL</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($errors as $error): ?>
                        <tr>
                            <td class="error-message"><?= htmlspecialchars(substr($error['message'], 0, 100)) ?></td>
                            <td><?= htmlspecialchars(basename($error['source'] ?? '')) ?></td>
                            <td><?= $error['line_number'] ?? '-' ?></td>
                            <td class="url-cell"><?= htmlspecialchars(substr($error['url'], 0, 30)) ?></td>
                            <td><?= date('M j H:i', strtotime($error['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<style>
/* Admin Header */
.admin-header {
    margin-bottom: 2rem;
}

.admin-header h1 {
    margin: 0 0 0.5rem 0;
    font-size: 1.5rem;
    color: var(--color-text);
}

.admin-header p {
    margin: 0;
    color: var(--color-text-muted);
}

/* RUM Page Styles */
.rum-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.rum-stat-card {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: 1.5rem;
    text-align: center;
}

.rum-stat-value {
    font-size: 2rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: var(--color-text);
}

.rum-stat-value.good { color: #4ade80; }
.rum-stat-value.needs-improvement { color: #fbbf24; }
.rum-stat-value.poor { color: #f87171; }

.rum-stat-label {
    color: var(--color-text-muted);
    font-size: 0.875rem;
}

.rum-card {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    padding: 1.5rem;
    margin-bottom: 2rem;
}

.rum-card h2 {
    margin: 0 0 0.5rem 0;
    font-size: 1.125rem;
}

.rum-card > p {
    color: var(--color-text-muted);
    margin: 0 0 1.5rem 0;
}

.vitals-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1.5rem;
}

.vital-card {
    background: var(--color-background);
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
    padding: 1.25rem;
}

.vital-card h3 {
    margin: 0 0 0.25rem 0;
    font-size: 0.95rem;
}

.vital-description {
    color: var(--color-text-muted);
    font-size: 0.8rem;
    margin: 0 0 1rem 0;
}

.vital-bar {
    display: flex;
    height: 20px;
    border-radius: var(--radius);
    overflow: hidden;
    background: var(--color-border);
    margin-bottom: 0.75rem;
}

.bar-segment {
    transition: width 0.3s ease;
    min-width: 1px;
}

.bar-segment.good { background: #4ade80; }
.bar-segment.needs-improvement { background: #fbbf24; }
.bar-segment.poor { background: #f87171; }

.vital-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    font-size: 0.75rem;
    color: var(--color-text-muted);
}

.vital-legend span {
    display: flex;
    align-items: center;
    gap: 0.35rem;
}

.vital-legend span::before {
    content: '';
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 2px;
}

.vital-legend .good::before { background: #4ade80; }
.vital-legend .needs-improvement::before { background: #fbbf24; }
.vital-legend .poor::before { background: #f87171; }

/* Table Styles */
.rum-table-container {
    overflow-x: auto;
    margin-top: 1rem;
}

.rum-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.rum-table th,
.rum-table td {
    padding: 0.75rem 1rem;
    text-align: left;
    border-bottom: 1px solid var(--color-border);
}

.rum-table th {
    background: var(--color-surface-elevated);
    font-weight: 600;
    color: var(--color-text);
    white-space: nowrap;
}

.rum-table tbody tr:hover {
    background: var(--color-surface-elevated);
}

.rum-table tbody tr:last-child td {
    border-bottom: none;
}

.rum-table td.good { color: #4ade80; }
.rum-table td.needs-improvement { color: #fbbf24; }
.rum-table td.poor { color: #f87171; }

.rum-table .url-cell {
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.rum-table .error-message {
    color: #f87171;
    font-family: monospace;
    font-size: 0.8rem;
}

.rum-table .empty {
    text-align: center;
    color: var(--color-text-muted);
    padding: 2rem;
}

/* Alert Info */
.alert-info {
    background-color: rgba(59, 130, 246, 0.1);
    border: 1px solid #3b82f6;
    color: #3b82f6;
    padding: 1rem 1.25rem;
    border-radius: var(--radius-md);
    line-height: 1.6;
}

.alert-info a {
    color: inherit;
    font-weight: 600;
}
</style>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
