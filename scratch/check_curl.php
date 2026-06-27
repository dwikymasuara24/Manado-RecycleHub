<?php
$html = file_get_contents("http://v4.test/daur_ulang.php?tab=track&q=MRH-S-002");
if (strpos($html, 'MRH-S-002') !== false) {
    echo "Found MRH-S-002 in HTML! Search working perfectly.\n";
    
    // Output the surrounding HTML of the track-card for verification
    if (preg_match('/<div class="track-card">.*?<\/div>/s', $html, $matches)) {
        echo "Track Card:\n" . $matches[0] . "\n";
    } else {
        echo "No track-card div found, outputting first 1000 chars of HTML...\n";
        echo substr(strip_tags($html), 0, 1000) . "\n";
    }
} else {
    echo "Not found in HTML!\n";
}
