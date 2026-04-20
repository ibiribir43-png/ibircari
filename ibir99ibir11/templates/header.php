<?php
require_once __DIR__ . '/../includes/config.php';
adminGirisKontrol();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $SITE_TITLE ?? 'ibiR CRM Admin' ?></title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom Admin CSS -->
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>

<!-- Sidebar -->
<nav class="sidebar">
    <a class="sidebar-brand" href="dashboard.php">
        <i class="fas fa-rocket me-2"></i> ibiR Core
    </a>
    <ul class="sidebar-nav">
        <!-- 1. Dashboard -->
        <li>
            <a href="dashboard.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>">
                <i class="fas fa-home"></i> Dashboard
            </a>
        </li>

        <li class="nav-heading">Yönetim Modülleri</li>
        
        <!-- 2. Firma Yönetimi -->
        <li>
            <a href="firmalar.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'firmalar.php' ? 'active' : '' ?>">
                <i class="fas fa-building"></i> Firma Yönetimi
            </a>
        </li>

        <!-- 3. Paket Yönetimi -->
        <li>
            <a href="paketler.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'paketler.php' ? 'active' : '' ?>">
                <i class="fas fa-box"></i> Paket Yönetimi
            </a>
        </li>

        <!-- 4. Sistem Kullanıcıları -->
        <li>
            <a href="kullanicilar.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'kullanicilar.php' ? 'active' : '' ?>">
                <i class="fas fa-users-cog"></i> Sistem Kullanıcıları
            </a>
        </li>

        <!-- YENİ: Merkezi Destek Sistemi -->
        <li>
            <a href="destek.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'destek.php' ? 'active' : '' ?>">
                <i class="fas fa-headset"></i> Destek Talepleri
            </a>
        </li>

        <li class="nav-heading">Sistem & Raporlar</li>

        <!-- 5. Sistem Ayarları -->
        <li>
            <a href="ayarlar.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'ayarlar.php' ? 'active' : '' ?>">
                <i class="fas fa-cogs"></i> Sistem Ayarları
            </a>
        </li>

        <!-- 6. Raporlar -->
        <li>
            <a href="raporlar.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'raporlar.php' ? 'active' : '' ?>">
                <i class="fas fa-chart-line"></i> Raporlar ve İstatistik
            </a>
        </li>

        <!-- 7. Bildirimler -->
        <li>
            <a href="bildirimler.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'bildirimler.php' ? 'active' : '' ?>">
                <i class="fas fa-bell"></i> Bildirim & İletişim
            </a>
        </li>

        <!-- 8. Güvenlik -->
        <li>
            <a href="guvenlik.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'guvenlik.php' ? 'active' : '' ?>">
                <i class="fas fa-shield-alt"></i> Güvenlik
            </a>
        </li>

        <!-- 9. Sistem Yönetimi -->
        <li>
            <a href="sistem.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'sistem.php' ? 'active' : '' ?>">
                <i class="fas fa-server"></i> Sistem Yönetimi (Yedek)
            </a>
        </li>
    </ul>
</nav>

<!-- Main Wrapper -->
<div class="main-wrapper">
    <!-- Topbar -->
    <header class="topbar">
        <div>
            <button class="btn btn-link d-md-none text-dark" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>
        </div>
        <div class="d-flex align-items-center gap-3">
            <span class="text-muted"><i class="fas fa-user-shield me-1"></i> Admin: <strong><?= e($_SESSION['admin_adi'] ?? 'Süper Admin') ?></strong></span>
            <div class="vr"></div>
            <a href="logout.php" class="btn btn-outline-danger btn-sm"><i class="fas fa-sign-out-alt me-1"></i> Çıkış</a>
        </div>
    </header>

    <!-- Content Area -->
    <main class="content">
        <!-- Flash Mesajları -->
        <?php $flash = getFlash(); if($flash): ?>
            <div class="alert alert-<?= $flash['tip'] ?> alert-dismissible fade show shadow-sm">
                <?= $flash['mesaj'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>