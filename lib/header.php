<?php
/**
 * Shared page chrome. Included after cfc_require_login().
 * Expected locals: $siteName, $pageTitle, $activeNav.
 */
if (!defined('CFC_BOOTSTRAPPED')) { http_response_code(500); exit('bootstrap missing'); }
$siteName  = $siteName  ?? cfc_config('general.site_name', 'The Castle Fun Center');
$pageTitle = $pageTitle ?? 'Monitor';
$activeNav = $activeNav ?? '';
$nav = [
    'dashboard'  => ['index.php',      'Dashboard'],
    'monitors'   => ['monitors.php',   'Monitors'],
    'incidents'  => ['incidents.php',  'Incidents'],
    'tools'      => ['tools.php',      'Tools'],
    'settings'   => ['settings.php',   'Settings'],
    'status'     => ['status.php',     'Public Status'],
];
$csrf = cfc_csrf_token();
?><!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($pageTitle) ?> — <?= h($siteName) ?></title>
<meta name="csrf-token" content="<?= h($csrf) ?>">
<link rel="stylesheet" href="assets/app.css">
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
</head>
<body class="app">
<nav class="topnav">
    <div class="topnav-inner">
        <a class="brand" href="index.php">
            <img src="assets/logo.png" alt="" class="brand-logo" onerror="this.style.display='none'">
            <span class="brand-text">
                <span class="brand-name"><?= h($siteName) ?></span>
                <span class="brand-sub">Monitor</span>
            </span>
        </a>
        <div class="nav-links">
            <?php foreach ($nav as $key => [$href, $label]): ?>
                <a class="nav-link <?= $activeNav === $key ? 'active' : '' ?>" href="<?= h($href) ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="nav-right">
            <button class="icon-btn" id="theme-toggle" title="Toggle theme" type="button">
                <span class="ic dark-only">☾</span><span class="ic light-only">☀</span>
            </button>
            <a class="icon-btn" href="logout.php" title="Sign out">⏻</a>
        </div>
    </div>
</nav>
<main class="container">
