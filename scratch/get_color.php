<?php
$img = imagecreatefrompng('idea box.png');
if (!$img) {
    echo "Could not load idea box.png\n";
    exit(1);
}
$rgb = imagecolorat($img, 10, 10);
$r = ($rgb >> 16) & 0xFF;
$g = ($rgb >> 8) & 0xFF;
$b = $rgb & 0xFF;
printf("Color: #%02x%02x%02x\n", $r, $g, $b);
