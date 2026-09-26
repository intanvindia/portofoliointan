<?php
require_once 'config/koneksi.php';
$settings_res = $conn->query("SELECT * FROM pengaturan");
$settings = [];
while($s = $settings_res->fetch_assoc()) $settings[$s['nama_key']] = $s['nilai_value'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance - <?= htmlspecialchars($settings['site_name'] ?? 'SIMBIM') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="bg-light d-flex align-items-center min-vh-100">
    <div class="container text-center">
        <div class="pb-card p-5 shadow-lg border-0 d-inline-block" style="max-width: 600px;">
            <div class="text-primary mb-4">
                <i class="bi bi-tools" style="font-size: 5rem;"></i>
            </div>
            <h1 class="fw-bold">Sedang Dalam Pemeliharaan</h1>
            <p class="text-muted lead mb-4"><?= htmlspecialchars($settings['site_name'] ?? 'Sistem') ?> sedang melakukan pembaruan rutin untuk meningkatkan layanan. Kami akan segera kembali!</p>
            <hr>
            <p class="small text-muted mb-0">Jika ada keperluan mendesak, hubungi admin melalui WhatsApp:</p>
            <a href="https://wa.me/<?= $settings['whatsapp_admin'] ?? '#' ?>" class="btn btn-success mt-2 rounded-pill px-4" target="_blank"><i class="bi bi-whatsapp me-2"></i>Hubungi Admin</a>
        </div>
    </div>
</body>
</html>