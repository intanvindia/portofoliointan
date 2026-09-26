<?php
$page_title = "SIMBIM - Temukan Potensi Terbaik Ananda";
include 'includes/header.php';

// Ambil data kelas dari database, utamakan yang berstatus 'Aktif'
$res_kelas = $conn->query("SELECT * FROM kelas_bimbel WHERE deleted_at IS NULL ORDER BY FIELD(status_kelas, 'Aktif', 'Coming Soon'), nama_kelas ASC");

// Ambil Statistik Global untuk Chart
$total_anak = $conn->query("SELECT COUNT(*) as t FROM anak")->fetch_assoc()['t'];
$total_tes = $conn->query("SELECT COUNT(*) as t FROM asesmen")->fetch_assoc()['t'];

$lang = $_SESSION['lang'] ?? 'id';
$content = [
    'id' => [
        'h1' => 'Temukan <span class="text-primary">Bakat Terbaik</span> Ananda Sekarang',
        'step_title' => 'Hanya 3 Langkah Mudah',
        'feat_api' => 'Integrasi API & Export',
        'feat_api_desc' => 'Hasil asesmen dapat diekspor ke Excel dan tersedia via API untuk kebutuhan institusi.',
        'stats_title' => 'Statistik Penggunaan'
    ],
    'en' => [
        'h1' => 'Discover Your Child\'s <span class="text-primary">Best Talent</span> Now',
        'step_title' => 'Only 3 Easy Steps',
        'feat_api' => 'API Integration & Export',
        'feat_api_desc' => 'Assessment results can be exported to Excel and are available via API for institutional needs.',
        'stats_title' => 'Global Statistics'
    ]
];
?>

    <main class="container py-5 mt-4">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 text-center text-lg-start order-2 order-lg-1">
                <h1 class="display-4 fw-bold lh-1 mb-3"><?= $content[$lang]['h1'] ?></h1>
                <p class="lead text-muted mb-4"><?= htmlspecialchars($settings['tagline'] ?? 'Berikan dukungan pendidikan yang tepat sejak dini. SIMBIM menggunakan sistem analisis cerdas untuk merekomendasikan kelas yang sesuai dengan kecerdasan buah hati Anda.') ?></p>
                <div class="d-grid gap-2 d-md-flex justify-content-md-start">
                    <?php if ($sudah_login && $level_user == 'orang_tua'): ?>
                        <a href="asesmen.php" class="btn btn-primary btn-lg px-4 me-md-2 fw-bold shadow-sm rounded-pill"><i class="bi bi-play-circle me-2"></i>Mulai Tes Bakat</a>
                    <?php elseif ($sudah_login && $level_user == 'admin'): ?>
                        <a href="admin_dashboard.php" class="btn btn-primary btn-lg px-4 me-md-2 fw-bold shadow-sm rounded-pill"><i class="bi bi-speedometer2 me-2"></i>Panel Admin</a>
                    <?php elseif ($sudah_login && $level_user == 'staf'): ?>
                        <a href="staff_dashboard.php" class="btn btn-warning btn-lg px-4 me-md-2 fw-bold shadow-sm rounded-pill text-dark"><i class="bi bi-person-workspace me-2"></i>Buka Panel Staf</a>
                    <?php else: ?>
                        <a href="daftar.php" class="btn btn-primary btn-lg px-4 me-md-2 fw-bold shadow-sm rounded-pill"><i class="bi bi-person-plus me-2"></i>Daftar Akun Gratis</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-6 order-1 order-lg-2">
                <img src="<?= htmlspecialchars($settings['hero_banner'] ?? 'https://img.freepik.com/free-vector/kids-learning-online-home_23-2148518135.jpg') ?>" class="d-block mx-lg-auto img-fluid rounded-5 shadow-lg border border-5 border-white" alt="Anak Belajar" loading="lazy" style="max-height: 450px; width: 100%; object-fit: cover;">
            </div>
        </div>
    </main>

    <!-- Statistik Interaktif (Dynamic Chart) -->
    <section class="py-5 bg-light">
        <div class="container">
            <div class="row align-items-center g-4">
                <div class="col-md-5">
                    <h2 class="fw-bold mb-3"><?= $content[$lang]['stats_title'] ?></h2>
                    <p class="text-muted">SIMBIM telah dipercaya oleh ratusan orang tua untuk memetakan bakat anak secara akurat melalui algoritma Weighted Product.</p>
                    <div class="row g-3">
                        <div class="col-6"><div class="p-3 bg-white shadow-sm rounded-3"><h3><?= $total_anak ?></h3><small class="text-muted">Anak Terdaftar</small></div></div>
                        <div class="col-6"><div class="p-3 bg-white shadow-sm rounded-3"><h3><?= $total_tes ?></h3><small class="text-muted">Tes Selesai</small></div></div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="bg-white p-4 shadow-sm rounded-4">
                        <canvas id="globalChart" height="200"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-5">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold"><?= $content[$lang]['step_title'] ?></h2>
            </div>
            <div class="row g-4 text-center">
                <div class="col-md-3">
                    <div class="pb-card p-4 h-100">
                        <div class="mb-3 text-primary"><i class="bi bi-person-check fs-1"></i></div>
                        <h4 class="fw-bold">1. Daftar Akun</h4>
                        <p class="text-muted small">Buat akun orang tua untuk menyimpan riwayat asesmen secara aman.</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="pb-card p-4 h-100">
                        <div class="mb-3 text-primary"><i class="bi bi-clipboard2-check fs-1"></i></div>
                        <h4 class="fw-bold">2. Isi Asesmen</h4>
                        <p class="text-muted small">Berikan penilaian jujur mengenai kebiasaan harian buah hati Anda.</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="pb-card p-4 h-100">
                        <div class="mb-3 text-primary"><i class="bi bi-star-fill fs-1"></i></div>
                        <h4 class="fw-bold">3. Dapatkan Hasil</h4>
                        <p class="text-muted small">Lihat rekomendasi kelas bimbel terbaik berdasarkan analisis pakar.</p>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="pb-card p-4 h-100 border-primary border-top border-5">
                        <div class="mb-3 text-success"><i class="bi bi-file-earmark-spreadsheet fs-1"></i></div>
                        <h4 class="fw-bold"><?= $content[$lang]['feat_api'] ?></h4>
                        <p class="text-muted small"><?= $content[$lang]['feat_api_desc'] ?></p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-5">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold"><i class="bi bi-building me-2 text-primary"></i>Program Kelas Kami</h2>
                <p class="text-muted">Pilih program yang paling sesuai dengan minat dan bakat buah hati Anda.</p>
            </div>
            <div class="row g-4">
                <?php while($k = $res_kelas->fetch_assoc()): ?>
                    <div class="col-md-4">
                        <div class="card h-100 border-0 shadow-sm p-3 pb-card">
                            <div class="card-body">
                                <span class="badge <?= $k['status_kelas'] == 'Aktif' ? 'bg-success' : 'bg-warning text-dark' ?> mb-2 text-uppercase fw-bold">
                                    <?= $k['status_kelas'] == 'Aktif' ? 'Tersedia' : 'Coming Soon' ?>
                                </span>
                                <h3 class="h5 fw-bold text-dark"><?= htmlspecialchars($k['nama_kelas']) ?></h3>
                                <p class="text-muted small mb-0">Program unggulan untuk mengasah kecerdasan dan kreativitas anak dalam bidang <?= htmlspecialchars($k['nama_kelas']) ?>.</p>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>
    </section>

    <footer class="py-4 text-center text-muted border-top bg-light">
        &copy; <?= date('Y'); ?> <?= htmlspecialchars($settings['footer_text'] ?? 'SIMBIM Indonesia. All Rights Reserved.') ?>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const ctx = document.getElementById('globalChart');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Minggu 1', 'Minggu 2', 'Minggu 3', 'Minggu 4'],
                datasets: [{
                    label: 'Tren Asesmen Baru',
                    data: [12, 19, 15, 25],
                    borderColor: '#4A90E2',
                    tension: 0.4,
                    fill: true,
                    backgroundColor: 'rgba(74, 144, 226, 0.1)'
                }]
            },
            options: { responsive: true, plugins: { legend: { display: false } } }
        });
    </script>
</body>
</html>