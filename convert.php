<?php
header('Content-Type: text/plain');

$files = [
    'c:/laragon/www/v4/unnamed.png',
    'c:/laragon/www/v4/logo_square.png'
];

foreach ($files as $f) {
    if (file_exists($f)) {
        $info = getimagesize($f);
        if ($info) {
            echo "File $f is of type: " . $info['mime'] . "\n";
            // Convert appropriately
            if ($info['mime'] === 'image/jpeg') {
                $im = imagecreatefromjpeg($f);
            } elseif ($info['mime'] === 'image/png') {
                $im = imagecreatefrompng($f);
            } elseif ($info['mime'] === 'image/webp') {
                $im = imagecreatefromwebp($f);
            } else {
                $im = null;
            }
            
            if ($im) {
                unlink($f);
                imagepng($im, $f);
                imagedestroy($im);
                echo "Successfully normalized $f to PNG.\n";
            } else {
                echo "Failed to create image resource for $f.\n";
            }
        } else {
            echo "Failed to get image size for $f.\n";
        }
    } else {
        echo "$f does not exist.\n";
    }
}
