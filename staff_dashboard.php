<?php
require_once 'config/koneksi.php';
session_start();

// Guard: hanya level 'staf' yang boleh akses halaman ini
if (!isset($_SESSION['id_user']) || $_SESSION['level'] != 'staf') {
    header("Location: login.php");
    exit;
}

$id_staf = $_SESSION['id_user'];
$is_demo_account = ($_SESSION['username'] == 'staf_demo');

// ==========================================
// LOGIKA FILTER & PENCARIAN
// ==========================================
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$usia_min = isset($_GET['usia_min']) && is_numeric($_GET['usia_min']) ? intval($_GET['usia_min']) : '';
$usia_max = isset($_GET['usia_max']) && is_numeric($_GET['usia_max']) ? intval($_GET['usia_max']) : '';
$id_kelas_filter = isset($_GET['id_kelas']) ? intval($_GET['id_kelas']) : 0;
$search_user = isset($_GET['search_user']) ? trim($_GET['search_user']) : '';

// ==========================================
// QUERY DATA LAPORAN ASESMEN (DENGAN FILTER)
// ==========================================
$sql_rekap = "SELECT asm.id_asesmen, a.nama_anak, a.usia, asm.tgl_asesmen, u.nama_lengkap as wali, u.no_hp, asm.status_follow_up, asm.catatan_follow_up
              FROM asesmen asm
              JOIN anak a ON asm.id_anak = a.id_anak
              JOIN user u ON a.id_user = u.id_user";
$params = [];
$types = "";

if ($id_kelas_filter > 0) {
    $sql_rekap .= " JOIN rekomendasi_hasil rh ON asm.id_asesmen = rh.id_asesmen";
}

$sql_rekap .= " WHERE asm.tgl_asesmen BETWEEN ? AND ?";
$params[] = $start_date;
$params[] = $end_date;
$types .= "ss";

if ($usia_min !== '' && $usia_max !== '') {
    $sql_rekap .= " AND a.usia BETWEEN ? AND ?";
    $params[] = $usia_min;
    $params[] = $usia_max;
    $types .= "ii";
}

if ($id_kelas_filter > 0) {
    $sql_rekap .= " AND rh.id_kelas = ?";
    $params[] = $id_kelas_filter;
    $types .= "i";
}

$sql_rekap .= " ORDER BY asm.tgl_asesmen DESC";
$stmt_rekap = $conn->prepare($sql_rekap);
if (!empty($params)) {
    $stmt_rekap->bind_param($types, ...$params);
}
$stmt_rekap->execute();
$result_rekap = $stmt_rekap->get_result();

// Statistik ringkasan
$total_asesmen  = $conn->query("SELECT COUNT(*) as t FROM asesmen")->fetch_assoc()['t'];
$total_users    = $conn->query("SELECT COUNT(*) as t FROM user WHERE level = 'orang_tua'")->fetch_assoc()['t'];
$total_kelas    = $conn->query("SELECT COUNT(*) as t FROM kelas_bimbel WHERE deleted_at IS NULL")->fetch_assoc()['t'];
$this_month     = $conn->query("SELECT COUNT(*) as t FROM asesmen WHERE MONTH(tgl_asesmen) = MONTH(NOW()) AND YEAR(tgl_asesmen) = YEAR(NOW())")->fetch_assoc()['t'];

// Data untuk filter dan tabel user
$list_kelas_filter = $conn->query("SELECT id_kelas, nama_kelas FROM kelas_bimbel WHERE deleted_at IS NULL AND status_kelas = 'Aktif' ORDER BY nama_kelas ASC");

$sql_users = "SELECT id_user, username, nama_lengkap FROM user WHERE level = 'orang_tua'";
if (!empty($search_user)) {
    $search_term = "%" . $search_user . "%";
    $sql_users .= " AND (username LIKE ? OR nama_lengkap LIKE ?)";
}
$sql_users .= " ORDER BY nama_lengkap ASC";
$stmt_users = $conn->prepare($sql_users);
if (!empty($search_user)) {
    $stmt_users->bind_param("ss", $search_term, $search_term);
}
$stmt_users->execute();
$result_users = $stmt_users->get_result();

// ==========================================
// QUERY UNTUK FITUR BARU
// ==========================================
// 1. Query Deteksi Akun Duplikat
$sql_duplicates = "SELECT nama_lengkap, COUNT(*) as jumlah
                   FROM user
                   WHERE level = 'orang_tua'
                   GROUP BY nama_lengkap
                   HAVING COUNT(*) > 1
                   ORDER BY jumlah DESC, nama_lengkap ASC";
$result_duplicates = $conn->query($sql_duplicates);

// 2. Query Monitor Login Terakhir
$sql_logins = "SELECT 
                    l.waktu, 
                    u.nama_lengkap, 
                    u.username,
                    u.no_hp
                FROM logs l
                JOIN user u ON l.id_user = u.id_user
                WHERE u.level = 'orang_tua' AND l.aktivitas LIKE '%login%'
                AND l.id_log IN (
                    SELECT MAX(id_log) 
                    FROM logs 
                    WHERE aktivitas LIKE '%login%' GROUP BY id_user
                ) ORDER BY l.waktu DESC LIMIT 20";
$result_logins = $conn->query($sql_logins);

// Ambil Pengaturan (sudah di-load di header.php tapi redundansi aman)
$settings = [];
$res_set = $conn->query("SELECT * FROM pengaturan");
while($s = $res_set->fetch_assoc()) $settings[$s['nama_key']] = $s['nilai_value'];

$page_title = "Staff Panel - SIMBIM";
include 'includes/header.php';
?>

<div class="container py-5">

    <!-- Header -->
    <div class="row mb-4 align-items-center">
        <div class="col-lg-8">
            <h1 class="fw-bold text-dark">Dashboard Staf Administrasi</h1>
            <p class="text-muted lead">Selamat datang! Di sini Anda dapat mengelola laporan, memantau status tindak lanjut, dan mengelola data pengguna.</p>
        </div>
        <div class="col-lg-4 text-lg-end">
            <div class="pb-card p-3 text-start">
                <i class="bi bi-person-badge me-2"></i>Staf:
                <strong><?= htmlspecialchars($_SESSION['nama_lengkap']) ?></strong>
            </div>
        </div>
    </div>

    <?php 
    $pesan = $_GET['pesan'] ?? '';
    if ($pesan == 'reset_sukses'): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i>Data demo berhasil direset ke kondisi awal!
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php elseif ($pesan == 'reset_pass_sukses'): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-key-fill me-2"></i>Password pengguna berhasil direset ke '123456'.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php elseif ($pesan == 'hapus_duplikat_sukses'): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i>Akun duplikat berhasil dihapus.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php elseif ($pesan == 'hapus_duplikat_gagal'): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-x-circle-fill me-2"></i>Gagal menghapus akun duplikat.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <!-- Statistik Kartu -->
    <div class="row g-4 mb-5">
        <div class="col-md-3">
            <div class="pb-card p-3 border-start border-primary border-4 h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-bold text-uppercase">Total Asesmen</div>
                        <div class="fs-4 fw-bold text-dark"><?= $total_asesmen ?></div>
                    </div>
                    <i class="bi bi-journal-check fs-2 text-primary opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="pb-card p-3 border-start border-success border-4 h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-bold text-uppercase">Asesmen Bulan Ini</div>
                        <div class="fs-4 fw-bold text-dark"><?= $this_month ?></div>
                    </div>
                    <i class="bi bi-calendar-check fs-2 text-success opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="pb-card p-3 border-start border-warning border-4 h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-bold text-uppercase">Orang Tua</div>
                        <div class="fs-4 fw-bold text-dark"><?= $total_users ?></div>
                    </div>
                    <i class="bi bi-people-fill fs-2 text-warning opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="pb-card p-3 border-start border-info border-4 h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-bold text-uppercase">Kelas Aktif</div>
                        <div class="fs-4 fw-bold text-dark"><?= $total_kelas ?></div>
                    </div>
                    <i class="bi bi-building fs-2 text-info opacity-50"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Nav Tabs -->
    <ul class="nav nav-pills nav-fill mb-4" id="staffTab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="laporan-tab" data-bs-toggle="tab" data-bs-target="#laporan-tab-pane" type="button" role="tab"><i class="bi bi-file-earmark-text me-2"></i>Laporan Asesmen</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="user-tab" data-bs-toggle="tab" data-bs-target="#user-tab-pane" type="button" role="tab"><i class="bi bi-people me-2"></i>Manajemen Pengguna</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="aktivitas-tab" data-bs-toggle="tab" data-bs-target="#aktivitas-tab-pane" type="button" role="tab"><i class="bi bi-clock-history me-2"></i>Aktivitas Login</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="utilitas-tab" data-bs-toggle="tab" data-bs-target="#utilitas-tab-pane" type="button" role="tab"><i class="bi bi-tools me-2"></i>Utilitas Data</button>
        </li>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content" id="staffTabContent">
        <!-- 1. Laporan Asesmen Tab -->
        <div class="tab-pane fade show active" id="laporan-tab-pane" role="tabpanel">
            <div class="pb-card p-4 shadow-sm">
                <h4 class="fw-bold mb-4"><i class="bi bi-funnel-fill me-2 text-primary"></i>Filter Laporan Lanjutan</h4>
                <form action="" method="GET" class="row g-3 mb-4">
                    <div class="col-md-4"><label class="form-label fw-semibold">Mulai Tanggal</label><input type="date" name="start_date" class="form-control" value="<?= $start_date ?>"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Sampai Tanggal</label><input type="date" name="end_date" class="form-control" value="<?= $end_date ?>"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Rekomendasi Kelas</label>
                        <select name="id_kelas" class="form-select">
                            <option value="0">Semua Kelas</option>
                            <?php while($k = $list_kelas_filter->fetch_assoc()) {
                                $selected = ($id_kelas_filter == $k['id_kelas']) ? 'selected' : '';
                                echo "<option value='{$k['id_kelas']}' $selected>".htmlspecialchars($k['nama_kelas'])."</option>";
                            } ?>
                        </select>
                    </div>
                    <div class="col-md-2"><label class="form-label fw-semibold">Usia Min.</label><input type="number" name="usia_min" class="form-control" placeholder="cth: 5" value="<?= $usia_min ?>"></div>
                    <div class="col-md-2"><label class="form-label fw-semibold">Usia Max.</label><input type="number" name="usia_max" class="form-control" placeholder="cth: 10" value="<?= $usia_max ?>"></div>
                    <div class="col-md-8 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-2"></i>Filter Data</button>
                        <a href="export_excel.php?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&usia_min=<?= $usia_min ?>&usia_max=<?= $usia_max ?>&id_kelas=<?= $id_kelas_filter ?>" class="btn btn-success w-100"><i class="bi bi-file-earmark-excel me-2"></i>Unduh Excel</a>
                        <button type="button" onclick="window.print()" class="btn btn-outline-secondary w-100"><i class="bi bi-printer me-2"></i>Cetak</button>
                    </div>
                </form>

                <div class="table-responsive printable-area">
                    <h5 class="d-none d-print-block text-center mb-4">REKAP ASESMEN SIMBIM<br><small><?= $start_date ?> s/d <?= $end_date ?></small></h5>
                    <table class="table table-striped table-hover align-middle">
                        <thead class="table-dark">
                            <tr><th>Tanggal</th><th>Nama Anak</th><th>Usia</th><th>Nama Wali</th><th>Status Follow-up</th><th class="d-print-none text-center">Aksi</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($result_rekap->num_rows > 0):
                                while($r = $result_rekap->fetch_assoc()): 
                                    $status_class = '';
                                    switch ($r['status_follow_up']) {
                                        case 'Mendaftar': $status_class = 'bg-success-subtle text-success'; break;
                                        case 'Sudah Dihubungi': $status_class = 'bg-info-subtle text-info'; break;
                                        default: $status_class = 'bg-secondary-subtle text-secondary'; break;
                                    }
                                ?>
                                <tr>
                                    <td><?= date('d/m/Y', strtotime($r['tgl_asesmen'])) ?></td>
                                    <td class="fw-bold"><?= htmlspecialchars($r['nama_anak']) ?></td>
                                    <td><span class="badge bg-secondary-subtle text-secondary"><?= $r['usia'] ?> Thn</span></td>
                                    <td><?= htmlspecialchars($r['wali']) ?></td>
                                    <td><span class="badge <?= $status_class ?>"><?= htmlspecialchars($r['status_follow_up']) ?></span></td>
                                    <td class="text-center d-print-none">
                                        <a href="hitung_wp.php?id_asesmen=<?= $r['id_asesmen'] ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                                            <i class="bi bi-file-earmark-pdf me-1"></i>PDF
                                        </a>
                                        <?php 
                                        if (!empty($r['no_hp'])): 
                                            $wa_number = preg_replace('/^0/', '62', $r['no_hp']);
                                        ?>
                                        <a href="https://wa.me/<?= $wa_number ?>" target="_blank" class="btn btn-sm btn-outline-success rounded-pill px-3">
                                            <i class="bi bi-whatsapp me-1"></i>WA
                                        </a>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-3" 
                                                data-bs-toggle="modal" data-bs-target="#followUpModal"
                                                data-id="<?= $r['id_asesmen'] ?>"
                                                data-nama="<?= htmlspecialchars($r['nama_anak']) ?>"
                                                data-status="<?= htmlspecialchars($r['status_follow_up'] ?? '') ?>"
                                                data-catatan="<?= htmlspecialchars($r['catatan_follow_up'] ?? '') ?>">
                                            <i class="bi bi-pencil-square me-1"></i>Update
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; else: ?>
                                <tr><td colspan="6" class="text-center py-5 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2 opacity-25"></i>Tidak ada data asesmen sesuai filter.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($result_rekap->num_rows > 0): ?>
                <div class="mt-3 text-end"><span class="badge bg-primary-subtle text-primary fs-6 px-3 py-2"><i class="bi bi-hash me-1"></i>Total <?= $result_rekap->num_rows ?> data ditemukan</span></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 2. Manajemen Pengguna Tab -->
        <div class="tab-pane fade" id="user-tab-pane" role="tabpanel">
            <div class="pb-card p-4 shadow-sm">
                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                    <h5 class="fw-bold mb-0"><i class="bi bi-people-fill me-2"></i>Daftar Pengguna (Orang Tua)</h5>
                    <form action="" method="GET" id="userSearchForm">
                        <div class="input-group" style="max-width: 300px;">
                            <input type="text" name="search_user" class="form-control" placeholder="Cari username atau nama..." value="<?= htmlspecialchars($search_user) ?>">
                            <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search"></i></button>
                        </div>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr><th>Nama Lengkap</th><th>Username</th><th class="text-center">Aksi</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($result_users->num_rows > 0):
                                while($u = $result_users->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($u['nama_lengkap']) ?></td>
                                    <td><?= htmlspecialchars($u['username']) ?></td>
                                    <td class="text-center">
                                        <form action="includes/proses_staff.php" method="POST" class="d-inline" onsubmit="return confirm('Yakin ingin mereset password untuk user <?= htmlspecialchars($u['username']) ?> menjadi `123456`?')">
                                            <input type="hidden" name="aksi" value="reset_password">
                                            <input type="hidden" name="id_user" value="<?= $u['id_user'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-warning rounded-pill px-3">
                                                <i class="bi bi-key-fill me-1"></i>Reset Pass
                                            </button>
                                        </form>
                                        <a href="cetak_kartu.php?id_user=<?= $u['id_user'] ?>" target="_blank" class="btn btn-sm btn-outline-success rounded-pill px-3">
                                            <i class="bi bi-printer-fill me-1"></i>Cetak Kartu
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; else: ?>
                                <tr><td colspan="3" class="text-center py-5 text-muted">Tidak ada data pengguna.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. Aktivitas Login Tab -->
    <div class="tab-pane fade" id="aktivitas-tab-pane" role="tabpanel">
        <div class="pb-card p-4 shadow-sm">
            <h4 class="fw-bold mb-2"><i class="bi bi-clock-history me-2 text-primary"></i>Monitor Login Terakhir Pengguna</h4>
            <p class="text-muted small mb-4">Daftar ini menampilkan 20 aktivitas login terakhir dari para orang tua, diurutkan dari yang paling baru. Ini membantu Anda mengetahui siapa yang aktif memantau perkembangan anak.</p>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Waktu Login</th>
                            <th>Nama Wali</th>
                            <th>Username</th>
                            <th class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_logins->num_rows > 0):
                            while($log = $result_logins->fetch_assoc()): ?>
                            <tr>
                                <td><?= date('d M Y, H:i', strtotime($log['waktu'])) ?></td>
                                <td class="fw-bold"><?= htmlspecialchars($log['nama_lengkap']) ?></td>
                                <td><?= htmlspecialchars($log['username']) ?></td>
                                <td class="text-center">
                                    <?php if (!empty($log['no_hp'])): 
                                        $wa_number = preg_replace('/^0/', '62', $log['no_hp']); ?>
                                        <a href="https://wa.me/<?= $wa_number ?>" target="_blank" class="btn btn-sm btn-outline-success rounded-pill px-3">
                                            <i class="bi bi-whatsapp me-1"></i> Hubungi
                                        </a>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary">No. HP tidak ada</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="4" class="text-center py-5 text-muted">Tidak ada aktivitas login yang tercatat.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 4. Utilitas Data Tab -->
    <div class="tab-pane fade" id="utilitas-tab-pane" role="tabpanel">
        <div class="pb-card p-4 shadow-sm">
            <h4 class="fw-bold mb-2"><i class="bi bi-person-bounding-box me-2 text-danger"></i>Deteksi Akun Duplikat</h4>
            <p class="text-muted small mb-4">Fitur ini mendeteksi akun orang tua yang terdaftar dengan nama lengkap yang sama lebih dari sekali. Anda dapat membersihkan data dengan menghapus akun yang tidak memiliki riwayat asesmen.</p>
            
            <?php if ($result_duplicates->num_rows > 0): ?>
                <div class="accordion" id="accordionDuplicates">
                    <?php 
                    $i = 0;
                    while($dup = $result_duplicates->fetch_assoc()): 
                        $nama_duplikat = $dup['nama_lengkap'];
                    ?>
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="heading-<?= $i ?>">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-<?= $i ?>">
                                <strong><?= htmlspecialchars($nama_duplikat) ?></strong>&nbsp;—&nbsp;<span class="badge bg-danger"><?= $dup['jumlah'] ?> Akun Terdeteksi</span>
                            </button>
                        </h2>
                        <div id="collapse-<?= $i ?>" class="accordion-collapse collapse" data-bs-parent="#accordionDuplicates">
                            <div class="accordion-body">
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered">
                                        <thead class="table-light">
                                            <tr><th>Username</th><th>Jml. Anak</th><th>Jml. Asesmen</th><th class="text-center">Aksi</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $stmt_details = $conn->prepare("SELECT u.id_user, u.username, (SELECT COUNT(*) FROM anak WHERE id_user = u.id_user) as jumlah_anak, (SELECT COUNT(*) FROM asesmen JOIN anak ON asesmen.id_anak = anak.id_anak WHERE anak.id_user = u.id_user) as jumlah_asesmen FROM user u WHERE u.nama_lengkap = ? AND u.level = 'orang_tua'");
                                            $stmt_details->bind_param("s", $nama_duplikat);
                                            $stmt_details->execute();
                                            $res_details = $stmt_details->get_result();
                                            while($detail = $res_details->fetch_assoc()):
                                            ?>
                                            <tr>
                                                <td><?= htmlspecialchars($detail['username']) ?></td>
                                                <td class="text-center"><?= $detail['jumlah_anak'] ?></td>
                                                <td class="text-center"><?= $detail['jumlah_asesmen'] ?></td>
                                                <td class="text-center">
                                                    <?php if ($detail['jumlah_asesmen'] == 0): ?>
                                                        <a href="includes/proses_staff.php?aksi=hapus_duplikat&id_user=<?= $detail['id_user'] ?>" class="btn btn-sm btn-danger rounded-pill px-3" onclick="return confirm('Yakin ingin menghapus akun <?= htmlspecialchars($detail['username']) ?> secara permanen? Aksi ini tidak dapat dibatalkan.')"><i class="bi bi-trash-fill me-1"></i> Hapus</a>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary-subtle text-secondary" title="Tidak dapat dihapus karena memiliki riwayat asesmen.">Terpakai</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endwhile; $stmt_details->close(); ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php $i++; endwhile; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-5 bg-light rounded-3"><i class="bi bi-check2-circle fs-1 text-success"></i><p class="mt-3 text-muted">Tidak ada akun duplikat yang terdeteksi. Database bersih!</p></div>
            <?php endif; ?>
        </div>
    </div>
</div>

    <!-- Info Hak Akses -->
    <div class="alert alert-info border-0 mt-4 d-flex align-items-center gap-3">
        <i class="bi bi-shield-lock-fill fs-4 text-info"></i>
        <div>
            <strong>Hak Akses Terbatas:</strong> Sebagai Staf, Anda hanya dapat melihat dan mengekspor laporan.
            Untuk mengubah data sistem (kelas, soal, kriteria, pengaturan), hubungi Admin.
        </div>
    </div>

</div>

<footer class="py-4 text-center text-muted border-top bg-light mt-4">
    &copy; <?= date('Y') ?> <?= htmlspecialchars($settings['site_name'] ?? 'SIMBIM Indonesia') ?>. Staff View.
</footer>

<!-- Modal Follow Up -->
<div class="modal fade" id="followUpModal" tabindex="-1" aria-labelledby="followUpModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="followUpModalLabel">Update Status Follow-up</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="formFollowUp">
        <div class="modal-body">
            <input type="hidden" name="id_asesmen" id="fu_id_asesmen">
            <input type="hidden" name="aksi" value="update_follow_up">
            <p>Anak: <strong id="fu_nama_anak"></strong></p>
            <div class="mb-3">
                <label for="fu_status" class="form-label">Status</label>
                <select name="status" id="fu_status" class="form-select">
                    <option value="Belum Dihubungi">Belum Dihubungi</option>
                    <option value="Sudah Dihubungi">Sudah Dihubungi (Pikir-pikir)</option>
                    <option value="Mendaftar">Resmi Mendaftar Bimbel</option>
                </select>
            </div>
            <div class="mb-3">
                <label for="fu_catatan" class="form-label">Catatan (Opsional)</label>
                <textarea name="catatan" id="fu_catatan" class="form-control" rows="3"></textarea>
            </div>
            <div id="fu_alert" class="alert d-none"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
          <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const followUpModal = document.getElementById('followUpModal');
    followUpModal.addEventListener('show.bs.modal', event => {
        const button = event.relatedTarget;
        document.getElementById('fu_id_asesmen').value = button.dataset.id;
        document.getElementById('fu_nama_anak').textContent = button.dataset.nama;
        document.getElementById('fu_status').value = button.dataset.status;
        document.getElementById('fu_catatan').value = button.dataset.catatan;
    });

    document.getElementById('formFollowUp').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        fetch('includes/proses_staff.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                const alertBox = document.getElementById('fu_alert');
                alertBox.textContent = data.message;
                alertBox.className = 'alert ' + (data.status === 'success' ? 'alert-success' : 'alert-danger');
                alertBox.classList.remove('d-none');
                if (data.status === 'success') {
                    setTimeout(() => window.location.reload(), 1000);
                }
            });
    });
});
</script>
<style>
@media print {
    .d-print-none, .navbar, footer, .alert { display: none !important; }
    .container { width: 100% !important; max-width: 100% !important; }
    .pb-card { box-shadow: none !important; }
    .printable-area { display: block !important; }
}
</style>
</body>
</html>
