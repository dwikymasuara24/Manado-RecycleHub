<?php
$src = "C:\\Users\\LENOVO\\.gemini\\antigravity\\brain\\2ac870e2-a79a-4ea2-934c-421b915e546c\\dotted_map_1781832485929.png";
$dest = __DIR__ . '/../images/dotted_map.png';

echo "Copying $src to $dest...\n";
if (copy($src, $dest)) {
    echo "Copied successfully!\n";
} else {
    echo "Failed to copy!\n";
}
