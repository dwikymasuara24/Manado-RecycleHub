<?php
$info = getimagesize('c:/laragon/www/v4/ide.png');
if ($info) {
    file_put_contents('c:/laragon/www/v4/scratch/ide_output.txt', sprintf("%dx%d", $info[0], $info[1]));
} else {
    file_put_contents('c:/laragon/www/v4/scratch/ide_output.txt', "Error");
}
