<?php
/**
 * Teams Plugin
 *
 * Team collaboration with shared model access and invitations.
 */

// Register routes
$plugin->addRoute('POST', '/actions/teams', ['file' => $pluginDir . '/actions/teams.php'], 'actions.teams');

// Register feature
$plugin->addFilter('available_features', function($features) {
    $features['teams'] = [
        'name' => 'Teams',
        'description' => 'Team collaboration with shared model access',
        'icon' => 'users',
        'category' => 'Collaboration',
        'default' => true,
    ];
    return $features;
});
