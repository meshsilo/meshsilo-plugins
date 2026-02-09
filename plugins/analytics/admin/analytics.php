<?php
/**
 * Advanced Analytics Dashboard
 *
 * Custom analytics dashboards and scheduled report management
 */

require_once __DIR__ . '/../../../includes/config.php';

// Require view stats permission
if (!isLoggedIn() || !canViewStats()) {
    $_SESSION['error'] = 'You do not have permission to view analytics.';
    header('Location: ' . route('home'));
    exit;
}

// Include Analytics
if (!class_exists('Analytics')) {
    require_once __DIR__ . '/../lib/Analytics.php';
}

$message = '';
$error = '';
$activeTab = $_GET['tab'] ?? 'dashboard';
$period = $_GET['period'] ?? 'month';

// Handle AJAX requests
if (!empty($_GET['ajax'])) {
    header('Content-Type: application/json');

    switch ($_GET['ajax']) {
        case 'timeseries':
            $metric = $_GET['metric'] ?? 'downloads';
            echo json_encode(Analytics::getTimeSeries($metric, $period));
            break;

        case 'top':
            $type = $_GET['type'] ?? 'models';
            echo json_encode(Analytics::getTopItems($type, 10, $period));
            break;

        case 'storage':
            echo json_encode(Analytics::getStorageBreakdown());
            break;

        case 'activity':
            echo json_encode(Analytics::getUserActivityDistribution($period));
            break;

        default:
            echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}

// Handle form submissions
// CSRF protection for all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::check()) {
    $error = 'Invalid request. Please refresh the page and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'create_report':
            $result = Analytics::createScheduledReport([
                'name' => $_POST['name'] ?? '',
                'report_type' => $_POST['report_type'] ?? '',
                'filters' => ['period' => $_POST['period'] ?? 'month'],
                'schedule' => $_POST['schedule'] ?? 'weekly',
                'recipients' => array_filter(explode(',', $_POST['recipients'] ?? '')),
                'format' => $_POST['format'] ?? 'csv',
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            ]);

            if ($result) {
                $message = 'Scheduled report created successfully.';
                $activeTab = 'scheduled';
            } else {
                $error = 'Failed to create scheduled report.';
            }
            break;

        case 'update_report':
            $result = Analytics::updateScheduledReport($_POST['report_id'], [
                'name' => $_POST['name'] ?? '',
                'report_type' => $_POST['report_type'] ?? '',
                'filters' => ['period' => $_POST['period'] ?? 'month'],
                'schedule' => $_POST['schedule'] ?? 'weekly',
                'recipients' => array_filter(explode(',', $_POST['recipients'] ?? '')),
                'format' => $_POST['format'] ?? 'csv',
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            ]);

            if ($result) {
                $message = 'Scheduled report updated successfully.';
                $activeTab = 'scheduled';
            } else {
                $error = 'Failed to update scheduled report.';
            }
            break;

        case 'delete_report':
            if (Analytics::deleteScheduledReport($_POST['report_id'])) {
                $message = 'Scheduled report deleted.';
                $activeTab = 'scheduled';
            } else {
                $error = 'Failed to delete scheduled report.';
            }
            break;

        case 'run_report':
            $result = Analytics::runScheduledReport($_POST['report_id']);
            if ($result) {
                $message = 'Report generated successfully.';
                $activeTab = 'scheduled';
            } else {
                $error = 'Failed to generate report.';
            }
            break;

        case 'download_report':
            $reportType = $_POST['report_type'] ?? 'usage';
            $format = $_POST['format'] ?? 'csv';
            $reportPeriod = $_POST['period'] ?? 'month';

            $result = Analytics::generateReport($reportType, [
                'period' => $reportPeriod,
                'format' => $format
            ]);

            header('Content-Type: ' . $result['mime_type']);
            header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
            echo $result['content'];
            exit;
    }
}

// Get data for display
$stats = Analytics::getDashboardStats($period);
$scheduledReports = Analytics::getScheduledReports(false);
$reportHistory = Analytics::getReportHistory(null, 20);

// Report type labels
$reportTypes = [
    'usage' => 'Usage Overview',
    'storage' => 'Storage Analysis',
    'downloads' => 'Download Statistics',
    'uploads' => 'Upload Statistics',
    'users' => 'User Activity',
    'categories' => 'Category Analysis'
];

$scheduleOptions = [
    'daily' => 'Daily',
    'weekly' => 'Weekly',
    'monthly' => 'Monthly'
];

$periodOptions = [
    'day' => 'Today',
    'week' => 'Last 7 Days',
    'month' => 'Last 30 Days',
    'year' => 'Last Year'
];

$pageTitle = 'Analytics';
include __DIR__ . '/../../../includes/header.php';
?>

<div class="admin-layout">
    <?php include __DIR__ . '/../../../includes/admin-sidebar.php'; ?>

    <div class="admin-content">
        <div class="page-header">
            <h1>Analytics</h1>
            <div class="header-actions">
                <select id="period-select" class="form-control" onchange="changePeriod(this.value)">
                    <?php foreach ($periodOptions as $value => $label): ?>
                        <option value="<?= $value ?>" <?= $period === $value ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab-btn <?= $activeTab === 'dashboard' ? 'active' : '' ?>"
                    onclick="switchTab('dashboard')">Dashboard</button>
            <button class="tab-btn <?= $activeTab === 'reports' ? 'active' : '' ?>"
                    onclick="switchTab('reports')">Generate Reports</button>
            <button class="tab-btn <?= $activeTab === 'scheduled' ? 'active' : '' ?>"
                    onclick="switchTab('scheduled')">Scheduled Reports</button>
        </div>

        <!-- Dashboard Tab -->
        <div id="tab-dashboard" class="tab-content" style="<?= $activeTab !== 'dashboard' ? 'display:none' : '' ?>">
            <!-- Stats Overview -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?= number_format($stats['total_models']) ?></div>
                    <div class="stat-label">Total Models</div>
                    <div class="stat-change positive">+<?= number_format($stats['new_models']) ?> new</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= formatBytes($stats['total_storage']) ?></div>
                    <div class="stat-label">Total Storage</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= number_format($stats['downloads']) ?></div>
                    <div class="stat-label">Downloads</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= number_format($stats['views']) ?></div>
                    <div class="stat-label">Views</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= number_format($stats['active_users']) ?></div>
                    <div class="stat-label">Active Users</div>
                    <div class="stat-sublabel">of <?= $stats['total_users'] ?> total</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= number_format($stats['favorites']) ?></div>
                    <div class="stat-label">Favorites</div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="charts-grid">
                <div class="chart-card">
                    <div class="chart-header">
                        <h3>Activity Trend</h3>
                        <select id="chart-metric" class="form-control form-control-sm" onchange="updateChart()">
                            <option value="downloads">Downloads</option>
                            <option value="uploads">Uploads</option>
                            <option value="views">Views</option>
                        </select>
                    </div>
                    <div class="chart-container">
                        <canvas id="activity-chart"></canvas>
                    </div>
                </div>

                <div class="chart-card">
                    <h3>Storage by Category</h3>
                    <div class="chart-container">
                        <canvas id="storage-chart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Top Items Row -->
            <div class="charts-grid">
                <div class="chart-card">
                    <h3>Top Downloaded Models</h3>
                    <div id="top-models-list" class="top-list">
                        <div class="loading">Loading...</div>
                    </div>
                </div>

                <div class="chart-card">
                    <h3>Activity Distribution</h3>
                    <div class="chart-container">
                        <canvas id="activity-pie-chart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reports Tab -->
        <div id="tab-reports" class="tab-content" style="<?= $activeTab !== 'reports' ? 'display:none' : '' ?>">
            <div class="card">
                <h2>Generate Report</h2>
                <p>Generate and download analytics reports in various formats.</p>

                <form method="POST" class="report-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="download_report">

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="report-type">Report Type</label>
                            <select id="report-type" name="report_type" class="form-control" required>
                                <?php foreach ($reportTypes as $value => $label): ?>
                                    <option value="<?= $value ?>"><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="report-period">Time Period</label>
                            <select id="report-period" name="period" class="form-control">
                                <?php foreach ($periodOptions as $value => $label): ?>
                                    <option value="<?= $value ?>" <?= $value === 'month' ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="report-format">Format</label>
                            <select id="report-format" name="format" class="form-control">
                                <option value="csv">CSV (Excel compatible)</option>
                                <option value="json">JSON</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">Download Report</button>
                </form>

                <hr style="margin: 2rem 0;">

                <h3>Report Types</h3>
                <div class="report-descriptions">
                    <div class="report-desc">
                        <strong>Usage Overview</strong>
                        <p>Summary statistics, upload/download trends, and activity distribution.</p>
                    </div>
                    <div class="report-desc">
                        <strong>Storage Analysis</strong>
                        <p>Storage breakdown by category, growth trends, and largest models.</p>
                    </div>
                    <div class="report-desc">
                        <strong>Download Statistics</strong>
                        <p>Download trends, top downloaded models, and downloads by category.</p>
                    </div>
                    <div class="report-desc">
                        <strong>Upload Statistics</strong>
                        <p>Upload trends, uploads by user, and uploads by category.</p>
                    </div>
                    <div class="report-desc">
                        <strong>User Activity</strong>
                        <p>User growth, most active users, and activity statistics.</p>
                    </div>
                    <div class="report-desc">
                        <strong>Category Analysis</strong>
                        <p>Storage and activity breakdown by category.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Scheduled Reports Tab -->
        <div id="tab-scheduled" class="tab-content" style="<?= $activeTab !== 'scheduled' ? 'display:none' : '' ?>">
            <div class="section-header">
                <h2>Scheduled Reports</h2>
                <button class="btn btn-primary" onclick="openReportModal()">Create Scheduled Report</button>
            </div>

            <div class="card">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Schedule</th>
                            <th>Recipients</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scheduledReports as $report): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($report['name']) ?></strong></td>
                                <td><?= $reportTypes[$report['report_type']] ?? $report['report_type'] ?></td>
                                <td><?= ucfirst($report['schedule']) ?></td>
                                <td><?= count($report['recipients']) ?> recipient(s)</td>
                                <td>
                                    <span class="badge badge-<?= $report['is_active'] ? 'success' : 'secondary' ?>">
                                        <?= $report['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <form method="POST" style="display: inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="run_report">
                                            <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-secondary" title="Run Now">
                                                Run
                                            </button>
                                        </form>
                                        <button class="btn btn-sm btn-secondary"
                                                onclick="editReport(<?= htmlspecialchars(json_encode($report)) ?>)">
                                            Edit
                                        </button>
                                        <form method="POST" style="display: inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_report">
                                            <input type="hidden" name="report_id" value="<?= $report['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger"
                                                    onclick="return confirm('Delete this scheduled report?')">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($scheduledReports)): ?>
                            <tr>
                                <td colspan="6" class="text-center">No scheduled reports configured.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!empty($reportHistory)): ?>
                <h3 style="margin-top: 2rem;">Recent Executions</h3>
                <div class="card">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Report</th>
                                <th>Executed</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportHistory as $history): ?>
                                <tr>
                                    <td><?= htmlspecialchars($history['report_name'] ?? 'Unknown') ?></td>
                                    <td><?= date('M j, Y H:i', strtotime($history['executed_at'])) ?></td>
                                    <td>
                                        <?php if ($history['success']): ?>
                                            <span class="badge badge-success">Success</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger" title="<?= htmlspecialchars($history['error_message'] ?? '') ?>">Failed</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Scheduled Report Modal -->
<div id="report-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="report-modal-title">Create Scheduled Report</h3>
            <button class="modal-close" onclick="closeReportModal()">&times;</button>
        </div>
        <form method="POST" id="report-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="report-form-action" value="create_report">
            <input type="hidden" name="report_id" id="report-form-id" value="">

            <div class="modal-body">
                <div class="form-group">
                    <label for="sched-name">Report Name *</label>
                    <input type="text" id="sched-name" name="name" required class="form-control">
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="sched-type">Report Type *</label>
                        <select id="sched-type" name="report_type" required class="form-control">
                            <?php foreach ($reportTypes as $value => $label): ?>
                                <option value="<?= $value ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="sched-period">Time Period</label>
                        <select id="sched-period" name="period" class="form-control">
                            <?php foreach ($periodOptions as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $value === 'month' ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="sched-schedule">Schedule *</label>
                        <select id="sched-schedule" name="schedule" required class="form-control">
                            <?php foreach ($scheduleOptions as $value => $label): ?>
                                <option value="<?= $value ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="sched-format">Format</label>
                        <select id="sched-format" name="format" class="form-control">
                            <option value="csv">CSV</option>
                            <option value="json">JSON</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="sched-recipients">Email Recipients</label>
                    <input type="text" id="sched-recipients" name="recipients" class="form-control"
                           placeholder="email1@example.com, email2@example.com">
                    <small>Comma-separated email addresses (requires email webhook)</small>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="is_active" id="sched-active" checked>
                        Report is active
                    </label>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeReportModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Report</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<style>
.admin-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}

.header-actions {
    display: flex;
    gap: 0.5rem;
}

.tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    border-bottom: 2px solid var(--color-border);
}

.tab-btn {
    padding: 0.75rem 1.5rem;
    border: none;
    background: none;
    cursor: pointer;
    font-size: 1rem;
    color: var(--color-text-muted);
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
}

.tab-btn:hover {
    color: var(--color-text);
}

.tab-btn.active {
    color: var(--color-primary);
    border-bottom-color: var(--color-primary);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.stat-card {
    background: var(--color-surface);
    padding: 1.25rem;
    border-radius: var(--radius);
    text-align: center;
}

.stat-value {
    font-size: 1.75rem;
    font-weight: bold;
    color: var(--color-primary);
}

.stat-label {
    color: var(--color-text-muted);
    font-size: 0.875rem;
    margin-top: 0.25rem;
}

.stat-change {
    font-size: 0.75rem;
    margin-top: 0.5rem;
}

.stat-change.positive {
    color: #28a745;
}

.stat-sublabel {
    font-size: 0.75rem;
    color: var(--color-text-muted);
}

.charts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.chart-card {
    background: var(--color-surface);
    padding: 1.25rem;
    border-radius: var(--radius);
}

.chart-card h3 {
    margin: 0 0 1rem 0;
    font-size: 1rem;
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.chart-header h3 {
    margin: 0;
}

.chart-container {
    position: relative;
    height: 250px;
}

.top-list {
    max-height: 250px;
    overflow-y: auto;
}

.top-list-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--color-border);
}

.top-list-item:last-child {
    border-bottom: none;
}

.top-list-rank {
    width: 30px;
    font-weight: bold;
    color: var(--color-text-muted);
}

.top-list-name {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.top-list-value {
    font-weight: bold;
    color: var(--color-primary);
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.section-header h2 {
    margin: 0;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}

.report-form {
    max-width: 600px;
}

.report-descriptions {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}

.report-desc {
    padding: 1rem;
    background: var(--color-surface-hover);
    border-radius: var(--radius);
}

.report-desc strong {
    display: block;
    margin-bottom: 0.5rem;
}

.report-desc p {
    margin: 0;
    font-size: 0.875rem;
    color: var(--color-text-muted);
}

.loading {
    text-align: center;
    padding: 2rem;
    color: var(--color-text-muted);
}

.badge-success {
    background-color: #28a745;
    color: white;
}

.badge-danger {
    background-color: #dc3545;
    color: white;
}

.badge-secondary {
    background-color: #6c757d;
    color: white;
}

@media (max-width: 768px) {
    .charts-grid {
        grid-template-columns: 1fr;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
const period = '<?= $period ?>';
let activityChart, storageChart, activityPieChart;

// Chart colors
const chartColors = [
    '#4285f4', '#34a853', '#fbbc05', '#ea4335', '#9c27b0',
    '#00bcd4', '#ff9800', '#795548', '#607d8b', '#e91e63'
];

// Initialize charts on load
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('tab-dashboard').style.display !== 'none') {
        initCharts();
        loadTopModels();
    }
});

function initCharts() {
    // Activity trend chart
    const activityCtx = document.getElementById('activity-chart');
    if (activityCtx) {
        activityChart = new Chart(activityCtx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: 'Downloads',
                    data: [],
                    borderColor: chartColors[0],
                    tension: 0.3,
                    fill: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
        updateChart();
    }

    // Storage breakdown chart
    loadStorageChart();

    // Activity distribution pie chart
    loadActivityPieChart();
}

async function updateChart() {
    const metric = document.getElementById('chart-metric')?.value || 'downloads';

    try {
        const response = await fetch(`?ajax=timeseries&metric=${metric}&period=${period}`);
        const data = await response.json();

        activityChart.data.labels = data.map(d => d.period);
        activityChart.data.datasets[0].data = data.map(d => d.value);
        activityChart.data.datasets[0].label = metric.charAt(0).toUpperCase() + metric.slice(1);
        activityChart.update();
    } catch (error) {
        console.error('Error loading chart data:', error);
    }
}

async function loadStorageChart() {
    const ctx = document.getElementById('storage-chart');
    if (!ctx) return;

    try {
        const response = await fetch('?ajax=storage');
        const data = await response.json();

        storageChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.map(d => d.name || 'Uncategorized'),
                datasets: [{
                    data: data.map(d => d.total_size),
                    backgroundColor: chartColors
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            boxWidth: 12
                        }
                    }
                }
            }
        });
    } catch (error) {
        console.error('Error loading storage chart:', error);
    }
}

async function loadActivityPieChart() {
    const ctx = document.getElementById('activity-pie-chart');
    if (!ctx) return;

    try {
        const response = await fetch(`?ajax=activity&period=${period}`);
        const data = await response.json();

        activityPieChart = new Chart(ctx, {
            type: 'pie',
            data: {
                labels: data.map(d => d.action),
                datasets: [{
                    data: data.map(d => d.count),
                    backgroundColor: chartColors
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            boxWidth: 12
                        }
                    }
                }
            }
        });
    } catch (error) {
        console.error('Error loading activity pie chart:', error);
    }
}

async function loadTopModels() {
    const container = document.getElementById('top-models-list');
    if (!container) return;

    try {
        const response = await fetch(`?ajax=top&type=models&period=${period}`);
        const data = await response.json();

        if (data.length === 0) {
            container.innerHTML = '<div class="loading">No data available</div>';
            return;
        }

        container.innerHTML = data.map((item, index) => `
            <div class="top-list-item">
                <span class="top-list-rank">#${index + 1}</span>
                <span class="top-list-name">${escapeHtml(item.name)}</span>
                <span class="top-list-value">${item.period_downloads || item.download_count || 0}</span>
            </div>
        `).join('');
    } catch (error) {
        container.innerHTML = '<div class="loading">Error loading data</div>';
        console.error('Error loading top models:', error);
    }
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function changePeriod(newPeriod) {
    const url = new URL(window.location);
    url.searchParams.set('period', newPeriod);
    window.location = url.toString();
}

function switchTab(tabName) {
    document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));

    document.getElementById('tab-' + tabName).style.display = 'block';
    event.target.classList.add('active');

    const url = new URL(window.location);
    url.searchParams.set('tab', tabName);
    history.replaceState(null, '', url);

    if (tabName === 'dashboard' && !activityChart) {
        initCharts();
        loadTopModels();
    }
}

function openReportModal() {
    document.getElementById('report-modal-title').textContent = 'Create Scheduled Report';
    document.getElementById('report-form-action').value = 'create_report';
    document.getElementById('report-form-id').value = '';
    document.getElementById('report-form').reset();
    document.getElementById('sched-active').checked = true;
    document.getElementById('report-modal').style.display = 'flex';
}

function editReport(report) {
    document.getElementById('report-modal-title').textContent = 'Edit Scheduled Report';
    document.getElementById('report-form-action').value = 'update_report';
    document.getElementById('report-form-id').value = report.id;
    document.getElementById('sched-name').value = report.name;
    document.getElementById('sched-type').value = report.report_type;
    document.getElementById('sched-period').value = report.filters?.period || 'month';
    document.getElementById('sched-schedule').value = report.schedule;
    document.getElementById('sched-format').value = report.format;
    document.getElementById('sched-recipients').value = (report.recipients || []).join(', ');
    document.getElementById('sched-active').checked = report.is_active == 1;
    document.getElementById('report-modal').style.display = 'flex';
}

function closeReportModal() {
    document.getElementById('report-modal').style.display = 'none';
}

// Close modal on backdrop click
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.style.display = 'none';
        }
    });
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal').forEach(m => m.style.display = 'none');
    }
});
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
