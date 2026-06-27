<?php
$url = "https://lh3.googleusercontent.com/sitesv/AA5AbUD0EyDqS92joFFxyKABM0Ex4SbQdmdZEPHsUj_I1dKtXP-ZuOCF8xFdMk0jKN5gv8swHOC_b0B8QVZgZoq2sDz40mDMcKIAuEj4kXH4RqfoNNR5t3yM_IQ-ybSHQOID0XCwQ5VnrfCPByq0rEI6xUEb_acgtV1jRA4oJAykev3KmCx6dRl8k02bMJU=w1600";
$dest = __DIR__ . '/../images/home_banner.png';

echo "Downloading from $url with spoofed headers...\n";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
curl_setopt($ch, CURLOPT_REFERER, 'https://sites.google.com/');

$content = curl_exec($ch);
if (curl_errno($ch)) {
    echo "Curl error: " . curl_error($ch) . "\n";
} else {
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "HTTP Status Code: $http_code\n";
}
curl_close($ch);

if ($content && $http_code == 200) {
    if (file_put_contents($dest, $content)) {
        echo "Downloaded successfully to $dest!\n";
    } else {
        echo "Failed to save file to $dest.\n";
    }
} else {
    echo "Failed to retrieve content from URL. Content length: " . strlen($content) . "\n";
}
