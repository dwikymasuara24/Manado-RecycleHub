<?php
$img = imagecreatefromjpeg('c:/laragon/www/v4/medsos.jpeg');
if (!$img) {
    echo "Could not load medsos.jpeg\n";
    exit(1);
}
// Let's print colors at some coordinate that represents the background green
$rgb = imagecolorat($img, imagesx($img) / 2, 10);
$r = ($rgb >> 16) & 0xFF;
$g = ($rgb >> 8) & 0xFF;
$b = $rgb & 0xFF;
printf("Middle-top color: RGB(%d, %d, %d) -> #%02x%02x%02x\n", $r, $g, $b, $r, $g, $b);

$rgb2 = imagecolorat($img, 10, imagesy($img) / 2);
$r2 = ($rgb2 >> 16) & 0xFF;
$g2 = ($rgb2 >> 8) & 0xFF;
$b2 = $rgb2 & 0xFF;
printf("Middle-left color: RGB(%d, %d, %d) -> #%02x%02x%02x\n", $r2, $g2, $b2, $r2, $g2, $b2);
