<?php
$info = getimagesize('idea box.png');
if ($info) {
    echo "Dimensions: " . $info[0] . "x" . $info[1] . "\n";
} else {
    echo "Could not read idea box.png\n";
}
