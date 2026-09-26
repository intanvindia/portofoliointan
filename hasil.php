<?php
// Panggil koneksi database dan aktifkan session
require_once 'config/koneksi.php';
session_start();

// Proteksi Halaman
if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

$id_asesmen = isset($_GET['id_asesmen']) ? intval($_GET['id_asesmen']) : 0;
if ($id_asesmen <= 0) {
    // Redirect to dashboard or show error if ID is not valid
    header("Location: dashboard.php");
    exit;
}

// Ambil Pengaturan Global (untuk footer)
$settings = [];
$res_set = $conn->query("SELECT * FROM pengaturan");
while($s = $res_set->fetch_assoc()) {
    $settings[$s['nama_key']] = $s['nilai_value'];
}

// Ambil info nama anak dan tanggal asesmen
$stmt_mhs = $conn->prepare("SELECT a.nama_anak, a.usia, asm.tgl_asesmen FROM asesmen asm
                            JOIN anak a ON asm.id_anak = a.id_anak
                            WHERE asm.id_asesmen = ?");
$stmt_mhs->bind_param("i", $id_asesmen);
$stmt_mhs->execute();
$res_mhs = $stmt_mhs->get_result();

if ($res_mhs->num_rows == 0) {
    $stmt_mhs->close();
    header("Location: dashboard.php"); // Redirect if asesmen not found
    exit;
}
$info_anak = $res_mhs->fetch_assoc();
$stmt_mhs->close();


// =========================================================
// ALGORITMA WP: NORMALISASI MATRIKS & PEMBOBOTAN DINAMIS
// (Simplified for hasil.php, only fetching what's needed for ranking)
// =========================================================

// STEP 1: Ambil Skor Kuesioner Anak (Sebagai Bobot W)
$stmt_input = $conn->prepare("SELECT p.id_kriteria, AVG(da.nilai_input) as rata_nilai
                              FROM detail_asesmen da
                              JOIN pertanyaan p ON da.id_pertanyaan = p.id_pertanyaan
                              WHERE da.id_asesmen = ?
                              GROUP BY p.id_kriteria");
$stmt_input->bind_param("i", $id_asesmen);
$stmt_input->execute();
$res_input = $stmt_input->get_result();

$skor_anak = [];
$total_skor_anak = 0;
while($row = $res_input->fetch_assoc()) {
    $skor_anak[$row['id_kriteria']] = $row['rata_nilai'];
    $total_skor_anak += $row['rata_nilai'];
}
$stmt_input->close();

// Cegah error pembagian nol
if ($total_skor_anak <= 0) $total_skor_anak = 1;


// STEP 2: Cari Nilai Max per Kriteria (Untuk Menghilangkan Bias Skala Kelas)
$sql_max = "SELECT id_kriteria, MAX(nilai_default) as max_val FROM nilai_kriteria_kelas GROUP BY id_kriteria";
$res_max = $conn->query($sql_max);
$max_kriteria = [];
while($row = $res_max->fetch_assoc()) {
    $max_kriteria[$row['id_kriteria']] = $row['max_val'];
}


// STEP 3: Hitung Vektor S (Perkalian Pangkat)
$sql_kelas = "SELECT * FROM kelas_bimbel WHERE deleted_at IS NULL";
$res_kelas = $conn->query($sql_kelas);

$vektor_s_aktif = [];
$vektor_s_coming = [];
$total_s_aktif = 0;
$total_s_coming = 0;

$stmt_m = $conn->prepare("SELECT * FROM nilai_kriteria_kelas WHERE id_kelas = ?");

while($kelas = $res_kelas->fetch_assoc()) {
    $id_kelas = $kelas['id_kelas'];

    $stmt_m->bind_param("i", $id_kelas);
    $stmt_m->execute();
    $res_m = $stmt_m->get_result();

    $S = 1; // Inisialisasi awal Vektor S

    while($matriks = $res_m->fetch_assoc()) {
        $id_kr = $matriks['id_kriteria'];
        $nilai_default = $matriks['nilai_default'];

        // NORMALISASI X_ij: Menyetarakan semua kelas agar adil
        $max_val = isset($max_kriteria[$id_kr]) ? $max_kriteria[$id_kr] : 5;
        $x_ij = $nilai_default / $max_val;

        // BOBOT W_j: Diambil dari proporsi skor anak
        $skor = isset($skor_anak[$id_kr]) ? $skor_anak[$id_kr] : 1;
        $w_j = $skor / $total_skor_anak;

        // RUMUS INTI WP
        $S *= pow($x_ij, $w_j);
    }

    // Pisahkan penampung berdasarkan status operasional kelas
    if($kelas['status_kelas'] == 'Aktif') {
        $vektor_s_aktif[$id_kelas] = ['nama' => $kelas['nama_kelas'], 'nilai_s' => $S];
        $total_s_aktif += $S;
    } else {
        $vektor_s_coming[$id_kelas] = ['nama' => $kelas['nama_kelas'], 'nilai_s' => $S];
        $total_s_coming += $S;
    }
}
$stmt_m->close();


// =========================================================
// STEP 4: LOGIKA RANKING (BERSIH DARI ID BIAS)
// =========================================================

$ranking_aktif = [];
$ranking_coming = [];

// Gunakan nilai Vektor V murni. Jika hasil sama, biarkan sama (itu berarti memang sama cocoknya)
foreach ($vektor_s_aktif as $id_cl => $data) {
    $ranking_aktif[$id_cl] = [
        'nama' => $data['nama'],
        'v' => ($total_s_aktif > 0) ? ($data['nilai_s'] / $total_s_aktif) : 0
    ];
}

foreach ($vektor_s_coming as $id_cl => $data) {
    $ranking_coming[$id_cl] = [
        'nama' => $data['nama'],
        'v' => ($total_s_coming > 0) ? ($data['nilai_s'] / $total_s_coming) : 0
    ];
}

// Urutkan murni berdasarkan nilai (v)
uasort($ranking_aktif, function($a, $b) { return $b['v'] <=> $a['v']; });
uasort($ranking_coming, function($a, $b) { return $b['v'] <=> $a['v']; });

// DATA UNTUK RADAR CHART (Visualisasi Bakat)
$res_kriteria = $conn->query("SELECT id_kriteria, nama_kriteria FROM kriteria ORDER BY id_kriteria ASC");
$labels_chart = [];
$values_chart = [];
$highest_val = 0;
$highest_name = "";

while($kr = $res_kriteria->fetch_assoc()) {
    // Logic to split long labels for the chart to prevent them from being cut off
    $label = $kr['nama_kriteria'];
    if (str_contains($label, '&')) {
        $parts = explode('&', $label);
        $labels_chart[] = array_map('trim', $parts);
    } else {
        $labels_chart[] = $label;
    }
    $skor = isset($skor_anak[$kr['id_kriteria']]) ? round($skor_anak[$kr['id_kriteria']], 2) : 0;
    $values_chart[] = $skor;

    if ($skor > $highest_val) {
        $highest_val = $skor;
        $highest_name = $kr['nama_kriteria'];
    }
}

$page_title = "Hasil Rekomendasi - SIMBIM";
include 'includes/header.php';
?>

<div class="container py-4">
    <div class="pb-card p-4 p-md-5 mb-5 shadow-sm">
        <div class="text-center">
            <div class="bg-primary text-white d-inline-block p-3 rounded-circle mb-3 shadow">
                <i class="bi bi-award fs-2"></i>
            </div>
            <h2 class="fw-bold text-dark">Hasil Rekomendasi Kelas Bimbel</h2>
            <p class="text-muted lead">Berikut adalah rekomendasi kelas yang paling sesuai dengan potensi Ananda.</p>
            <hr class="my-4">
            <div class="alert alert-info border-0 shadow-sm p-3 mb-0">
                📌 <strong>Nama Ananda:</strong> <?= htmlspecialchars($info_anak['nama_anak']); ?><br>
                🎂 <strong>Usia Anak:</strong> <?= $info_anak['usia']; ?> Tahun<br>
                📅 <strong>Tanggal Tes:</strong> <?= date('d F Y', strtotime($info_anak['tgl_asesmen'])); ?>
            </div>
        </div>
    </div>

    <!-- Visualisasi Radar Chart -->
    <div class="pb-card p-4 mb-5 border-0 shadow-sm rounded-4 text-center">
        <h4 class="fw-bold mb-4 text-primary"><i class="bi bi-graph-up-arrow me-2"></i>Pemetaan Potensi Kecerdasan Ananda</h4>
        <div class="mx-auto" style="max-width: 550px; height: 400px;">
            <canvas id="radarChart"></canvas>
        </div>
        
        <!-- Penjelasan Deskriptif Otomatis -->
        <div class="mt-4 p-3 bg-light rounded-3 border text-start mx-auto" style="max-width: 600px;">
            <h5 class="fw-bold text-dark mb-2"><i class="bi bi-lightbulb-fill text-warning me-2"></i>Kesimpulan Potensi</h5>
            <p class="mb-0 small text-muted">Berdasarkan hasil asesmen, Ananda memiliki kecenderungan potensi paling menonjol pada bidang <strong><?= $highest_name; ?></strong>. Grafik ini membantu Anda melihat area yang perlu dikembangkan lebih lanjut.</p>
        </div>
    </div>

    <h3 class="h5 fw-bold mt-4 mb-3 text-dark"><i class="bi bi-star-fill me-2 text-primary"></i>Pilihan Program Kelas Utama (Siap Daftar)</h3>
    <div class="table-responsive shadow-sm rounded-3 overflow-hidden mb-5">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-primary">
                <tr>
                    <th style="width: 100px;">Peringkat</th>
                    <th>Nama Program Bimbel</th>
                    <th>Nilai Preferensi (Vektor V)</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $no = 1;
                if (!empty($ranking_aktif)) {
                    foreach($ranking_aktif as $ra) {
                        $is_top = ($no == 1); ?>
                        <tr class="<?= $is_top ? 'table-success fw-bold' : ''; ?>">
                            <td><span class="badge <?= $is_top ? 'bg-success' : 'bg-secondary'; ?>">#<?= $no++; ?></span></td>
                            <td><?= htmlspecialchars($ra['nama']); ?> <?= ($is_top) ? "🌟 (Paling Direkomendasikan)" : ""; ?></td>
                            <td><?= round($ra['v'], 4); ?> (<?= round($ra['v'] * 100, 2); ?>%)</td>
                        </tr>
                    <?php }
                } else { ?>
                    <tr>
                        <td colspan="3" class="text-center py-4 text-muted">Tidak ada kelas aktif yang direkomendasikan.</td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>

    <div class="pb-card p-4 mb-5 bg-warning-subtle border-warning border-start border-5 shadow-sm">
        <h3 class="h5 fw-bold text-warning-emphasis mb-3"><i class="bi bi-hourglass-split me-2"></i>Potensi Kelas Ekstensi (Coming Soon)</h3>
        <p class="text-muted small mb-3">Berdasarkan kecenderungan minat yang diisi, berikut adalah urutan kecocokan Ananda untuk program yang akan segera diluncurkan:</p>

        <div class="row row-cols-1 row-cols-md-3 g-3">
            <?php
            $no_soon = 1;
            if (!empty($ranking_coming)) {
                foreach($ranking_coming as $rc) { ?>
                    <div class="col">
                        <div class="card h-100 border-0 shadow-sm">
                            <div class="card-body py-3">
                                <span class="fw-bold text-primary">#<?= $no_soon++; ?></span> <?= htmlspecialchars($rc['nama']); ?>
                                <br><small class="text-muted">Preferensi: <?= round($rc['v'] * 100, 2); ?>%</small>
                            </div>
                        </div>
                    </div>
                <?php }
            } else { ?>
                <div class="col-12">
                    <p class="text-muted text-center mb-0">Tidak ada kelas 'Coming Soon' yang direkomendasikan.</p>
                </div>
            <?php } ?>
        </div>
        <p class="small text-muted mt-4 mb-0">*Hubungi Admin untuk masuk daftar tunggu prioritas (Waiting List).</p>
    </div>

    <!-- Form & Tombol Aksi -->
    <form id="formCetak" action="cetak_pdf.php" method="POST" target="_blank">
        <input type="hidden" name="id_asesmen" value="<?= $id_asesmen; ?>">
        <input type="hidden" name="chart_image" id="chart_image">

        <div class="my-5 d-flex flex-wrap gap-3 justify-content-center justify-content-md-start">
            <a href="dashboard.php" class="btn btn-outline-secondary px-4 shadow-sm py-2 rounded-pill">
                <i class="bi bi-house me-2"></i>Beranda
            </a>
            <button type="button" onclick="handleCetak()" class="btn btn-danger px-4 shadow-sm py-2 rounded-pill">
                <i class="bi bi-file-earmark-pdf me-2"></i>Cetak PDF
            </button>
            <a href="export_excel.php?id_asesmen=<?= $id_asesmen ?>" class="btn btn-success px-4 shadow-sm py-2 rounded-pill">
                <i class="bi bi-file-earmark-excel me-2"></i>Simpan Excel
            </a>
        </div>
    </form>
</div>

<footer class="py-4 text-center text-muted border-top bg-light mt-4">
    &copy; <?= date('Y'); ?> <?= htmlspecialchars($settings['footer_text'] ?? 'SIMBIM Indonesia. All Rights Reserved.') ?>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    let myRadarChart = null;
    const ctx = document.getElementById('radarChart');
    
    myRadarChart = new Chart(ctx, {
        type: 'radar',
        data: {
            labels: <?= json_encode($labels_chart); ?>,
            datasets: [{
                label: 'Skor Potensi Ananda',
                data: <?= json_encode($values_chart); ?>,
                fill: true,
                backgroundColor: 'rgba(74, 144, 226, 0.3)',
                borderColor: 'rgba(74, 144, 226, 1)',
                pointBackgroundColor: 'rgb(74, 144, 226)',
                pointBorderColor: '#fff',
                pointRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            layout: {
                padding: 30 // Menambahkan ruang di sekitar chart agar label tidak terpotong
            },
            scales: {
                r: {
                    min: 0,
                    max: 5,
                    ticks: { stepSize: 1 },
                    pointLabels: {
                        font: { size: 10, weight: '600' }
                    }
                }
            },
            plugins: {
                legend: { display: false }
            }
        }
    });

    function handleCetak() {
        if (myRadarChart) {
            try {
                const canvas = document.getElementById('radarChart');
                const imageBase64 = canvas.toDataURL('image/png', 1.0);
                
                if (imageBase64 === "data:," || !imageBase64) {
                    alert("Grafik belum siap.");
                    return;
                }

                document.getElementById('chart_image').value = imageBase64;
                document.getElementById('formCetak').submit();
            } catch (error) {
                console.error("Cetak Error:", error);
                alert("Gagal memproses grafik.");
            }
        }
    }
</script>

</body>
</html>