<?php

/**
 * Simple cache clearing script for hosting
 * Upload this file to your hosting and open it in browser
 */

echo "<h1>Laravel Cache Clear Script</h1>";
echo "<pre>";

// Change to Laravel root directory
chdir(__DIR__);

echo "Current directory: " . getcwd() . "\n\n";

// Clear different caches
$commands = [
    'php artisan route:clear' => 'Route cache cleared',
    'php artisan config:clear' => 'Config cache cleared',
    'php artisan cache:clear' => 'Application cache cleared',
    'php artisan view:clear' => 'View cache cleared',
];

foreach ($commands as $command => $message) {
    echo "Running: $command\n";
    $output = [];
    $return_var = 0;
    exec($command . ' 2>&1', $output, $return_var);

    if ($return_var === 0) {
        echo "✅ $message\n";
    } else {
        echo "❌ Failed to run command\n";
    }

    if (!empty($output)) {
        echo "Output: " . implode("\n", $output) . "\n";
    }
    echo "\n";
}

echo "\n=== Cache clearing completed! ===\n";
echo "You can delete this file now.\n";
echo "</pre>";
