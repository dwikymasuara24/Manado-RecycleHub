<?php
$img = imagecreatefrompng('c:/laragon/www/v4/idea box.png');
if (!$img) {
    file_put_contents('scratch/idea_box_info.txt', "Error loading image");
    exit(1);
}
$w = imagesx($img);
$h = imagesy($img);

// Sample background color at (10, 10)
$rgb = imagecolorat($img, 10, 10);
$r = ($rgb >> 16) & 0xFF;
$g = ($rgb >> 8) & 0xFF;
$b = $rgb & 0xFF;
$hex = sprintf("#%02x%02x%02x", $r, $g, $b);

file_put_contents('c:/laragon/www/v4/scratch/idea_box_info.txt', "Dimensions: {$w}x{$h}\nColor: {$hex}\n");
