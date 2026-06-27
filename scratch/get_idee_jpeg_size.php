<?php
$info = getimagesize('idee.jpeg');
if ($info) {
    echo "Dimensions: " . $info[0] . "x" . $info[1] . "\n";
} else {
    echo "Could not read idee.jpeg\n";
}
