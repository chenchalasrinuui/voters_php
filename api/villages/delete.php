<?php
// api/villages/delete.php - Direct endpoint for /api/villages/delete
require_once __DIR__ . '/../../cors.php';
$_GET['action'] = 'delete';
require_once __DIR__ . '/../../routes/villages.php';
