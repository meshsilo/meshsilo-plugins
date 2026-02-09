<?php
/**
 * Print History Analytics Dashboard
 */
require_once 'includes/config.php';

requireAuth();

// Ensure Analytics is loaded
if (!class_exists('Analytics')) {
    require_once __DIR__ . '/../lib/Analytics.php';
}

$pageTitle = 'Print Analytics';
$activePage = 'print-analytics';

// Get analytics data
$period = $_GET['period'] ?? 'month';
$validPeriods = ['week', 'month', 'year'];
if (!in_array($period, $validPeriods)) {
    $period = 'month';
}

$printStats = Analytics::getPrintStats($period);
$printTimeSeries = Analytics::getPrintTimeSeries($period);
$mostPrinted = Analytics::getMostPrintedModels(10);
$printTypeDistribution = Analytics::getPrintTypeDistribution();
$materialUsage = Analytics::getMaterialUsageEstimate($period);
$queueStats = Analytics::getPrintQueueStats(isLoggedIn() ? getCurrentUser()['id'] : null);

// formatBytes is defined in includes/helpers.php

require_once 'includes/header.php';
?>

<div class="page-container-wide">
    <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h1>Print Analytics</h1>
            <p>Track your 3D printing activity and statistics</p>
        </div>
        <div class="period-selector">
            <a href="?period=week" class="btn btn-small <?= $period === 'week' ? 'btn-primary' : 'btn-secondary' ?>">Week</a>
            <a href="?period=month" class="btn btn-small <?= $period === 'month' ? 'btn-primary' : 'btn-secondary' ?>">Month</a>
            <a href="?period=year" class="btn btn-small <?= $period === 'year' ? 'btn-primary' : 'btn-secondary' ?>">Year</a>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="analytics-stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($printStats['total_printed']) ?></div>
            <div class="stat-label">Total Printed</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($printStats['printed_this_period']) ?></div>
            <div class="stat-label">Printed This <?= ucfirst($period) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $printStats['print_rate'] ?>%</div>
            <div class="stat-label">Print Rate</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($queueStats['pending']) ?></div>
            <div class="stat-label">In Queue</div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="analytics-charts-grid">
        <!-- Print Activity Over Time -->
        <div class="analytics-chart-card analytics-chart-large">
            <h3>Print Activity Over Time</h3>
            <canvas id="printActivityChart" height="250"></canvas>
        </div>

        <!-- Print Type Distribution -->
        <div class="analytics-chart-card">
            <h3>Print Type Distribution</h3>
            <canvas id="printTypeChart" height="250"></canvas>
        </div>
    </div>

    <!-- Second Row -->
    <div class="analytics-charts-grid">
        <!-- Material Usage Estimate -->
        <div class="analytics-chart-card analytics-chart-large">
            <h3>Material Usage (by file size)</h3>
            <canvas id="materialUsageChart" height="200"></canvas>
        </div>

        <!-- Most Printed Models -->
        <div class="analytics-chart-card">
            <h3>Most Printed Models</h3>
            <?php if (!empty($mostPrinted)): ?>
            <div class="most-printed-list">
                <?php foreach ($mostPrinted as $index => $model): ?>
                <div class="most-printed-item">
                    <span class="rank"><?= $index + 1 ?></span>
                    <a href="model.php?id=<?= $model['id'] ?>" class="model-name"><?= htmlspecialchars($model['name']) ?></a>
                    <span class="print-count"><?= $model['printed_parts'] ?>/<?= $model['part_count'] ?: 1 ?> parts</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="text-muted" style="text-align: center; padding: 2rem;">No print data yet</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.analytics-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: 1.5rem;
    text-align: center;
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--color-primary);
    margin-bottom: 0.25rem;
}

.stat-label {
    font-size: 0.875rem;
    color: var(--color-text-muted);
}

.analytics-charts-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 1.5rem;
    margin-bottom: 2rem;
}

@media (max-width: 900px) {
    .analytics-charts-grid {
        grid-template-columns: 1fr;
    }
}

.analytics-chart-card {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: 1.5rem;
}

.analytics-chart-card h3 {
    margin: 0 0 1rem 0;
    font-size: 1rem;
    font-weight: 600;
}

.most-printed-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.most-printed-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.5rem;
    background-color: var(--color-bg);
    border-radius: var(--radius);
}

.most-printed-item .rank {
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    background-color: var(--color-primary);
    color: white;
    border-radius: 50%;
    font-size: 0.75rem;
    font-weight: 600;
}

.most-printed-item .model-name {
    flex: 1;
    color: var(--color-text);
    text-decoration: none;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.most-printed-item .model-name:hover {
    color: var(--color-primary);
}

.most-printed-item .print-count {
    font-size: 0.75rem;
    color: var(--color-text-muted);
    white-space: nowrap;
}

.period-selector {
    display: flex;
    gap: 0.5rem;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Theme-aware colors
const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
const textColor = isDark ? '#94a3b8' : '#64748b';
const gridColor = isDark ? '#334155' : '#e2e8f0';
const primaryColor = '#2563eb';
const fdmColor = '#3b82f6';
const slaColor = '#8b5cf6';

// Chart.js default configuration
Chart.defaults.color = textColor;
Chart.defaults.borderColor = gridColor;

// Print Activity Over Time Chart
const printActivityData = <?= json_encode($printTimeSeries) ?>;
new Chart(document.getElementById('printActivityChart'), {
    type: 'line',
    data: {
        labels: printActivityData.map(d => d.period),
        datasets: [{
            label: 'Parts Printed',
            data: printActivityData.map(d => d.value),
            borderColor: primaryColor,
            backgroundColor: primaryColor + '20',
            fill: true,
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { stepSize: 1 }
            }
        }
    }
});

// Print Type Distribution Chart
const printTypeData = <?= json_encode($printTypeDistribution) ?>;
new Chart(document.getElementById('printTypeChart'), {
    type: 'doughnut',
    data: {
        labels: printTypeData.map(d => d.print_type?.toUpperCase() || 'Unknown'),
        datasets: [{
            data: printTypeData.map(d => d.count),
            backgroundColor: [fdmColor, slaColor, '#22c55e', '#f59e0b'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});

// Material Usage Chart
const materialData = <?= json_encode($materialUsage) ?>;
new Chart(document.getElementById('materialUsageChart'), {
    type: 'bar',
    data: {
        labels: materialData.map(d => d.period),
        datasets: [
            {
                label: 'FDM',
                data: materialData.map(d => (d.fdm_size / 1024 / 1024).toFixed(2)), // Convert to MB
                backgroundColor: fdmColor
            },
            {
                label: 'SLA',
                data: materialData.map(d => (d.sla_size / 1024 / 1024).toFixed(2)), // Convert to MB
                backgroundColor: slaColor
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'top'
            }
        },
        scales: {
            x: { stacked: true },
            y: {
                stacked: true,
                beginAtZero: true,
                title: {
                    display: true,
                    text: 'File Size (MB)'
                }
            }
        }
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
