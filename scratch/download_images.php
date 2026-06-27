<?php
$imagesDir = __DIR__ . '/../images';
if (!is_dir($imagesDir)) {
    mkdir($imagesDir, 0777, true);
    echo "Created directory: images/\n";
}

$images = [
    'global-recycling-day.png' => 'https://images.unsplash.com/photo-1532996122724-e3c354a0b15b?auto=format&fit=crop&w=600&h=450&q=80',
    'hari-daur-ulang.png' => 'https://images.unsplash.com/photo-1503596476-1c12a8ba09a9?auto=format&fit=crop&w=600&h=450&q=80',
    'do-you-recycle.jpg' => 'https://images.unsplash.com/photo-1611284446314-60a58ac0deb9?auto=format&fit=crop&w=600&h=450&q=80',
    'instagram-30-oktober-2021.jpg' => 'https://images.unsplash.com/photo-1509042239860-f550ce710b93?auto=format&fit=crop&w=600&h=450&q=80',
    'instagram-25-oktober-2021.jpg' => 'https://images.unsplash.com/photo-1528190336454-13cd56b45b5a?auto=format&fit=crop&w=600&h=450&q=80',
    'instagram-22-oktober-2021.jpg' => 'https://images.unsplash.com/photo-1605600611228-18220df5ed37?auto=format&fit=crop&w=600&h=450&q=80'
];

foreach ($images as $filename => $url) {
    $filepath = $imagesDir . '/' . $filename;
    echo "Downloading {$filename}... ";
    
    $downloaded = false;
    
    // Try file_get_contents first if allow_url_fopen is enabled
    if (ini_get('allow_url_fopen')) {
        $content = @file_get_contents($url);
        if ($content !== false) {
            file_put_contents($filepath, $content);
            $downloaded = true;
            echo "Success (file_get_contents)\n";
        }
    }
    
    // Try cURL if not downloaded yet
    if (!$downloaded && function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($content !== false && $httpCode === 200) {
            file_put_contents($filepath, $content);
            $downloaded = true;
            echo "Success (cURL)\n";
        }
    }
    
    // Fallback: Generate local GD image if downloading failed
    if (!$downloaded) {
        echo "Failed to download. Generating fallback image... ";
        if (function_exists('imagecreatetruecolor')) {
            $im = imagecreatetruecolor(600, 450);
            
            // Generate some distinct background colors
            $colors = [
                'global-recycling-day.png' => [46, 125, 50],
                'hari-daur-ulang.png' => [27, 94, 32],
                'do-you-recycle.jpg' => [56, 142, 60],
                'instagram-30-oktober-2021.jpg' => [121, 85, 72],
                'instagram-25-oktober-2021.jpg' => [0, 150, 136],
                'instagram-22-oktober-2021.jpg' => [33, 150, 243]
            ];
            
            $rgb = $colors[$filename] ?? [100, 100, 100];
            $bgColor = imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
            imagefill($im, 0, 0, $bgColor);
            
            // Draw text
            $textColor = imagecolorallocate($im, 255, 255, 255);
            $text = str_replace(['.png', '.jpg', '-'], ['', '', ' '], $filename);
            $text = ucwords($text);
            
            // Simple string drawing since TTF fonts might not be available
            imagestring($im, 5, 50, 200, $text, $textColor);
            imagestring($im, 3, 50, 230, "Manado Recycle Hub", $textColor);
            
            if (strpos($filename, '.png') !== false) {
                imagepng($im, $filepath);
            } else {
                imagejpeg($im, $filepath, 90);
            }
            imagedestroy($im);
            echo "Created GD image.\n";
        } else {
            // Write a tiny 1x1 transparent PNG fallback if GD not available
            $transparentPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
            file_put_contents($filepath, $transparentPng);
            echo "Created tiny PNG fallback.\n";
        }
    }
}
echo "All done!\n";
