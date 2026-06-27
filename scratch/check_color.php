<?php
$img = imagecreatefromjpeg('c:/laragon/www/v4/medsos.jpeg');
if (!$img) {
    echo "Could not load medsos.jpeg\n";
    exit(1);
}
for ($y = 0; $y < imagesy($img); $y += 50) {
    for ($x = 0; $x < imagesx($img); $x += 100) {
        $rgb = imagecolorat($img, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        if ($r < 240 || $g < 240 || $b < 240) {
            printf("Non-white color at (%d, %d): RGB(%d, %d, %d) -> #%02x%02x%02x\n", $x, $y, $r, $g, $b, $r, $g, $b);
            break 2;
        }
    }
}
