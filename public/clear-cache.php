<?php
// Clear all Laravel caches
$base = __DIR__;

// View cache
$viewDir = $base . '/storage/framework/views';
if (is_dir($viewDir)) {
    $files = glob($viewDir . '/*.php');
    foreach ($files as $file) {
        if (basename($file) !== '.gitignore') {
            @unlink($file);
        }
    }
}

// Bootstrap cache
$bootstrapCacheDir = $base . '/bootstrap/cache';
if (is_dir($bootstrapCacheDir)) {
    $files = glob($bootstrapCacheDir . '/*.php');
    foreach ($files as $file) {
        @unlink($file);
    }
}

// OPcache
if (function_exists('opcache_reset')) {
    opcache_reset();
}

echo "✓ Caches cleared\n";
echo "- View files deleted\n";
echo "- Bootstrap cache cleared\n";
if (function_exists('opcache_reset')) {
    echo "- OPcache reset\n";
}
