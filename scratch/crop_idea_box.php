<?php
$img = imagecreatefrompng('c:/laragon/www/v4/idea box.png');
if (!$img) {
    echo "Error loading image\n";
    exit(1);
}
$w = imagesx($img);
$h = imagesy($img);

$minX = $w;
$maxX = 0;
$minY = $h;
$maxY = 0;

for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
        $rgb = imagecolorat($img, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        // If the pixel is not white/near white
        if ($r < 250 || $g < 250 || $b < 250) {
            if ($x < $minX) $minX = $x;
            if ($x > $maxX) $maxX = $x;
            if ($y < $minY) $minY = $y;
            if ($y > $maxY) $maxY = $y;
        }
    }
}

printf("Bounding box of content: X from %d to %d (width %d), Y from %d to %d (height %d)\n", 
    $minX, $maxX, $maxX - $minX, $minY, $maxY, $maxY - $minY);
