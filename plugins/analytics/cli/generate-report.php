#!/usr/bin/env php
<?php
/**
 * CLI Tool: Generate Scheduled Reports
 *
 * NOTE: For scheduled execution, use: php cli/cron.php
 * This script is for manual report generation or custom schedules.
 *
 * Usage:
 *   php cli/generate-report.php [options]
 *
 * Options:
 *   --schedule=SCHEDULE  Only run reports matching this schedule (daily, weekly, monthly)
 *   --report=ID          Run a specific report by ID
 *   --output=PATH        Save report to file instead of sending
 *   --verbose            Show detailed output
 *   --quiet              Suppress all output except errors
 *   --list               List all scheduled reports
 */

// Change to project root (3 levels up from plugins/analytics/cli/)
chdir(dirname(__DIR__, 3));

// Load configuration
require_once 'includes/config.php';

if (!class_exists('Analytics')) {
    require_once __DIR__ . '/../lib/Analytics.php';
}

// Parse command line options
$options = getopt('', ['schedule:', 'report:', 'output:', 'verbose', 'quiet', 'list', 'help']);

// Show help
if (isset($options['help'])) {
    echo <<<HELP
Silo Report Generation CLI Tool

Usage: php cli/generate-report.php [options]

Options:
  --schedule=SCHEDULE  Only run reports matching this schedule (daily, weekly, monthly)
  --report=ID          Run a specific report by ID
  --output=PATH        Save report to file instead of sending
  --verbose            Show detailed output
  --quiet              Suppress all output except errors
  --list               List all scheduled reports
  --help               Show this help message

Examples:
  php cli/generate-report.php --schedule=daily
  php cli/generate-report.php --report=1 --output=/tmp/report.csv
  php cli/generate-report.php --list

HELP;
    exit(0);
}

$schedule = $options['schedule'] ?? null;
$reportId = $options['report'] ?? null;
$outputPath = $options['output'] ?? null;
$verbose = isset($options['verbose']);
$quiet = isset($options['quiet']);
$listReports = isset($options['list']);

/**
 * Output message unless quiet mode
 */
function output($message, $isError = false) {
    global $quiet;
    if ($quiet && !$isError) return;

    if ($isError) {
        fwrite(STDERR, $message . PHP_EOL);
    } else {
        echo $message . PHP_EOL;
    }
}

/**
 * Verbose output
 */
function verbose($message) {
    global $verbose;
    if ($verbose) {
        output("  " . $message);
    }
}

// List reports mode
if ($listReports) {
    $reports = Analytics::getScheduledReports(false);

    if (empty($reports)) {
        output("No scheduled reports configured.");
        exit(0);
    }

    output("Scheduled Reports:");
    output(str_repeat('-', 80));
    output(sprintf("%-4s %-30s %-12s %-10s %-8s", "ID", "Name", "Type", "Schedule", "Active"));
    output(str_repeat('-', 80));

    foreach ($reports as $report) {
        output(sprintf(
            "%-4s %-30s %-12s %-10s %-8s",
            $report['id'],
            substr($report['name'], 0, 30),
            $report['report_type'],
            $report['schedule'],
            $report['is_active'] ? 'Yes' : 'No'
        ));
    }

    exit(0);
}

// Header
output("==============================================");
output("Silo Report Generation");
output("Started: " . date('Y-m-d H:i:s'));
if ($schedule) {
    output("Schedule Filter: $schedule");
}
output("==============================================\n");

$generatedCount = 0;
$errorCount = 0;

try {
    if ($reportId !== null) {
        // Run specific report
        $report = Analytics::getScheduledReport((int)$reportId);
        if (!$report) {
            output("Error: Report ID $reportId not found.", true);
            exit(1);
        }

        output("Generating report: {$report['name']}");
        verbose("Type: {$report['report_type']}");
        verbose("Format: {$report['format']}");

        $result = Analytics::generateReport($report['report_type'], [
            'period' => $report['filters']['period'] ?? 'month',
            'format' => $report['format']
        ]);

        if ($outputPath) {
            // Save to file
            file_put_contents($outputPath, $result['content']);
            output("Report saved to: $outputPath");
        } else {
            // Run through the scheduled report system
            Analytics::runScheduledReport($reportId);
            output("Report generated and sent to " . count($report['recipients']) . " recipient(s)");
        }

        $generatedCount++;
    } else {
        // Run all matching scheduled reports
        $reports = Analytics::getScheduledReports(true);

        if ($schedule) {
            $reports = array_filter($reports, function($r) use ($schedule) {
                return $r['schedule'] === $schedule;
            });
        }

        if (empty($reports)) {
            output("No matching active reports found.");
            exit(0);
        }

        output("Found " . count($reports) . " reports to generate\n");

        foreach ($reports as $report) {
            output("Report: {$report['name']}");
            verbose("Type: {$report['report_type']}");
            verbose("Recipients: " . count($report['recipients']));

            try {
                $result = Analytics::runScheduledReport($report['id']);
                if ($result) {
                    output("  Status: Generated successfully");
                    $generatedCount++;
                } else {
                    output("  Status: Failed", true);
                    $errorCount++;
                }
            } catch (Exception $e) {
                output("  Error: " . $e->getMessage(), true);
                $errorCount++;
            }

            output("");
        }
    }

    // Summary
    output("==============================================");
    output("Summary:");
    output("  Reports Generated: $generatedCount");
    output("  Errors: $errorCount");
    output("Completed: " . date('Y-m-d H:i:s'));
    output("==============================================");

    exit($errorCount > 0 ? 1 : 0);

} catch (Exception $e) {
    output("Fatal error: " . $e->getMessage(), true);
    exit(2);
}
