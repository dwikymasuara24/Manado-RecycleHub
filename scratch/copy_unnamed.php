<?php
if (file_exists(__DIR__ . '/../unnamed.png')) {
    copy(__DIR__ . '/../unnamed.png', __DIR__ . '/../unnamed.jpg');
    echo "Copied unnamed.png to unnamed.jpg successfully!\n";
} else {
    echo "unnamed.png not found!\n";
}
