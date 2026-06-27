<?php
$info = getimagesize('diy.jpg');
if ($info) {
    echo "diy.jpg: " . $info[0] . "x" . $info[1] . "\n";
} else {
    echo "Could not read diy.jpg\n";
}
