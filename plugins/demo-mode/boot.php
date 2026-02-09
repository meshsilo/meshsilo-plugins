<?php
/**
 * Demo Mode Plugin
 *
 * Provides demo accounts, sample models, and periodic reset.
 */

// Load DemoMode library
require_once $pluginDir . '/lib/DemoMode.php';

// Register feature
$plugin->addFilter('available_features', function($features) {
    $features['demo_mode'] = [
        'name' => 'Demo Mode',
        'description' => 'Demo accounts, sample models, and periodic reset',
        'icon' => 'play-circle',
        'category' => 'Development',
        'default' => false,
    ];
    return $features;
});

// Register scheduled task for demo reset
$plugin->addFilter('scheduled_tasks', function($tasks) use ($pluginDir) {
    $tasks['demo:reset'] = [
        'name' => 'demo:reset',
        'schedule' => '0 * * * *',
        'callback' => function() {
            if (!function_exists('getSetting') || getSetting('demo_mode', '0') !== '1') {
                return 'Demo mode not enabled, skipped';
            }

            $demo = new DemoMode();
            $result = $demo->resetToDemo();

            if ($result['success']) {
                $msgs = implode('; ', $result['messages'] ?? []);
                return "Demo reset completed: $msgs";
            } else {
                $errs = implode('; ', $result['errors'] ?? []);
                throw new Exception("Demo reset failed: $errs");
            }
        },
        'enabled' => true,
        'timeout' => 600,
        'overlap' => false,
        'description' => 'Reset demo instance to sample data (hourly)',
    ];
    return $tasks;
});
