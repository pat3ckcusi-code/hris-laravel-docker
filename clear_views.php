<?php
$viewDir = __DIR__ . '/storage/framework/views';
$files = glob($viewDir . '/*.php');
foreach ($files as $file) {
    if (basename($file) !== '.gitignore') {
        unlink($file);
    }
}
echo "View cache cleared.\n";
