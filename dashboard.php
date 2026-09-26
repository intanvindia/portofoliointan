<?php
require_once 'config/koneksi.php';
session_start();

// 1. Validasi Guard: Hanya User dengan Role Orang Tua yang boleh masuk
if (!isset($_SESSION['id_user']) || $_SESSION['level'] != 'orang_tua') {
    header("Location: login.php");
    exit;
}

$id_user = $_SESSION['id_user'];
$filter_id_anak = isset($_GET['id_anak']) ? intval($_GET['id_anak']) : 0;

// Ambil daftar anak untuk dropdown filter
$stmt_daftar = $conn->prepare("SELECT id_anak, nama_anak FROM anak WHERE id_user = ?");
$stmt_daftar->bind_param("i", $id_user);
$stmt_daftar->execute();
$res_daftar_anak = $stmt_daftar->get_result();

// ==========================================
// AMBIL DATA RIWAYAT ASESMEN ANAK
// ==========================================
// Query ini mengambil data unik setiap anak beserta asesmen terakhir mereka.
// Menggunakan subquery dengan MAX() untuk menemukan id_asesmen dan tgl_asesmen terbaru untuk setiap anak.
$sql_riwayat = "SELECT 
                    a.id_anak, a.nama_anak, a.usia, 
                    MAX(asm.id_asesmen) as id_asesmen, 
                    MAX(asm.tgl_asesmen) as tgl_asesmen
                FROM 
                    anak a
                LEFT JOIN 
                    asesmen asm ON a.id_anak = asm.id_anak
                WHERE 
                    a.id_user = ?";

if ($filter_id_anak > 0) {
    $sql_riwayat .= " AND a.id_anak = ?";
    $stmt_riwayat = $conn->prepare($sql_riwayat . " GROUP BY a.id_anak, a.nama_anak, a.usia ORDER BY a.nama_anak ASC");
    $stmt_riwayat->bind_param("ii", $id_user, $filter_id_anak);
} else {
    $stmt_riwayat = $conn->prepare($sql_riwayat . " GROUP BY a.id_anak, a.nama_anak, a.usia ORDER BY a.nama_anak ASC");
    $stmt_riwayat->bind_param("i", $id_user);
}
$stmt_riwayat->execute();
$result_riwayat = $stmt_riwayat->get_result();

// Hitung total asesmen yang pernah dilakukan oleh akun ini
if ($filter_id_anak > 0) {
    $stmt_count = $conn->prepare("SELECT COUNT(asm.id_asesmen) as total FROM asesmen asm 
                                  JOIN anak a ON asm.id_anak = a.id_anak 
                                  WHERE a.id_user = ? AND a.id_anak = ?");
    $stmt_count->bind_param("ii", $id_user, $filter_id_anak);
} else {
    $stmt_count = $conn->prepare("SELECT COUNT(asm.id_asesmen) as total FROM asesmen asm 
                                  JOIN anak a ON asm.id_anak = a.id_anak 
                                  WHERE a.id_user = ?");
    $stmt_count->bind_param("i", $id_user);
}
$stmt_count->execute();
$count_anak_asesmen = $stmt_count->get_result()->fetch_assoc()['total'];
$stmt_count->close();
 
// ==========================================
// DATA UNTUK LINE CHART PERKEMBANGAN
// ==========================================
$sql_perkembangan = "SELECT 
                        asm.tgl_asesmen, 
                        k.nama_kriteria, 
                        AVG(da.nilai_input) as rata_nilai
                     FROM asesmen asm
                     JOIN detail_asesmen da ON asm.id_asesmen = da.id_asesmen
                     JOIN pertanyaan p ON da.id_pertanyaan = p.id_pertanyaan
                     JOIN kriteria k ON p.id_kriteria = k.id_kriteria
                     JOIN anak an ON asm.id_anak = an.id_anak
                     WHERE an.id_user = ?";

if ($filter_id_anak > 0) {
    $sql_perkembangan .= " AND an.id_anak = ?";
    $stmt_perkembangan = $conn->prepare($sql_perkembangan . " GROUP BY asm.id_asesmen, k.id_kriteria ORDER BY asm.tgl_asesmen ASC, k.id_kriteria ASC");
    $stmt_perkembangan->bind_param("ii", $id_user, $filter_id_anak);
} else {
    $stmt_perkembangan = $conn->prepare($sql_perkembangan . " GROUP BY asm.id_asesmen, k.id_kriteria ORDER BY asm.tgl_asesmen ASC, k.id_kriteria ASC");
    $stmt_perkembangan->bind_param("i", $id_user);
}
$stmt_perkembangan->execute();
$res_perkembangan = $stmt_perkembangan->get_result();

$chart_data_perkembangan = [];
while ($row = $res_perkembangan->fetch_assoc()) {
    $chart_data_perkembangan[] = $row;
}
$stmt_perkembangan->close();


$page_title = "Dashboard Orang Tua - SIMBIM";
include 'includes/header.php';
?>

    <div class="container py-4">
        
        <!-- Announcement Banner -->
        <?php if (!empty($settings['global_announcement'] ?? '')): ?>
            <div class="alert alert-primary border-0 shadow-sm p-4 mb-4 rounded-4 position-relative overflow-hidden d-flex align-items-center" role="alert" style="background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%); border-left: 5px solid #0284c7 !important;">
                <div class="me-3 bg-white text-primary rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 45px; height: 45px; min-width: 45px;">
                    <i class="bi bi-megaphone-fill fs-5 text-primary"></i>
                </div>
                <div>
                    <h5 class="fw-bold text-dark mb-1">📢 Pengumuman Penting</h5>
                    <p class="text-secondary mb-0 small"><?= nl2br(htmlspecialchars($settings['global_announcement'])); ?></p>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Welcome Section -->
        <div class="pb-card p-4 p-md-5 mb-5 border-start border-primary border-5 shadow-sm overflow-hidden position-relative">
            <div class="position-absolute end-0 top-0 p-3 opacity-10 d-none d-md-block" style="z-index: 0;">
                <i class="bi bi-person-hearts" style="font-size: 10rem;"></i>
            </div>
            <div class="row align-items-center position-relative" style="z-index: 1;">
                <div class="col-lg-8">
                    <h1 class="fw-bold text-dark">Halo, Bunda/Ayah! 👋</h1>
                    <p class="text-muted lead">Pantau perkembangan potensi kecerdasan buah hati dan dapatkan rekomendasi kelas terbaik secara objektif.</p>
                    <div class="d-inline-flex align-items-center bg-primary-subtle text-primary p-2 px-3 rounded-pill fw-bold small">
                        <i class="bi bi-bar-chart-fill me-2"></i>Total <?= $count_anak_asesmen; ?> Asesmen Dilakukan
                    </div>
                </div>
                <div class="col-lg-4 text-lg-end mt-4 mt-lg-0">
                    <?php if (($settings['registration_open'] ?? '1') == '1'): ?>
                        <a href="asesmen.php" class="btn btn-primary btn-lg px-4 shadow-sm rounded-pill"><i class="bi bi-plus-circle me-2"></i>Mulai Asesmen Baru</a>
                        <button type="button" class="btn btn-outline-primary btn-lg px-4 shadow-sm rounded-pill mt-2" data-bs-toggle="modal" data-bs-target="#modalTambahAnak">
                            <i class="bi bi-person-plus me-2"></i>Tambah Data Anak
                        </button>
                    <?php else: ?>
                        <button class="btn btn-secondary btn-lg px-4 shadow-sm rounded-pill" disabled><i class="bi bi-lock-fill me-2"></i>Pendaftaran Ditutup</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Analytics Section -->
        <div class="pb-card p-4 mb-4">
            <div class="mb-4">
                <h3 class="fw-bold mb-1 text-dark"><i class="bi bi-graph-up me-2 text-primary"></i>Perkembangan Potensi Ananda</h3>
                <p class="text-muted small">Visualisasi skor rata-rata kriteria kecerdasan dari waktu ke waktu.</p>
            </div>
            <?php if ($filter_id_anak > 0): ?>
            <div style="height: 400px; position: relative;">
                <canvas id="perkembanganChart"></canvas>
            </div>
            <?php else: ?>
            <div class="text-center py-5 bg-light rounded-3">
                <i class="bi bi-bar-chart-line fs-1 text-muted opacity-50"></i>
                <p class="mt-3 text-muted">Pilih salah satu anak dari filter di atas untuk melihat grafik perkembangannya.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Progress Bar Skor Kriteria Terakhir -->
        <?php
        // Ambil asesmen terakhir dari filter anak yang dipilih (atau semua anak)
        $sql_last_asm = "SELECT asm.id_asesmen FROM asesmen asm JOIN anak a ON asm.id_anak = a.id_anak WHERE a.id_user = ? AND a.id_anak = ? ORDER BY asm.tgl_asesmen DESC, asm.id_asesmen DESC LIMIT 1";
        $stmt_last = $conn->prepare($sql_last_asm);
        $stmt_last->bind_param("ii", $id_user, $filter_id_anak);
        $stmt_last->execute();
        $last_asm = $stmt_last->get_result()->fetch_assoc();
        $stmt_last->close();

        if ($last_asm):
            $id_last_asm = $last_asm['id_asesmen'];
            $res_skor = $conn->prepare("SELECT k.nama_kriteria, AVG(da.nilai_input) as rata
                                        FROM detail_asesmen da
                                        JOIN pertanyaan p ON da.id_pertanyaan = p.id_pertanyaan
                                        JOIN kriteria k ON p.id_kriteria = k.id_kriteria
                                        WHERE da.id_asesmen = ?
                                        GROUP BY k.id_kriteria ORDER BY k.id_kriteria ASC");
            $res_skor->bind_param("i", $id_last_asm);
            $res_skor->execute();
            $skor_result = $res_skor->get_result();
            $res_skor->close();
            $bar_colors = ['primary','success','warning','info','danger','secondary'];
            $ci = 0;
        ?>
        <div class="pb-card p-4 mb-4">
            <h3 class="fw-bold mb-1 text-dark"><i class="bi bi-speedometer2 me-2 text-success"></i>Profil Kecerdasan (Asesmen Terakhir)</h3>
            <p class="text-muted small mb-4">Rata-rata skor per aspek kecerdasan dari hasil asesmen terakhir. Skala 1–5.</p>
            <div class="row g-3">
                <?php while($skor = $skor_result->fetch_assoc()): $pct = round(($skor['rata'] / 5) * 100); ?>
                <div class="col-md-6">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="fw-semibold small"><?= htmlspecialchars($skor['nama_kriteria']) ?></span>
                        <span class="badge bg-<?= $bar_colors[$ci % count($bar_colors)] ?>-subtle text-<?= $bar_colors[$ci % count($bar_colors)] ?> fw-bold"><?= number_format($skor['rata'], 2) ?> / 5</span>
                    </div>
                    <div class="progress" style="height: 12px; border-radius: 8px;">
                        <div class="progress-bar bg-<?= $bar_colors[$ci % count($bar_colors)] ?>"
                             role="progressbar"
                             style="width: <?= $pct ?>%; border-radius: 8px; transition: width 1s ease-in-out;"
                             aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
                        </div>
                    </div>
                </div>
                <?php $ci++; endwhile; ?>
            </div>
        </div>
        <?php elseif ($filter_id_anak == 0): ?>
        <div class="pb-card p-4 mb-4 text-center text-muted bg-light">
            <i class="bi bi-speedometer2 fs-1 opacity-50"></i>
            <p class="mt-3">Pilih salah satu anak untuk melihat profil kecerdasan dari asesmen terakhirnya.</p>
        </div>
        <?php endif; ?>

        <!-- History Table Section -->
        <div class="pb-card p-4 mb-5">
            <div class="row align-items-center mb-4">
                <div class="col-md-6">
                    <h3 class="fw-bold mb-0 text-dark"><i class="bi bi-clock-history me-2 text-primary"></i>Riwayat Tes Ananda</h3>
                </div>
                <div class="col-md-6 text-md-end mt-3 mt-md-0">
                    <form action="" method="GET" class="d-inline-block">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white border-primary text-primary"><i class="bi bi-funnel"></i></span>
                            <select name="id_anak" class="form-select border-primary" onchange="this.form.submit()">
                                <option value="0">Tampilkan Semua Anak</option>
                                <?php 
                                $res_daftar_anak->data_seek(0);
                                while($c = $res_daftar_anak->fetch_assoc()) {
                                    $selected = ($filter_id_anak == $c['id_anak']) ? 'selected' : '';
                                    echo "<option value='".$c['id_anak']."' $selected>".htmlspecialchars($c['nama_anak'])."</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </form>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle border-top">
                    <thead class="table-light">
                        <tr>
                            <th class="py-3">Nama Anak</th>
                            <th class="py-3 text-center">Usia</th>
                            <th class="py-3">Tanggal Tes Terakhir</th>
                            <th style="text-align: center; width: 150px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_riwayat->num_rows > 0) { ?>
                            <?php while($row = $result_riwayat->fetch_assoc()) { ?>
                                <tr>
                                    <td>
                                        <a href="profil_anak.php?id=<?= $row['id_anak'] ?>" class="text-decoration-none fw-bold text-dark"><?= htmlspecialchars($row['nama_anak']); ?></a>
                                    </td>
                                    <td class="text-center"><span class="badge bg-secondary-subtle text-secondary"><?= $row['usia']; ?> Tahun</span></td>
                                    <td>
                                        <?php if ($row['tgl_asesmen'] != null): ?>
                                            <div class="d-flex align-items-center">
                                                <i class="bi bi-calendar-event me-2 text-primary"></i>
                                                <?= date('d M Y', strtotime($row['tgl_asesmen'])); ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small italic">Belum melakukan tes</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php if ($row['id_asesmen'] != null) { ?>
                                            <a href="hitung_wp.php?id_asesmen=<?= $row['id_asesmen']; ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3 mb-1 mb-md-0">
                                                <i class="bi bi-file-earmark-text me-1"></i>Hasil
                                            </a>
                                        <?php } else { ?>
                                            <a href="asesmen.php" class="btn btn-sm btn-primary rounded-pill px-3 mb-1 mb-md-0">
                                                <i class="bi bi-pencil-square me-1"></i>Ikut Tes
                                            </a>
                                        <?php } ?>
                                        <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-3 mb-1 mb-md-0" 
                                                data-bs-toggle="modal" data-bs-target="#modalEditAnak"
                                                data-id="<?= $row['id_anak'] ?>"
                                                data-nama="<?= htmlspecialchars($row['nama_anak']) ?>"
                                                data-usia="<?= $row['usia'] ?>">
                                            <i class="bi bi-pencil-fill me-1"></i>Edit
                                        </button>
                                        <a href="includes/proses_anak.php?aksi=hapus&id=<?= $row['id_anak'] ?>" 
                                           class="btn btn-sm btn-outline-danger rounded-pill px-3" 
                                           onclick="return confirm('Apakah Anda yakin ingin menghapus data anak ini? Semua riwayat asesmennya juga akan terhapus.')">
                                            <i class="bi bi-trash-fill me-1"></i>Hapus
                                        </a>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr>
                                <td colspan="4" class="py-5 text-center text-muted">
                                    <div class="mb-3">
                                        <i class="bi bi-clipboard-x fs-1 opacity-25"></i>
                                    </div>
                                    <p class="mb-0">Belum ada data anak terdaftar.</p>
                                    <small>Klik tombol <strong>"Mulai Asesmen Baru"</strong> untuk mendaftarkan anak pertama kali.</small>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

<!-- Modal Tambah Anak -->
<div class="modal fade" id="modalTambahAnak" tabindex="-1" aria-labelledby="modalTambahAnakLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalTambahAnakLabel"><i class="bi bi-person-plus-fill me-2"></i>Tambah Data Anak</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form action="includes/proses_anak.php" method="POST">
        <div class="modal-body">
            <div class="mb-3">
                <label for="nama_anak_modal" class="form-label fw-bold">Nama Lengkap Anak</label>
                <input type="text" name="nama_anak" id="nama_anak_modal" class="form-control" placeholder="Contoh: Budi Sanjaya" required>
            </div>
            <div class="mb-3">
                <label for="usia_modal" class="form-label fw-bold">Usia Anak (Tahun)</label>
                <input type="number" name="usia" id="usia_modal" class="form-control" min="2" max="15" placeholder="Contoh: 6" required>
            </div>
            <input type="hidden" name="aksi" value="tambah">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Simpan Data Anak</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Edit Anak -->
<div class="modal fade" id="modalEditAnak" tabindex="-1" aria-labelledby="modalEditAnakLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalEditAnakLabel"><i class="bi bi-pencil-square me-2"></i>Edit Data Anak</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form action="includes/proses_anak.php" method="POST">
        <div class="modal-body">
            <input type="hidden" name="id_anak" id="edit_id_anak">
            <div class="mb-3">
                <label for="edit_nama_anak" class="form-label fw-bold">Nama Lengkap Anak</label>
                <input type="text" name="nama_anak" id="edit_nama_anak" class="form-control" required>
            </div>
            <div class="mb-3">
                <label for="edit_usia" class="form-label fw-bold">Usia Anak (Tahun)</label>
                <input type="number" name="usia" id="edit_usia" class="form-control" min="2" max="15" required>
            </div>
            <input type="hidden" name="aksi" value="edit">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
// Ambil pesan dari URL jika ada (setelah redirect dari proses_anak.php)
$pesan = isset($_GET['pesan']) ? $_GET['pesan'] : '';
if ($pesan == 'sukses_tambah') echo "<script>alert('Data anak berhasil ditambahkan!');</script>";
if ($pesan == 'sukses_edit') echo "<script>alert('Data anak berhasil diperbarui!');</script>";
if ($pesan == 'sukses_hapus') echo "<script>alert('Data anak berhasil dihapus!');</script>";
if ($pesan == 'gagal') echo "<script>alert('Operasi gagal!');</script>";
?>
</body>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        const ctxPerkembangan = document.getElementById('perkembanganChart').getContext('2d');
        
        // Script untuk Modal Edit Anak
        const modalEditAnak = document.getElementById('modalEditAnak');
        if (modalEditAnak) {
            modalEditAnak.addEventListener('show.bs.modal', event => {
                const button = event.relatedTarget;
                const id = button.getAttribute('data-id');
                const nama = button.getAttribute('data-nama');
                const usia = button.getAttribute('data-usia');

                document.getElementById('edit_id_anak').value = id;
                document.getElementById('edit_nama_anak').value = nama;
                document.getElementById('edit_usia').value = usia;
            });
        }

        // =================================================
        // LOGIKA UNTUK GRAFIK PERKEMBANGAN (LINE CHART)
        // =================================================
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
                spanGaps: true // Menyambungkan garis jika ada data null (kosong)
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
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 5,
                        title: {
                            display: true,
                            text: 'Skor Rata-rata'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Tanggal Asesmen'
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: true,
                        text: 'Perkembangan Skor Kriteria per Asesmen'
                    }
                }
            }
        });
    });
</script>
</html>