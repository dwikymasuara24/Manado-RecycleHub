<?php
$img = imagecreatefrompng('c:/laragon/www/v4/Footer.png');
if (!$img) {
    echo "Could not load Footer.png\n";
    exit(1);
}
$cropped = imagecropauto($img, IMG_CROP_DEFAULT);
if ($cropped !== false) {
    printf("Cropped size: %dx%d\n", imagesx($cropped), imagesy($cropped));
} else {
    echo "No cropping possible\n";
}
