<?php
$info = getimagesize('idee.jpeg');
if ($info) {
    file_put_contents('scratch/idee_jpeg_info.txt', "Dimensions: " . $info[0] . "x" . $info[1] . "\n");
} else {
    file_put_contents('scratch/idee_jpeg_info.txt', "Could not read idee.jpeg\n");
}
