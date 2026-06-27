<?php
$size = getimagesize(__DIR__ . '/../unnamed.png');
echo "unnamed.png dimensions: " . $size[0] . "x" . $size[1] . "\n";

$size2 = getimagesize(__DIR__ . '/../logo_square.png');
echo "logo_square.png dimensions: " . $size2[0] . "x" . $size2[1] . "\n";
