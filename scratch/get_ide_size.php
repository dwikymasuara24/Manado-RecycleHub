<?php
$info = getimagesize('ide.png');
if ($info) {
    echo "Dimensions: " . $info[0] . "x" . $info[1] . "\n";
} else {
    echo "Could not read ide.png\n";
}
