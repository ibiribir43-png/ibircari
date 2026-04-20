<?php
require 'config.php';
adminGirisKontrol(); // Oturum kontrolü

// Duyuru ekleme
if(isset($_POST['ekle'])){
    $mesaj = trim($_POST['mesaj']);
    $tip = $_POST['tip'];
    if($mesaj !== ''){
        $stmt = $pdo->prepare("INSERT INTO admin_duyurular (mesaj, tip) VALUES (:mesaj, :tip)");
        $stmt->execute(['mesaj'=>$mesaj, 'tip'=>$tip]);
        header("Location: admin_duyurular.php");
        exit;
    }
}

// Duyuru silme
if(isset($_GET['sil'])){
    $id = (int)$_GET['sil'];
    $stmt = $pdo->prepare("DELETE FROM admin_duyurular WHERE id = :id");
    $stmt->execute(['id'=>$id]);
    header("Location: admin_duyurular.php");
    exit;
}

// Tüm duyuruları çek
$duyurular = $pdo->query("SELECT * FROM admin_duyurular ORDER BY tarih DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Duyurular</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/admin.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php">ibiR Admin</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <span class="nav-link text-white">Hoşgeldin, <?= htmlspecialchars($_SESSION['admin_adi']); ?></span>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="dashboard.php">Dashboard</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="logout.php">Çıkış Yap</a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <h2>Duyurular</h2>
    <hr>

    <!-- Yeni Duyuru Ekle -->
    <div class="card mb-4">
        <div class="card-header">Yeni Duyuru Ekle</div>
        <div class="card-body">
            <form method="post">
                <div class="mb-3">
                    <textarea name="mesaj" class="form-control" placeholder="Mesaj" required></textarea>
                </div>
                <div class="mb-3">
                    <select name="tip" class="form-select">
                        <option value="info">Bilgi</option>
                        <option value="warning">Uyarı</option>
                        <option value="danger">Acil</option>
                    </select>
                </div>
                <button type="submit" name="ekle" class="btn btn-primary">Ekle</button>
            </form>
        </div>
    </div>

    <!-- Mevcut Duyurular -->
    <div class="card">
        <div class="card-header">Mevcut Duyurular</div>
        <div class="card-body p-0">
            <table class="table table-striped mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Mesaj</th>
                        <th>Tip</th>
                        <th>Tarih</th>
                        <th>İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($duyurular as $d): ?>
                    <tr>
                        <td><?= $d['id']; ?></td>
                        <td><?= htmlspecialchars($d['mesaj']); ?></td>
                        <td><?= $d['tip']; ?></td>
                        <td><?= $d['tarih']; ?></td>
                        <td>
                            <a href="?sil=<?= $d['id']; ?>" class="btn btn-sm btn-danger"
                               onclick="return confirm('Silmek istediğine emin misin?')">Sil</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($duyurular)): ?>
                    <tr>
                        <td colspan="5" class="text-center">Henüz duyuru yok.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<footer class="bg-dark text-white text-center py-3 mt-5">
    &copy; <?= date('Y'); ?> ibiR Admin Paneli
</footer>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/admin.js"></script>
</body>
</html>