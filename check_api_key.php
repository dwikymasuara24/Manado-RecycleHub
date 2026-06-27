<?php
require_once __DIR__ . '/include/config.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

echo "Key: '" . getGmapsKey() . "'\n";
?>
