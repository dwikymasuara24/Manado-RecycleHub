<?php

require_once __DIR__ . '/../include/auth.php';
requireRole('officer');
header('Location: ' . baseUrl('officer/dashboard'));
exit;
