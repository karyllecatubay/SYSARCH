<?php
// Simple autoloader for TCPDF
if (file_exists(__DIR__ . '/../tcpdf/tcpdf.php')) {
    require_once __DIR__ . '/../tcpdf/tcpdf.php';
}

// Simple autoloader for PhpSpreadsheet
if (file_exists(__DIR__ . '/../PhpSpreadsheet/src/PhpSpreadsheet/Spreadsheet.php')) {
    require_once __DIR__ . '/../PhpSpreadsheet/src/PhpSpreadsheet/Spreadsheet.php';
    
    spl_autoload_register(function($class) {
        $prefix = 'PhpOffice\\PhpSpreadsheet\\';
        $base_dir = __DIR__ . '/../PhpSpreadsheet/src/PhpSpreadsheet/';
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    });
}
