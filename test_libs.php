<?php
echo "Testing library loading...\n\n";

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    echo "✓ Autoloader loaded\n";
}

if (class_exists('TCPDF')) {
    echo "✓ TCPDF is available\n";
} else {
    echo "✗ TCPDF not available\n";
}

if (class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
    echo "✓ PhpSpreadsheet is available\n";
} else {
    echo "✗ PhpSpreadsheet not available\n";
}

echo "\nDone!\n";
