#!/usr/bin/env php
<?php
/**
 * CLI Tool: Apply Retention Policies
 *
 * NOTE: This task runs automatically via the unified cron. Manual use only when needed.
 * For scheduled execution, use: php cli/cron.php (runs retention:apply task daily at 2am)
 *
 * Usage:
 *   php cli/apply-retention.php [options]
 *
 * Options:
 *   --dry-run      Show what would be affected without making changes
 *   --policy=ID    Run a specific policy only (by ID)
 *   --verbose      Show detailed output
 *   --quiet        Suppress all output except errors
 *   --json         Output results as JSON
 */

// Change to project root (3 levels up from plugins/retention/cli/)
chdir(dirname(__DIR__, 3));

// Load configuration
require_once 'includes/config.php';

if (!class_exists('RetentionManager')) {
    require_once __DIR__ . '/../lib/RetentionManager.php';
}

// Parse command line options
$options = getopt('', ['dry-run', 'policy:', 'verbose', 'quiet', 'json', 'help']);

// Show help
if (isset($options['help'])) {
    echo <<<HELP
Silo Retention Policy CLI Tool

Usage: php cli/apply-retention.php [options]

Options:
  --dry-run      Show what would be affected without making changes
  --policy=ID    Run a specific policy only (by ID)
  --verbose      Show detailed output
  --quiet        Suppress all output except errors
  --json         Output results as JSON
  --help         Show this help message

Examples:
  php cli/apply-retention.php --dry-run
  php cli/apply-retention.php --policy=1 --verbose
  php cli/apply-retention.php --json

HELP;
    exit(0);
}

$dryRun = isset($options['dry-run']);
$policyId = $options['policy'] ?? null;
$verbose = isset($options['verbose']);
$quiet = isset($options['quiet']);
$jsonOutput = isset($options['json']);

/**
 * Output message unless quiet mode
 */
function output($message, $isError = false) {
    global $quiet, $jsonOutput;
    if ($quiet && !$isError) return;
    if ($jsonOutput) return;

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

// Header
output("==============================================");
output("Silo Retention Policy Execution");
output("Started: " . date('Y-m-d H:i:s'));
output("Mode: " . ($dryRun ? "DRY RUN (no changes)" : "LIVE"));
output("==============================================\n");

$results = [];
$totalAffected = 0;
$totalSkipped = 0;
$totalErrors = 0;

try {
    if ($policyId !== null) {
        // Run specific policy
        $policy = RetentionManager::getPolicy((int)$policyId);
        if (!$policy) {
            output("Error: Policy ID $policyId not found.", true);
            exit(1);
        }

        output("Running policy: {$policy['name']}");
        verbose("Entity type: {$policy['entity_type']}");
        verbose("Action: {$policy['action']}");

        $result = RetentionManager::applyPolicy($policy, $dryRun);
        $results[] = $result;

        $totalAffected += $result['affected'];
        $totalSkipped += $result['skipped_legal_hold'];
        $totalErrors += count($result['errors']);

        output("  Affected: {$result['affected']}");
        output("  Skipped (legal hold): {$result['skipped_legal_hold']}");
        if (count($result['errors']) > 0) {
            output("  Errors: " . count($result['errors']));
            foreach ($result['errors'] as $err) {
                verbose("    Entity #{$err['entity_id']}: {$err['error']}");
            }
        }
    } else {
        // Run all active policies
        $policies = RetentionManager::getPolicies(true);

        if (empty($policies)) {
            output("No active retention policies found.");
            exit(0);
        }

        output("Found " . count($policies) . " active policies\n");

        foreach ($policies as $policy) {
            output("Policy: {$policy['name']}");
            verbose("Entity type: {$policy['entity_type']}");
            verbose("Action: {$policy['action']}");

            $result = RetentionManager::applyPolicy($policy, $dryRun);
            $results[] = $result;

            $totalAffected += $result['affected'];
            $totalSkipped += $result['skipped_legal_hold'];
            $totalErrors += count($result['errors']);

            output("  Affected: {$result['affected']}");
            output("  Skipped (legal hold): {$result['skipped_legal_hold']}");
            if (count($result['errors']) > 0) {
                output("  Errors: " . count($result['errors']));
                foreach ($result['errors'] as $err) {
                    verbose("    Entity #{$err['entity_id']}: {$err['error']}");
                }
            }
            output("");
        }
    }

    // Summary
    output("==============================================");
    output("Summary:");
    output("  Total Affected: $totalAffected");
    output("  Total Skipped (Legal Hold): $totalSkipped");
    output("  Total Errors: $totalErrors");
    output("Completed: " . date('Y-m-d H:i:s'));
    output("==============================================");

    // JSON output
    if ($jsonOutput) {
        echo json_encode([
            'success' => true,
            'dry_run' => $dryRun,
            'timestamp' => date('c'),
            'summary' => [
                'policies_executed' => count($results),
                'total_affected' => $totalAffected,
                'total_skipped' => $totalSkipped,
                'total_errors' => $totalErrors
            ],
            'results' => $results
        ], JSON_PRETTY_PRINT) . PHP_EOL;
    }

    // Log to audit system if not dry run
    if (!$dryRun && !empty($results)) {
        AuditLogger::log(
            AuditLogger::TYPE_SYSTEM,
            'retention_cli_executed',
            [
                'metadata' => [
                    'policies' => count($results),
                    'affected' => $totalAffected,
                    'skipped' => $totalSkipped,
                    'errors' => $totalErrors
                ]
            ]
        );
    }

    exit($totalErrors > 0 ? 1 : 0);

} catch (Exception $e) {
    output("Fatal error: " . $e->getMessage(), true);

    if ($jsonOutput) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ], JSON_PRETTY_PRINT) . PHP_EOL;
    }

    exit(2);
}
