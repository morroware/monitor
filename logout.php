<?php
require_once __DIR__ . '/lib/bootstrap.php';
cfc_logout();
header('Location: login.php');
exit;
