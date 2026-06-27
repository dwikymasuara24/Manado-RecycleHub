<?php
$img = imagecreatefrompng('c:/laragon/www/v4/ide.png');
if (!$img) {
    echo "Error loading image";
    exit(1);
}
$rgb = imagecolorat($img, 100, 100);
$r = ($rgb >> 16) & 0xFF;
$g = ($rgb >> 8) & 0xFF;
$b = $rgb & 0xFF;
printf("#%02x%02x%02x\n", $r, $g, $b);
