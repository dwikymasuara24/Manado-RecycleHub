<?php
@unlink(__DIR__ . '/original_live_tracking.php');
@unlink(__FILE__);
echo "Cleaned up!";
