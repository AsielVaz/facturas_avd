<?php
$pageTitle = $pageTitle ?? 'ERP Dinamico';
$pageDescription = $pageDescription ?? 'Administracion de facturas, bancos e inventario.';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($pageTitle) ?> | ERP Dinamico</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= htmlspecialchars($pageDescription) ?>">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="shortcut icon" href="<?= htmlspecialchars(SesionEmpresa::logoActual()) ?>">
    <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css">
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css">
    <script src="assets/js/config.min.js"></script>
    <style>
        .erp-page-title { letter-spacing: -.02em; }
        .erp-kpi-icon { width: 46px; height: 46px; display: grid; place-items: center; border-radius: 12px; }
        .erp-kpi-icon svg { width: 22px; height: 22px; }
        .erp-table td, .erp-table th { vertical-align: middle; }
        .erp-table thead th { white-space: nowrap; font-size: 11px; letter-spacing: .045em; text-transform: uppercase; }
        .erp-avatar { width: 36px; height: 36px; display: grid; place-items: center; border-radius: 10px; font-weight: 700; }
        .erp-account-card { position: relative; overflow: hidden; min-height: 184px; color: #fff; }
        .erp-account-card::after { content: ''; position: absolute; width: 190px; height: 190px; border-radius: 50%; right: -65px; top: -80px; background: rgba(255,255,255,.10); }
        .erp-account-card::before { content: ''; position: absolute; width: 110px; height: 110px; border-radius: 50%; right: 65px; bottom: -70px; background: rgba(255,255,255,.08); }
        .erp-stock-bar { height: 6px; }
        .erp-scanner { min-height: 315px; border: 1px dashed var(--bs-border-color); background: linear-gradient(135deg, rgba(var(--bs-primary-rgb),.05), rgba(var(--bs-info-rgb),.03)); }
        .erp-scan-frame { width: 230px; height: 135px; border: 2px solid var(--bs-primary); border-radius: 14px; position: relative; }
        .erp-scan-frame::before, .erp-scan-frame::after { content: ''; position: absolute; left: 15px; right: 15px; height: 2px; background: var(--bs-danger); box-shadow: 0 0 8px rgba(var(--bs-danger-rgb),.55); }
        .erp-scan-frame::before { top: 50%; }
        .erp-scan-frame::after { top: calc(50% + 4px); opacity: .25; }
        .nav-text { white-space: nowrap; }
        .main-logo-box .logo-box { height: var(--bs-topbar-height); display: flex; align-items: center; }
        .main-logo-box .logo-box > a { height: 100%; align-items: center; }
        .main-logo-box .logo-box > a.logo-dark { display: flex; }
        .main-logo-box .erp-company-logo { object-fit: contain; object-position: left center; background: #fff; border-radius: 7px; padding: 2px; }
        .main-logo-box .erp-company-logo.logo-lg { height: calc(var(--bs-topbar-height) - 12px) !important; width: auto; max-width: 150px; margin-left: 0; }
        .main-logo-box .erp-company-logo.logo-sm { height: calc(var(--bs-topbar-height) - 18px) !important; width: auto; max-width: 52px; }
        html[data-bs-theme=dark] .main-logo-box .logo-box > a.logo-light, .sidebar-dark .main-logo-box .logo-box > a.logo-light { display: flex; }
        @media (max-width: 575.98px) { .page-content { padding-left: 12px; padding-right: 12px; } }
    </style>
</head>
<body>
<div class="wrapper">
