<?php
$info = getimagesize('idea.png');
if ($info) {
    echo "Dimensions: " . $info[0] . "x" . $info[1] . "\n";
} else {
    echo "Could not read idea.png\n";
}
