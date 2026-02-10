<?php
/**
 * Print Cost Calculator Actions
 */

require_once __DIR__ . '/../../../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user = getCurrentUser();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'calculate':
        calculateCost();
        break;
    case 'save_settings':
        saveCostSettings();
        break;
    case 'get_settings':
        getCostSettings();
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

function calculateCost() {
    $filamentUsedG = floatval($_POST['filament_used_g'] ?? 0);
    $printTimeMinutes = (int)($_POST['print_time_minutes'] ?? 0);
    $filamentType = $_POST['filament_type'] ?? 'PLA';

    // Get user's cost settings
    $settings = getUserCostSettings();

    // Calculate costs
    $filamentCost = 0;
    if ($filamentUsedG > 0 && $settings['filament_cost_per_kg'] > 0) {
        $filamentCost = ($filamentUsedG / 1000) * $settings['filament_cost_per_kg'];
    }

    $electricityCost = 0;
    if ($printTimeMinutes > 0 && $settings['printer_wattage'] > 0 && $settings['electricity_cost_per_kwh'] > 0) {
        $printTimeHours = $printTimeMinutes / 60;
        $kwhUsed = ($settings['printer_wattage'] / 1000) * $printTimeHours;
        $electricityCost = $kwhUsed * $settings['electricity_cost_per_kwh'];
    }

    $wearCost = 0;
    if ($printTimeMinutes > 0 && $settings['printer_cost'] > 0 && $settings['printer_lifespan_hours'] > 0) {
        $printTimeHours = $printTimeMinutes / 60;
        $costPerHour = $settings['printer_cost'] / $settings['printer_lifespan_hours'];
        $wearCost = $printTimeHours * $costPerHour;
    }

    $laborCost = 0;
    if ($settings['labor_cost_per_hour'] > 0) {
        // Estimate 5 minutes setup + 2 minutes per hour of print time
        $laborMinutes = 5 + ($printTimeMinutes / 60) * 2;
        $laborCost = ($laborMinutes / 60) * $settings['labor_cost_per_hour'];
    }

    $totalCost = $filamentCost + $electricityCost + $wearCost + $laborCost;

    // Add markup if configured
    if ($settings['markup_percent'] > 0) {
        $markup = $totalCost * ($settings['markup_percent'] / 100);
        $totalWithMarkup = $totalCost + $markup;
    } else {
        $markup = 0;
        $totalWithMarkup = $totalCost;
    }

    echo json_encode([
        'success' => true,
        'breakdown' => [
            'filament_cost' => round($filamentCost, 2),
            'electricity_cost' => round($electricityCost, 2),
            'wear_cost' => round($wearCost, 2),
            'labor_cost' => round($laborCost, 2),
            'subtotal' => round($totalCost, 2),
            'markup' => round($markup, 2),
            'total' => round($totalWithMarkup, 2)
        ],
        'currency' => $settings['currency'] ?? 'USD'
    ]);
}

function saveCostSettings() {
    global $user;

    $settings = [
        'filament_cost_per_kg' => floatval($_POST['filament_cost_per_kg'] ?? 0),
        'electricity_cost_per_kwh' => floatval($_POST['electricity_cost_per_kwh'] ?? 0),
        'printer_wattage' => (int)($_POST['printer_wattage'] ?? 0),
        'printer_cost' => floatval($_POST['printer_cost'] ?? 0),
        'printer_lifespan_hours' => (int)($_POST['printer_lifespan_hours'] ?? 0),
        'labor_cost_per_hour' => floatval($_POST['labor_cost_per_hour'] ?? 0),
        'markup_percent' => floatval($_POST['markup_percent'] ?? 0),
        'currency' => $_POST['currency'] ?? 'USD'
    ];

    // Store in user preferences (could use a separate table, but we'll use settings with user prefix)
    $db = getDB();
    $key = 'user_cost_settings_' . $user['id'];
    setSetting($key, json_encode($settings));

    echo json_encode(['success' => true]);
}

function getCostSettings() {
    $settings = getUserCostSettings();
    echo json_encode(['success' => true, 'settings' => $settings]);
}

function getUserCostSettings() {
    global $user;

    $db = getDB();
    $key = 'user_cost_settings_' . $user['id'];
    $json = getSetting($key, '');

    if ($json) {
        $settings = json_decode($json, true);
        if (is_array($settings)) {
            return $settings;
        }
    }

    // Default settings
    return [
        'filament_cost_per_kg' => 25.00,
        'electricity_cost_per_kwh' => 0.12,
        'printer_wattage' => 200,
        'printer_cost' => 300,
        'printer_lifespan_hours' => 5000,
        'labor_cost_per_hour' => 0,
        'markup_percent' => 0,
        'currency' => 'USD'
    ];
}
