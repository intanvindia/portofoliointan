<?php
require_once 'config/koneksi.php';
session_start();

// 1. Validasi Guard: Hanya User dengan Role Orang Tua yang boleh masuk
if (!isset($_SESSION['id_user']) || $_SESSION['level'] != 'orang_tua') {
    header("Location: login.php");
    exit;
}

$id_user = $_SESSION['id_user'];
$id_anak = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id_anak <= 0) {
    header("Location: dashboard.php");
    exit;
}

// 2. Validasi Kepemilikan: Pastikan anak yang diakses adalah milik user yang login
$stmt_anak = $conn->prepare("SELECT nama_anak, usia FROM anak WHERE id_anak = ? AND id_user = ?");
$stmt_anak->bind_param("ii", $id_anak, $id_user);
$stmt_anak->execute();
$res_anak = $stmt_anak->get_result();

if ($res_anak->num_rows == 0) {
    // Jika tidak ditemukan, berarti anak tsb bukan miliknya atau tidak ada
    die("<div class='container py-5 text-center'><div class='alert alert-danger'>Akses ditolak atau data anak tidak ditemukan.</div></div>");
}
$info_anak = $res_anak->fetch_assoc();
$stmt_anak->close();

// 3. Ambil semua riwayat asesmen untuk anak ini
$stmt_riwayat = $conn->prepare("SELECT id_asesmen, tgl_asesmen FROM asesmen WHERE id_anak = ? ORDER BY tgl_asesmen DESC");
$stmt_riwayat->bind_param("i", $id_anak);
$stmt_riwayat->execute();
$result_riwayat = $stmt_riwayat->get_result();

// 4. Ambil data untuk grafik perkembangan
$sql_perkembangan = "SELECT 
                        asm.tgl_asesmen, 
                        k.nama_kriteria, 
                        AVG(da.nilai_input) as rata_nilai
                     FROM asesmen asm
                     JOIN detail_asesmen da ON asm.id_asesmen = da.id_asesmen
                     JOIN pertanyaan p ON da.id_pertanyaan = p.id_pertanyaan
                     JOIN kriteria k ON p.id_kriteria = k.id_kriteria
                     WHERE asm.id_anak = ?
                     GROUP BY asm.id_asesmen, k.id_kriteria 
                     ORDER BY asm.tgl_asesmen ASC, k.id_kriteria ASC";
$stmt_perkembangan = $conn->prepare($sql_perkembangan);
$stmt_perkembangan->bind_param("i", $id_anak);
$stmt_perkembangan->execute();
$res_perkembangan = $stmt_perkembangan->get_result();
$chart_data_perkembangan = [];
while ($row = $res_perkembangan->fetch_assoc()) {
    $chart_data_perkembangan[] = $row;
}
$stmt_perkembangan->close();

$page_title = "Profil Anak: " . htmlspecialchars($info_anak['nama_anak']) . " - SIMBIM";
include 'includes/header.php';
?>

<div class="container py-5">
    <!-- Header Profil Anak -->
    <div class="pb-card p-4 p-md-5 mb-5 shadow-sm">
        <div class="d-flex align-items-center">
            <div class="bg-primary text-white d-inline-block p-3 rounded-circle me-4 shadow">
                <i class="bi bi-person-badge fs-1"></i>
            </div>
            <div>
                <h1 class="fw-bold text-dark mb-1"><?= htmlspecialchars($info_anak['nama_anak']) ?></h1>
                <p class="text-muted lead mb-0">Usia: <?= $info_anak['usia'] ?> Tahun</p>
            </div>
        </div>
    </div>

    <!-- Grafik Perkembangan Potensi -->
    <?php if ($result_riwayat->num_rows > 1): // Tampilkan grafik hanya jika ada lebih dari 1 asesmen ?>
    <div class="pb-card p-4 mb-5">
        <h3 class="fw-bold mb-4 text-dark"><i class="bi bi-graph-up-arrow me-2 text-primary"></i>Grafik Perkembangan Potensi</h3>
        <div style="height: 400px; position: relative;">
            <canvas id="perkembanganChartProfil"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tabel Riwayat Asesmen -->
    <div class="pb-card p-4">
        <h3 class="fw-bold mb-4 text-dark"><i class="bi bi-clock-history me-2 text-primary"></i>Seluruh Riwayat Asesmen</h3>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width: 50px;">No.</th>
                        <th>Tanggal Pelaksanaan Tes</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result_riwayat->num_rows > 0):
                        $no = 1;
                        while ($row = $result_riwayat->fetch_assoc()): ?>
                        <tr>
                            <td class="fw-bold text-muted"><?= $no++ ?>.</td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-calendar-check me-2 text-success"></i>
                                    <span class="fw-semibold"><?= date('d F Y', strtotime($row['tgl_asesmen'])) ?></span>
                                </div>
                            </td>
                            <td class="text-center">
                                <a href="hitung_wp.php?id_asesmen=<?= $row['id_asesmen'] ?>" class="btn btn-sm btn-outline-primary rounded-pill px-4">
                                    <i class="bi bi-file-earmark-text me-1"></i> Lihat Hasil
                                </a>
                            </td>
                        </tr>
                        <?php endwhile;
                    else: ?>
                        <tr>
                            <td colspan="3" class="text-center py-5 text-muted">
                                <i class="bi bi-clipboard-x fs-1 d-block mb-2 opacity-25"></i>
                                Belum ada riwayat asesmen untuk anak ini.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-5 text-center">
        <a href="dashboard.php" class="btn btn-outline-secondary rounded-pill px-4">
            <i class="bi bi-arrow-left me-2"></i>Kembali ke Dashboard
        </a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Cek apakah elemen canvas ada di halaman
    const ctxPerkembangan = document.getElementById('perkembanganChartProfil');
    if (!ctxPerkembangan) return; // Hentikan jika tidak ada grafik

    const rawData = <?= json_encode($chart_data_perkembangan) ?>;
    const labels = [...new Set(rawData.map(item => new Date(item.tgl_asesmen).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })))];
    const kriteriaData = {};

    rawData.forEach(item => {
        if (!kriteriaData[item.nama_kriteria]) {
            kriteriaData[item.nama_kriteria] = {};
        }
        const dateLabel = new Date(item.tgl_asesmen).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
        kriteriaData[item.nama_kriteria][dateLabel] = parseFloat(item.rata_nilai).toFixed(2);
    });

    const datasets = [];
    const colors = ['#0d6efd', '#198754', '#ffc107', '#0dcaf0', '#dc3545', '#6c757d'];
    let colorIndex = 0;

    for (const kriteria in kriteriaData) {
        const data = labels.map(label => kriteriaData[kriteria][label] || null);
        datasets.push({
            label: kriteria,
            data: data,
            borderColor: colors[colorIndex % colors.length],
            backgroundColor: colors[colorIndex % colors.length],
            fill: false,
            tension: 0.1,
            spanGaps: true
        });
        colorIndex++;
    }

    new Chart(ctxPerkembangan, {
        type: 'line',
        data: {
            labels: labels,
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, max: 5 } },
            plugins: { legend: { position: 'top' } }
        }
    });
});
</script>
</body>
</html>

<?php $stmt_riwayat->close(); ?>