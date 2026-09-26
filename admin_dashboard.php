<?php
require_once 'config/koneksi.php';
session_start();

// 1. Validasi Guard: Hanya Admin yang boleh masuk halaman ini
if (!isset($_SESSION['id_user']) || $_SESSION['level'] != 'admin') {
    header("Location: login.php");
    exit;
}

$pesan = "";

// Fungsi helper untuk mencatat log aktivitas
function createLog($conn, $aktivitas) {
    $id_user = $_SESSION['id_user'];
    $stmt = $conn->prepare("INSERT INTO logs (id_user, aktivitas) VALUES (?, ?)");
    $stmt->bind_param("is", $id_user, $aktivitas);
    $stmt->execute();
    $stmt->close();
}

// ==========================================
// DATA ANALYTICS QUERIES (Untuk Chart.js)
// ==========================================
// 1. Query Pendaftar per Bulan
$monthly_query = $conn->query("SELECT DATE_FORMAT(tgl_asesmen, '%M %Y') as bulan, COUNT(*) as total FROM asesmen GROUP BY bulan ORDER BY MIN(tgl_asesmen) DESC LIMIT 6");
$chart_labels = []; $chart_data = [];
while($row = $monthly_query->fetch_assoc()){
    $chart_labels[] = $row['bulan'];
    $chart_data[] = $row['total'];
}

// 2. Query Distribusi Minat (Berdasarkan Rata-rata Skor Kriteria yang diinput Orang Tua)
$dist_query = $conn->query("SELECT k.nama_kriteria, AVG(da.nilai_input) as rata 
                            FROM detail_asesmen da 
                            JOIN pertanyaan p ON da.id_pertanyaan = p.id_pertanyaan 
                            JOIN kriteria k ON p.id_kriteria = k.id_kriteria 
                            GROUP BY k.id_kriteria");
$pie_labels = []; $pie_data = [];
while($row = $dist_query->fetch_assoc()){
    $pie_labels[] = $row['nama_kriteria'];
    $pie_data[] = round($row['rata'], 2);
}

// 3. Query Top Kelas Terpopuler (yang paling sering muncul sebagai rekomendasi terbaik)
// Query ini mengambil data dari tabel 'rekomendasi_hasil' yang menyimpan peringkat teratas untuk setiap asesmen.
$top_kelas_query = $conn->query("SELECT
                                      kb.nama_kelas,
                                      COUNT(rh.id_asesmen) AS total_rekomendasi
                                  FROM rekomendasi_hasil rh
                                  JOIN kelas_bimbel kb ON rh.id_kelas = kb.id_kelas
                                  GROUP BY rh.id_kelas, kb.nama_kelas
                                  ORDER BY total_rekomendasi DESC, kb.nama_kelas ASC
                                  LIMIT 5;");
$top_kelas_labels = []; $top_kelas_data = [];
if ($top_kelas_query) {
    while($row = $top_kelas_query->fetch_assoc()){
        $top_kelas_labels[] = $row['nama_kelas'];
        $top_kelas_data[] = $row['total_rekomendasi'];
    }
}

// 4. Statistik Perbandingan: Asesmen bulan ini vs bulan lalu
$this_month_count = $conn->query("SELECT COUNT(*) as total FROM asesmen WHERE MONTH(tgl_asesmen) = MONTH(NOW()) AND YEAR(tgl_asesmen) = YEAR(NOW())")->fetch_assoc()['total'];
$last_month_count = $conn->query("SELECT COUNT(*) as total FROM asesmen WHERE MONTH(tgl_asesmen) = MONTH(DATE_SUB(NOW(), INTERVAL 1 MONTH)) AND YEAR(tgl_asesmen) = YEAR(DATE_SUB(NOW(), INTERVAL 1 MONTH))")->fetch_assoc()['total'];
$user_count_this_month = $conn->query("SELECT COUNT(*) as total FROM user WHERE level = 'orang_tua'")->fetch_assoc()['total'];
$month_diff_pct = $last_month_count > 0 ? round((($this_month_count - $last_month_count) / $last_month_count) * 100, 1) : ($this_month_count > 0 ? 100 : 0);

// ==========================================
// LOGIKA FILTER LAPORAN GLOBAL
// ==========================================
$start_date = isset($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-01');
$end_date = isset($_POST['end_date']) ? $_POST['end_date'] : date('Y-m-d');

$sql_rekap = "SELECT asm.id_asesmen, a.nama_anak, a.usia, asm.tgl_asesmen, u.nama_lengkap as wali 
              FROM asesmen asm 
              JOIN anak a ON asm.id_anak = a.id_anak 
              JOIN user u ON a.id_user = u.id_user 
              WHERE asm.tgl_asesmen BETWEEN ? AND ?
              ORDER BY asm.tgl_asesmen DESC";
$stmt_rekap = $conn->prepare($sql_rekap);
$stmt_rekap->bind_param("ss", $start_date, $end_date);
$stmt_rekap->execute();
$result_rekap = $stmt_rekap->get_result();

// ==========================================
// LOGIKA PROSES CRUD (CREATE, UPDATE, DELETE)
// ==========================================

// 0. PROSES TAMBAH KELAS (CREATE)
if (isset($_POST['tambah_kelas'])) {
    $nama_kelas = $_POST['nama_kelas'];
    $status = $_POST['status_kelas'];

    if (!empty($nama_kelas)) {
        $stmt_ins_kelas = $conn->prepare("INSERT INTO kelas_bimbel (nama_kelas, status_kelas) VALUES (?, ?)");
        $stmt_ins_kelas->bind_param("ss", $nama_kelas, $status);
        if ($stmt_ins_kelas->execute()) {
            $id_kelas_baru = $stmt_ins_kelas->insert_id;
            $stmt_ins_kelas->close();
            
            // Inisialisasi nilai matriks default (skor 1) untuk semua kriteria yang ada
            $all_kriteria = $conn->query("SELECT id_kriteria FROM kriteria");
            $stmt_mat = $conn->prepare("INSERT INTO nilai_kriteria_kelas (id_kelas, id_kriteria, nilai_default) VALUES (?, ?, 1)");
            while ($kr = $all_kriteria->fetch_assoc()) {
                $id_k = $kr['id_kriteria'];
                $stmt_mat->bind_param("ii", $id_kelas_baru, $id_k);
                $stmt_mat->execute();
            }
            $stmt_mat->close();
            
            createLog($conn, "Menambahkan kelas baru: $nama_kelas");
            $pesan = "<div class='alert alert-success'>Kelas baru berhasil ditambahkan!</div>";
        } else {
            $pesan = "<div class='alert alert-danger'>Gagal menambahkan kelas: " . $stmt_ins_kelas->error . "</div>";
            $stmt_ins_kelas->close();
        }
    }
}

// 0.1 PROSES EDIT KELAS & MATRIKS (UPDATE)
if (isset($_POST['edit_kelas_submit'])) {
    $id_k = intval($_POST['id_kelas']);
    $nama = $_POST['nama_kelas'];
    $status = $_POST['status_kelas'];
    
    $stmt = $conn->prepare("UPDATE kelas_bimbel SET nama_kelas = ?, status_kelas = ? WHERE id_kelas = ?");
    $stmt->bind_param("ssi", $nama, $status, $id_k);
    $stmt->execute();
    $stmt->close();
    
    createLog($conn, "Memperbarui data kelas ID: $id_k");
    $pesan = "<div class='alert alert-success'>Data kelas diperbarui!</div>";
}

// A. PROSES TAMBAH PERTANYAAN (CREATE)
if (isset($_POST['tambah_pertanyaan'])) {
    $teks = $_POST['teks_pertanyaan'];
    $id_kriteria = intval($_POST['id_kriteria']);

    if (!empty($teks) && $id_kriteria > 0) {
        $stmt = $conn->prepare("INSERT INTO pertanyaan (teks_pertanyaan, id_kriteria) VALUES (?, ?)");
        $stmt->bind_param("si", $teks, $id_kriteria);
        if ($stmt->execute()) {
            $stmt->close();
            createLog($conn, "Menambahkan pertanyaan baru");
            $pesan = "<div class='alert alert-success'>Pertanyaan berhasil ditambahkan!</div>";
        } else {
            $pesan = "<div class='alert alert-danger'>Gagal menambahkan: " . $stmt->error . "</div>";
            $stmt->close();
        }
    }
}

// B. PROSES EDIT PERTANYAAN (UPDATE)
if (isset($_POST['edit_pertanyaan'])) {
    $id_edit = intval($_POST['id_pertanyaan']);
    $teks = $_POST['teks_pertanyaan'];
    $id_kriteria = intval($_POST['id_kriteria']);

    if ($id_edit > 0 && !empty($teks) && $id_kriteria > 0) {
        $stmt = $conn->prepare("UPDATE pertanyaan SET teks_pertanyaan = ?, id_kriteria = ? WHERE id_pertanyaan = ?");
        $stmt->bind_param("sii", $teks, $id_kriteria, $id_edit);
        if ($stmt->execute()) {
            $stmt->close();
            createLog($conn, "Memperbarui pertanyaan ID: $id_edit");
            $pesan = "<div class='alert alert-success'>Pertanyaan berhasil diperbarui!</div>";
        } else {
            $pesan = "<div class='alert alert-danger'>Gagal memperbarui: " . $stmt->error . "</div>";
            $stmt->close();
        }
    }
}

// F. PROSES BULK UPDATE MATRIKS (TUNING ALGORITMA)
if (isset($_POST['bulk_update_matriks'])) {
    $stmt = $conn->prepare("UPDATE nilai_kriteria_kelas SET nilai_default = ? WHERE id_nilai_kelas = ?");
    foreach ($_POST['nkk'] as $id_nilai => $nilai) {
        $id_n = intval($id_nilai);
        $val = intval($nilai);
        if($val >= 1 && $val <= 5) {
            $stmt->bind_param("ii", $val, $id_n);
            $stmt->execute();
        }
    }
    $stmt->close();
    createLog($conn, "Melakukan Bulk Update Matriks WP");
    $pesan = "<div class='alert alert-success'>Matriks Algoritma WP berhasil diperbarui secara masal!</div>";
}

// D. PROSES CRUD KRITERIA (CREATE & UPDATE)
if (isset($_POST['tambah_kriteria'])) {
    $nama = $_POST['nama_kriteria'];
    $sifat = $_POST['sifat'];
    $bobot = floatval($_POST['bobot_awal']);
    
    $stmt = $conn->prepare("INSERT INTO kriteria (nama_kriteria, sifat, bobot_awal) VALUES (?, ?, ?)");
    $stmt->bind_param("ssd", $nama, $sifat, $bobot);
    if ($stmt->execute()) {
        $new_id = $stmt->insert_id;
        $stmt->close();
        
        // Tambahkan ke matriks nilai default untuk semua kelas
        $stmt_mat = $conn->prepare("INSERT INTO nilai_kriteria_kelas (id_kelas, id_kriteria, nilai_default) SELECT id_kelas, ?, 1 FROM kelas_bimbel WHERE deleted_at IS NULL");
        $stmt_mat->bind_param("i", $new_id);
        $stmt_mat->execute();
        $stmt_mat->close();
        
        createLog($conn, "Menambahkan kriteria baru: $nama");
        $pesan = "<div class='alert alert-success'>Kriteria berhasil ditambahkan!</div>";
    } else {
        $stmt->close();
    }
}

if (isset($_POST['edit_kriteria_submit'])) {
    $id_kr = intval($_POST['id_kriteria']);
    $nama = $_POST['nama_kriteria'];
    $bobot = floatval($_POST['bobot_awal']);

    if ($id_kr > 0 && !empty($nama)) {
        $stmt = $conn->prepare("UPDATE kriteria SET nama_kriteria = ?, bobot_awal = ? WHERE id_kriteria = ?");
        $stmt->bind_param("sdi", $nama, $bobot, $id_kr);
        if ($stmt->execute()) {
            $stmt->close();
            createLog($conn, "Memperbarui kriteria ID: $id_kr");
            $pesan = "<div class='alert alert-success'>Kriteria berhasil diperbarui!</div>";
        } else {
            $pesan = "<div class='alert alert-danger'>Gagal memperbarui kriteria: " . $stmt->error . "</div>";
            $stmt->close();
        }
    }
}

// E. PROSES UPDATE MATRIKS NILAI (UPDATE)
if (isset($_POST['update_matriks'])) {
    $id_kelas = intval($_POST['id_kelas']);
    $skor_matriks = $_POST['matriks']; // Array [id_kriteria => nilai]

    $stmt = $conn->prepare("UPDATE nilai_kriteria_kelas SET nilai_default = ? WHERE id_kelas = ? AND id_kriteria = ?");
    foreach ($skor_matriks as $id_kr => $nilai) {
        $val = intval($nilai);
        $stmt->bind_param("iii", $val, $id_kelas, $id_kr);
        $stmt->execute();
    }
    $stmt->close();
    
    createLog($conn, "Memperbarui matriks kelas ID: $id_kelas");
    $pesan = "<div class='alert alert-success'>Matriks kecocokan kelas berhasil diperbarui!</div>";
}

// G. PENGATURAN WEB & MANAJEMEN USER
if (isset($_POST['update_settings'])) {
    $stmt = $conn->prepare("INSERT INTO pengaturan (nama_key, nilai_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE nilai_value = ?");
    foreach ($_POST['setting'] as $key => $value) {
        $stmt->bind_param("sss", $key, $value, $value);
        $stmt->execute();
    }
    $stmt->close();
    
    createLog($conn, "Memperbarui Pengaturan Situs");
    $pesan = "<div class='alert alert-success border-0 shadow-sm'><i class='bi bi-check-circle-fill me-2'></i>Pengaturan situs berhasil diperbarui!</div>";
}

// H. PROSES EDIT USER (UPDATE)
if (isset($_POST['edit_user_submit'])) {
    $id_user_edit = intval($_POST['id_user']);
    $nama_lengkap = $_POST['nama_lengkap'];
    $username_edit = $_POST['username'];
    $level_edit = $_POST['level'];
    $no_hp_edit = $_POST['no_hp'];
    $password_baru = $_POST['password'];

    // Update profil dasar
    $stmt = $conn->prepare("UPDATE user SET nama_lengkap = ?, username = ?, level = ?, no_hp = ? WHERE id_user = ?");
    $stmt->bind_param("ssssi", $nama_lengkap, $username_edit, $level_edit, $no_hp_edit, $id_user_edit);
    
    if ($stmt->execute()) {
        $stmt->close();
        // Jika password baru diisi, maka lakukan hashing dan update
        if (!empty($password_baru)) {
            $password_hashed = password_hash($password_baru, PASSWORD_DEFAULT);
            $stmt_pass = $conn->prepare("UPDATE user SET password = ? WHERE id_user = ?");
            $stmt_pass->bind_param("si", $password_hashed, $id_user_edit);
            $stmt_pass->execute();
            $stmt_pass->close();
        }
        createLog($conn, "Memperbarui data user: $username_edit (ID: $id_user_edit)");
        $pesan = "<div class='alert alert-success'>Data pengguna berhasil diperbarui!</div>";
    } else {
        $pesan = "<div class='alert alert-danger'>Gagal memperbarui pengguna: " . $stmt->error . "</div>";
        $stmt->close();
    }
}

// I. PROSES GANTI PASSWORD ADMIN SENDIRI
if (isset($_POST['change_admin_password'])) {
    $id_admin_login = $_SESSION['id_user'];
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_new_password = $_POST['confirm_new_password'];

    // Ambil password hash admin dari database
    $stmt = $conn->prepare("SELECT password FROM user WHERE id_user = ? AND level = 'admin'");
    $stmt->bind_param("i", $id_admin_login);
    $stmt->execute();
    $admin_query = $stmt->get_result();
    
    if ($admin_query->num_rows > 0) {
        $admin_data = $admin_query->fetch_assoc();
        $stmt->close();
        // Verifikasi password lama
        if (password_verify($current_password, $admin_data['password'])) {
            // Pastikan password baru dan konfirmasi cocok
            if ($new_password === $confirm_new_password) {
                $new_password_hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt_up = $conn->prepare("UPDATE user SET password = ? WHERE id_user = ?");
                $stmt_up->bind_param("si", $new_password_hashed, $id_admin_login);
                $stmt_up->execute();
                $stmt_up->close();
                
                createLog($conn, "Mengubah password sendiri (ID: $id_admin_login)");
                $pesan = "<div class='alert alert-success'>Password berhasil diubah!</div>";
            } else {
                $pesan = "<div class='alert alert-danger'>Password baru dan konfirmasi tidak cocok!</div>";
            }
        } else {
            $pesan = "<div class='alert alert-danger'>Password lama salah!</div>";
        }
    } else {
        $stmt->close();
        // Seharusnya tidak terjadi jika guard di atas sudah benar
        $pesan = "<div class='alert alert-danger'>Admin tidak ditemukan.</div>";
    }
}

// C. PROSES HAPUS PERTANYAAN (SOFT DELETE)
if (isset($_GET['aksi']) && $_GET['aksi'] == 'hapus') {
    $id_hapus = intval($_GET['id']);
    $stmt = $conn->prepare("UPDATE pertanyaan SET deleted_at = NOW() WHERE id_pertanyaan = ?");
    $stmt->bind_param("i", $id_hapus);
    if ($stmt->execute()) {
        $stmt->close();
        createLog($conn, "Menghapus pertanyaan ID: $id_hapus (Soft Delete)");
        $pesan = "<div class='alert alert-success'>Pertanyaan berhasil dihapus (Soft Delete)!</div>";
    } else {
        $pesan = "<div class='alert alert-danger'>Gagal menghapus: " . $stmt->error . "</div>";
        $stmt->close();
    }
}

if (isset($_GET['aksi']) && $_GET['aksi'] == 'hapus_kelas') {
    $id_hapus = intval($_GET['id']);
    $stmt = $conn->prepare("UPDATE kelas_bimbel SET deleted_at = NOW() WHERE id_kelas = ?");
    $stmt->bind_param("i", $id_hapus);
    $stmt->execute();
    $stmt->close();
    
    createLog($conn, "Menghapus kelas ID: $id_hapus (Soft Delete)");
    $pesan = "<div class='alert alert-success'>Kelas berhasil dihapus (Soft Delete)!</div>";
}

// RESTORE KELAS
if (isset($_GET['aksi']) && $_GET['aksi'] == 'restore_kelas') {
    $id_restore = intval($_GET['id']);
    $stmt = $conn->prepare("UPDATE kelas_bimbel SET deleted_at = NULL WHERE id_kelas = ?");
    $stmt->bind_param("i", $id_restore);
    $stmt->execute();
    $stmt->close();
    createLog($conn, "Memulihkan kelas ID: $id_restore");
    $pesan = "<div class='alert alert-success'><i class='bi bi-arrow-counterclockwise me-2'></i>Kelas berhasil dipulihkan!</div>";
}

// HAPUS PERMANEN KELAS
if (isset($_GET['aksi']) && $_GET['aksi'] == 'hapus_permanen_kelas') {
    $id_del = intval($_GET['id']);
    // Hapus nilai matriks dulu agar tidak error FK
    $stmt = $conn->prepare("DELETE FROM nilai_kriteria_kelas WHERE id_kelas = ?");
    $stmt->bind_param("i", $id_del);
    $stmt->execute();
    $stmt->close();
    $stmt = $conn->prepare("DELETE FROM kelas_bimbel WHERE id_kelas = ?");
    $stmt->bind_param("i", $id_del);
    $stmt->execute();
    $stmt->close();
    createLog($conn, "Menghapus permanen kelas ID: $id_del");
    $pesan = "<div class='alert alert-danger'><i class='bi bi-trash-fill me-2'></i>Kelas dihapus secara permanen!</div>";
}

// RESTORE SOAL
if (isset($_GET['aksi']) && $_GET['aksi'] == 'restore_soal') {
    $id_restore = intval($_GET['id']);
    $stmt = $conn->prepare("UPDATE pertanyaan SET deleted_at = NULL WHERE id_pertanyaan = ?");
    $stmt->bind_param("i", $id_restore);
    $stmt->execute();
    $stmt->close();
    createLog($conn, "Memulihkan pertanyaan ID: $id_restore");
    $pesan = "<div class='alert alert-success'><i class='bi bi-arrow-counterclockwise me-2'></i>Pertanyaan berhasil dipulihkan!</div>";
}

// HAPUS PERMANEN SOAL
if (isset($_GET['aksi']) && $_GET['aksi'] == 'hapus_permanen_soal') {
    $id_del = intval($_GET['id']);
    $stmt = $conn->prepare("DELETE FROM pertanyaan WHERE id_pertanyaan = ?");
    $stmt->bind_param("i", $id_del);
    $stmt->execute();
    $stmt->close();
    createLog($conn, "Menghapus permanen pertanyaan ID: $id_del");
    $pesan = "<div class='alert alert-danger'><i class='bi bi-trash-fill me-2'></i>Pertanyaan dihapus secara permanen!</div>";
}

if (isset($_GET['aksi']) && $_GET['aksi'] == 'hapus_user') {
    $id_hapus = intval($_GET['id']);
    if ($id_hapus != $_SESSION['id_user']) {
        $stmt = $conn->prepare("DELETE FROM user WHERE id_user = ?");
        $stmt->bind_param("i", $id_hapus);
        $stmt->execute();
        $stmt->close();
        
        createLog($conn, "Menghapus user ID: $id_hapus");
        $pesan = "<div class='alert alert-success'>Pengguna berhasil dihapus!</div>";
    }
}

if (isset($_GET['aksi']) && $_GET['aksi'] == 'hapus_kriteria') {
    $id_hapus = intval($_GET['id']);
    $stmt = $conn->prepare("DELETE FROM kriteria WHERE id_kriteria = ?");
    $stmt->bind_param("i", $id_hapus);
    if ($stmt->execute()) {
        $stmt->close();
        createLog($conn, "Menghapus kriteria ID: $id_hapus");
        $pesan = "<div class='alert alert-success'>Kriteria berhasil dihapus!</div>";
    } else {
        $stmt->close();
    }
}


// ==========================================
// HITUNG STATISTIK KARTU (DASHBOARD BOX)
// ==========================================
$count_kriteria = $conn->query("SELECT COUNT(*) as total FROM kriteria")->fetch_assoc()['total'];
$count_soal = $conn->query("SELECT COUNT(*) as total FROM pertanyaan WHERE deleted_at IS NULL")->fetch_assoc()['total'];
$count_asesmen = $conn->query("SELECT COUNT(*) as total FROM asesmen")->fetch_assoc()['total'];
$count_kelas = $conn->query("SELECT COUNT(*) as total FROM kelas_bimbel WHERE deleted_at IS NULL")->fetch_assoc()['total'];


// ==========================================
// AMBIL DATA UNTUK TABEL & DROPDOWN
// ==========================================
// Ambil semua pertanyaan bergabung dengan nama kriterianya (READ)
$sql_tabel = "SELECT p.*, k.nama_kriteria FROM pertanyaan p 
              JOIN kriteria k ON p.id_kriteria = k.id_kriteria 
              WHERE p.deleted_at IS NULL
              ORDER BY p.id_kriteria ASC, p.id_pertanyaan ASC";
$result_tabel = $conn->query($sql_tabel);

// Ambil data kriteria untuk pilihan di form tambah
$list_kriteria = $conn->query("SELECT * FROM kriteria ORDER BY id_kriteria ASC");

// Ambil data kelas
$list_kelas = $conn->query("SELECT * FROM kelas_bimbel WHERE deleted_at IS NULL ORDER BY status_kelas ASC, nama_kelas ASC");

// Ambil data kelas yang dihapus (arsip)
$list_kelas_deleted = $conn->query("SELECT * FROM kelas_bimbel WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC");

// Ambil data soal yang dihapus (arsip)
$result_tabel_deleted = $conn->query("SELECT p.*, k.nama_kriteria FROM pertanyaan p JOIN kriteria k ON p.id_kriteria = k.id_kriteria WHERE p.deleted_at IS NOT NULL ORDER BY p.deleted_at DESC");

// Data untuk Matrix Grid
$all_c_query = $conn->query("SELECT * FROM kriteria ORDER BY id_kriteria ASC");
$kriterias = [];
while($k = $all_c_query->fetch_assoc()) $kriterias[] = $k;

// Ambil data Semua User dengan fitur pencarian
$search_user = isset($_GET['search_user']) ? trim($_GET['search_user']) : '';
$sql_user = "SELECT * FROM user";
if (!empty($search_user)) {
    $search_term = "%" . $search_user . "%";
    $sql_user .= " WHERE (username LIKE ? OR nama_lengkap LIKE ?)";
}
$sql_user .= " ORDER BY FIELD(level, 'admin', 'staf', 'orang_tua'), nama_lengkap ASC";

$stmt_user = $conn->prepare($sql_user);
if (!empty($search_user)) $stmt_user->bind_param("ss", $search_term, $search_term);
$stmt_user->execute();
$list_user = $stmt_user->get_result();

// Ambil Pengaturan
$settings = [];
$res_set = $conn->query("SELECT * FROM pengaturan");
while($s = $res_set->fetch_assoc()) $settings[$s['nama_key']] = $s['nilai_value'];

// Ambil Log (15 terakhir)
$logs = $conn->query("SELECT l.*, u.username FROM logs l JOIN user u ON l.id_user = u.id_user ORDER BY l.waktu DESC LIMIT 15");

$page_title = "Panel Admin - SIMBIM";
include 'includes/header.php';
?>

<div class="container py-5">
    <div class="row mb-5 d-print-none">
        <div class="col-lg-8">
            <h1 class="fw-bold text-dark mb-2"><i class="bi bi-speedometer2 me-2 text-primary"></i>Manajemen Sistem</h1>
            <p class="text-muted">Kelola parameter kuesioner, pantau metrik keputusan sistem, dan kelola data secara terpusat.
            </p>
        </div>
        <div class="col-lg-4 text-lg-end d-flex align-items-center justify-content-lg-end">
            <div class="pb-card p-3 text-center" style="min-width: 250px;">
                <small class="text-muted d-block mb-2">ADMIN TERDAFTAR</small>
                <h5 class="fw-bold text-dark mb-0"><?= htmlspecialchars($_SESSION['nama_lengkap']); ?></h5>
            </div>
        </div>
    </div>

    <div class="mb-4 d-print-none">
        <?= $pesan; ?>
    </div>

    <!-- Dashboard Statistics Cards -->
    <div class="row g-3 mb-5 d-print-none">
        <div class="col-md-6 col-lg-3">
            <div class="pb-card p-4 h-100 stat-card-primary">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small fw-semibold mb-2">KRITERIA SISTEM</div>
                        <div class="display-5 fw-bold text-dark"><?= $count_kriteria; ?></div>
                    </div>
                    <div class="stat-icon-primary">
                        <i class="bi bi-diagram-3"></i>
                    </div>
                </div>
                <small class="text-muted d-block mt-3">Parameter penilaian aktif</small>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="pb-card p-4 h-100 stat-card-success">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small fw-semibold mb-2">BANK SOAL</div>
                        <div class="display-5 fw-bold text-dark"><?= $count_soal; ?></div>
                    </div>
                    <div class="stat-icon-success">
                        <i class="bi bi-question-circle"></i>
                    </div>
                </div>
                <small class="text-muted d-block mt-3">Pertanyaan tersedia</small>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="pb-card p-4 h-100 stat-card-warning">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small fw-semibold mb-2">KELAS BIMBEL</div>
                        <div class="display-5 fw-bold text-dark"><?= $count_kelas; ?></div>
                    </div>
                    <div class="stat-icon-warning">
                        <i class="bi bi-building"></i>
                    </div>
                </div>
                <small class="text-muted d-block mt-3">Kelas aktif</small>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="pb-card p-4 h-100 stat-card-info">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small fw-semibold mb-2">TOTAL ASESMEN</div>
                        <div class="display-5 fw-bold text-dark"><?= $count_asesmen; ?></div>
                    </div>
                    <div class="stat-icon-info">
                        <i class="bi bi-journal-check"></i>
                    </div>
                </div>
                <small class="text-muted d-block mt-3">Asesmen selesai</small>
            </div>
        </div>
    </div>

    <!-- Nav Tabs Section -->
    <div class="pb-card mb-4 d-print-none" style="border-bottom: 1px solid #f1f5f9;">
        <ul class="nav nav-pills p-3" id="adminTab" role="tablist" style="border-bottom: none;">
            <li class="nav-item"><button class="nav-link active fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-analytics"><i class="bi bi-bar-chart-line me-2"></i>Analytics</button></li>
            <li class="nav-item"><button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-bimbel"><i class="bi bi-building me-2"></i>Kelas & Matriks</button></li>
            <li class="nav-item"><button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-soal"><i class="bi bi-question-circle me-2"></i>Bank Soal</button></li>
            <li class="nav-item"><button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-kriteria"><i class="bi bi-gear me-2"></i>Parameter Kriteria</button></li>
            <li class="nav-item"><button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-user"><i class="bi bi-people me-2"></i>Manajemen User</button></li>
            <li class="nav-item"><button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-laporan"><i class="bi bi-file-earmark-text me-2"></i>Rekap Laporan</button></li>
            <li class="nav-item"><button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-settings"><i class="bi bi-sliders me-2"></i>Pengaturan Web</button></li>
            <li class="nav-item"><button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-system"><i class="bi bi-terminal me-2"></i>Sistem & Log</button></li>
        </ul>
    </div>

    <div class="tab-content">
        <!-- 1. TAB ANALYTICS -->
        <div class="tab-pane fade show active" id="tab-analytics">
            <!-- Statistik Kartu Perbandingan -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="pb-card p-4 border-start border-5 border-primary h-100">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div>
                                <div class="text-muted small fw-semibold mb-2">ASESMEN BULAN INI</div>
                                <div class="display-5 fw-bold text-dark"><?= $this_month_count ?></div>
                            </div>
                            <div class="stat-icon-primary">
                                <i class="bi bi-calendar-check"></i>
                            </div>
                        </div>
                        <div class="text-muted small">vs bulan lalu: <span class="badge <?= $month_diff_pct >= 0 ? 'bg-success' : 'bg-danger' ?> ms-1"><?= $month_diff_pct >= 0 ? '+' : '' ?><?= $month_diff_pct ?>%</span></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="pb-card p-4 border-start border-5 border-warning h-100">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div>
                                <div class="text-muted small fw-semibold mb-2">ASESMEN BULAN LALU</div>
                                <div class="display-5 fw-bold text-dark"><?= $last_month_count ?></div>
                            </div>
                            <div class="stat-icon-warning">
                                <i class="bi bi-calendar-x"></i>
                            </div>
                        </div>
                        <small class="text-muted d-block mt-2">Periode bulan lalu</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="pb-card p-4 border-start border-5 border-info h-100">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div>
                                <div class="text-muted small fw-semibold mb-2">TOTAL ORANG TUA</div>
                                <div class="display-5 fw-bold text-dark"><?= $user_count_this_month ?></div>
                            </div>
                            <div class="stat-icon-info">
                                <i class="bi bi-people"></i>
                            </div>
                        </div>
                        <small class="text-muted d-block mt-2">User terdaftar</small>
                    </div>
                </div>
            </div>
            <!-- Charts -->
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="pb-card p-4 border-top border-5" style="border-color: #4A90E2;">
                        <h5 class="fw-bold mb-4 text-dark"><i class="bi bi-graph-up me-2 text-primary"></i>Tren Pendaftaran Asesmen (6 Bulan Terakhir)</h5>
                        <canvas id="barMonthly" height="150"></canvas>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="pb-card p-4 border-top border-5" style="border-color: #10b981;">
                        <h5 class="fw-bold mb-4 text-dark"><i class="bi bi-pie-chart me-2 text-success"></i>Distribusi Minat Bakat</h5>
                        <canvas id="pieInterest"></canvas>
                    </div>
                </div>
                <div class="col-12">
                    <div class="pb-card p-4 border-top border-5" style="border-color: #8b5cf6;">
                        <h5 class="fw-bold mb-3 text-dark"><i class="bi bi-bar-chart-steps me-2" style="color: #8b5cf6;"></i>Top 5 Kelas Terdaftar</h5>
                        <p class="text-muted small mb-3">Distribusi jumlah kelas yang tersedia di sistem berdasarkan frekuensi asesmen yang berjalan.</p>
                        <canvas id="barTopKelas" height="90"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. TAB KELAS & MATRIKS (Bulk Update) -->
        <div class="tab-pane fade" id="tab-bimbel">
            <div class="row g-4 mb-4">
                <div class="col-lg-4">
                    <div class="pb-card p-4 border-top border-5" style="border-color: #4A90E2;">
                        <h5 class="fw-bold mb-4 text-dark"><i class="bi bi-plus-circle-fill me-2 text-primary"></i>Tambah Kelas Baru</h5>
                        <form action="" method="POST">
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Nama Kelas</label>
                                <input type="text" name="nama_kelas" class="form-control" placeholder="Contoh: Digital Art" required>
                            </div>
                            <div class="mb-4">
                                <label class="form-label fw-semibold small">Status Operasional</label>
                                <select name="status_kelas" class="form-select">
                                    <option value="Aktif">Aktif (Tersedia)</option>
                                    <option value="Coming Soon">Coming Soon (Promosi)</option>
                                </select>
                            </div>
                            <button type="submit" name="tambah_kelas" class="btn btn-primary w-100 shadow-sm">
                                <i class="bi bi-plus-lg me-2"></i>Tambah Kelas
                            </button>
                        </form>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="pb-card p-4 h-100 border-top border-5" style="border-color: #10b981;">
                        <h5 class="fw-bold mb-4 text-dark"><i class="bi bi-list-check me-2 text-success"></i>Daftar Kelas</h5>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Nama Kelas</th>
                                        <th>Status</th>
                                        <th class="text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $list_kelas->data_seek(0);
                                    while ($kls = $list_kelas->fetch_assoc()) { ?>
                                        <tr>
                                            <td class="fw-bold"><?= htmlspecialchars($kls['nama_kelas']); ?></td>
                                            <td>
                                                <span class="badge <?= $kls['status_kelas'] == 'Aktif' ? 'bg-success' : 'bg-warning text-dark'; ?>">
                                                    <?= $kls['status_kelas']; ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-info text-white rounded-pill px-3" 
                                                        data-bs-toggle="modal" data-bs-target="#modalMatriks" 
                                                        data-id="<?= $kls['id_kelas']; ?>" data-nama="<?= $kls['nama_kelas']; ?>">
                                                    <i class="bi bi-grid-3x3 me-1"></i>Matriks
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3"
                                                        data-bs-toggle="modal" data-bs-target="#modalEditKelas"
                                                        data-id="<?= $kls['id_kelas']; ?>" data-nama="<?= $kls['nama_kelas']; ?>" 
                                                        data-status="<?= $kls['status_kelas']; ?>">
                                                    <i class="bi bi-pencil me-1"></i>Edit
                                                </button>
                                                <a href="admin_dashboard.php?id=<?= $kls['id_kelas']; ?>&aksi=hapus_kelas" 
                                                   class="btn btn-sm btn-outline-danger rounded-pill px-3"
                                                   onclick="return confirm('Hapus kelas ini?')">
                                                   <i class="bi bi-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="pb-card p-4 border-top border-5" style="border-color: #f59e0b;">
                <h5 class="fw-bold mb-4"><i class="bi bi-lightning-charge-fill me-2" style="color: #f59e0b;"></i>Tuning Algoritma: Bulk Update Matriks</h5>
                <form action="" method="POST">
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle text-center">
                            <thead class="table-light">
                                <tr><th class="text-start">Kelas Bimbel</th><?php foreach($kriterias as $c): ?><th title="<?= $c['nama_kriteria'] ?>">C<?= $c['id_kriteria'] ?></th><?php endforeach; ?></tr>
                            </thead>
                            <tbody>
                                <?php 
                                $stmt_vals = $conn->prepare("SELECT * FROM nilai_kriteria_kelas WHERE id_kelas = ? ORDER BY id_kriteria ASC");
                                $list_kelas->data_seek(0); 
                                while($kls = $list_kelas->fetch_assoc()): 
                                ?>
                                <tr>
                                    <td class="text-start fw-bold small"><?= $kls['nama_kelas'] ?></td>
                                    <?php 
                                    $id_k = $kls['id_kelas']; 
                                    $stmt_vals->bind_param("i", $id_k);
                                    $stmt_vals->execute();
                                    $vals = $stmt_vals->get_result();
                                    while($v = $vals->fetch_assoc()): 
                                    ?>
                                    <td><input type="number" name="nkk[<?= $v['id_nilai_kelas'] ?>]" class="form-control form-control-sm text-center mx-auto" style="width: 60px;" min="1" max="5" value="<?= $v['nilai_default'] ?>"></td>
                                    <?php endwhile; ?>
                                </tr>
                                <?php 
                                endwhile; 
                                $stmt_vals->close();
                                ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="submit" name="bulk_update_matriks" class="btn btn-primary shadow-sm mt-3">Simpan Perubahan Matriks Masal</button>
                </form>
            </div>
        </div>


        <div class="tab-pane fade" id="tab-soal">
            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="pb-card p-4 border-top border-5" style="border-color: #f59e0b;">
                        <h5 class="fw-bold mb-4 text-dark"><i class="bi bi-plus-circle-fill me-2" style="color: #f59e0b;"></i>Tambah Pertanyaan</h5>
                        <form action="" method="POST">
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Aspek Kriteria Kecerdasan</label>
                                <select name="id_kriteria" class="form-select" required>
                                    <option value="">-- Pilih Aspek --</option>
                                    <?php $list_kriteria->data_seek(0); while ($k = $list_kriteria->fetch_assoc()) { ?>
                                        <option value="<?= $k['id_kriteria']; ?>">C<?= $k['id_kriteria']; ?> - <?= htmlspecialchars($k['nama_kriteria']); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="mb-4">
                                <label class="form-label fw-semibold">Teks Pertanyaan</label>
                                <textarea name="teks_pertanyaan" class="form-control" rows="4" placeholder="Masukkan teks pertanyaan..." required></textarea>
                            </div>
                            <button type="submit" name="tambah_pertanyaan" class="btn btn-primary w-100 shadow-sm"><i class="bi bi-save me-2"></i>Simpan Pertanyaan</button>
                        </form>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="pb-card p-4 border-top border-5" style="border-color: #8b5cf6;">
                        <h5 class="fw-bold mb-4 text-dark"><i class="bi bi-list-stars me-2" style="color: #8b5cf6;"></i>Daftar Pertanyaan</h5>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr><th style="width: 100px;">Aspek</th><th>Teks Pertanyaan</th><th style="width: 150px; text-align: center;">Aksi</th></tr>
                                </thead>
                                <tbody>
                                    <?php if ($result_tabel->num_rows > 0) { while ($row = $result_tabel->fetch_assoc()) { ?>
                                        <tr>
                                            <td><span class="badge bg-secondary-subtle text-secondary px-3 py-2">C<?= $row['id_kriteria']; ?></span></td>
                                            <td class="text-wrap small"><?= htmlspecialchars($row['teks_pertanyaan']); ?></td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#modalEdit" data-id="<?= $row['id_pertanyaan']; ?>" data-teks="<?= htmlspecialchars($row['teks_pertanyaan']); ?>" data-kriteria="<?= $row['id_kriteria']; ?>"><i class="bi bi-pencil"></i></button>
                                                <a href="admin_dashboard.php?id=<?= $row['id_pertanyaan']; ?>&aksi=hapus" class="btn btn-outline-danger btn-sm rounded-pill px-3" onclick="return confirm('Hapus?')"><i class="bi bi-trash"></i></a>
                                            </td>
                                        </tr>
                                    <?php } } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ACCORDION ARSIP SOAL -->
            <div class="accordion mt-3" id="accordionArsipSoal">
                <div class="accordion-item border-danger-subtle">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed bg-danger-subtle text-danger fw-bold py-3" type="button" data-bs-toggle="collapse" data-bs-target="#collapseArsipSoal">
                            <i class="bi bi-trash2-fill me-2"></i>
                            Arsip Soal Dihapus
                            <span class="badge bg-danger ms-2"><?= $result_tabel_deleted->num_rows ?></span>
                        </button>
                    </h2>
                    <div id="collapseArsipSoal" class="accordion-collapse collapse">
                        <div class="accordion-body p-3">
                            <?php if ($result_tabel_deleted->num_rows == 0): ?>
                                <p class="text-muted text-center mb-0"><i class="bi bi-check-circle me-2"></i>Tidak ada soal di arsip.</p>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light">
                                        <tr><th style="width:80px">Aspek</th><th>Teks Pertanyaan</th><th>Dihapus Pada</th><th class="text-center">Aksi</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php while($ds = $result_tabel_deleted->fetch_assoc()): ?>
                                        <tr class="table-danger">
                                            <td><span class="badge bg-secondary-subtle text-secondary px-2 py-1">C<?= $ds['id_kriteria'] ?></span></td>
                                            <td class="text-wrap small text-muted"><?= htmlspecialchars(mb_strimwidth($ds['teks_pertanyaan'], 0, 80, '...')) ?></td>
                                            <td class="small text-muted"><?= date('d/m/Y H:i', strtotime($ds['deleted_at'])) ?></td>
                                            <td class="text-center">
                                                <a href="admin_dashboard.php?id=<?= $ds['id_pertanyaan'] ?>&aksi=restore_soal"
                                                   class="btn btn-sm btn-success rounded-pill px-3 me-1"
                                                   onclick="return confirm('Pulihkan soal ini?')">
                                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Pulihkan
                                                </a>
                                                <a href="admin_dashboard.php?id=<?= $ds['id_pertanyaan'] ?>&aksi=hapus_permanen_soal"
                                                   class="btn btn-sm btn-danger rounded-pill px-3"
                                                   onclick="return confirm('HAPUS PERMANEN? Tidak bisa dibatalkan!')">
                                                    <i class="bi bi-trash-fill me-1"></i>Hapus Permanen
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. TAB PARAMETER (Existing Kriteria Logic) -->
        <div class="tab-pane fade" id="tab-kriteria">
            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="pb-card p-4 border-top border-5" style="border-color: #06b6d4;">
                        <h5 class="fw-bold mb-4 text-dark"><i class="bi bi-plus-circle me-2 text-info"></i>Tambah Kriteria</h5>
                        <form action="" method="POST">
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Nama Kriteria</label>
                                <input type="text" name="nama_kriteria" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Sifat</label>
                                <select name="sifat" class="form-select">
                                    <option value="Benefit">Benefit</option>
                                    <option value="Cost">Cost</option>
                                </select>
                            </div>
                            <div class="mb-4">
                                <label class="form-label fw-semibold">Bobot Awal</label>
                                <input type="number" step="0.01" name="bobot_awal" class="form-control" value="1" required>
                            </div>
                            <button type="submit" name="tambah_kriteria" class="btn btn-primary w-100 shadow-sm">Simpan Kriteria</button>
                        </form>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="pb-card p-4 h-100 border-top border-5" style="border-color: #ef4444;">
                        <h5 class="fw-bold mb-4 text-dark"><i class="bi bi-gear-fill me-2 text-danger"></i>Pengaturan Kriteria</h5>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr><th style="width: 100px;">ID</th><th>Nama Kriteria</th><th>Sifat</th><th>Bobot Awal</th><th style="width: 150px; text-align: center;">Aksi</th></tr>
                                </thead>
                                <tbody>
                                    <?php $list_kriteria->data_seek(0); while ($k = $list_kriteria->fetch_assoc()) { ?>
                                        <tr>
                                            <td><span class="badge bg-primary-subtle text-primary px-3 py-2">C<?= $k['id_kriteria']; ?></span></td>
                                            <td><?= htmlspecialchars($k['nama_kriteria']); ?></td>
                                            <td><span class="badge <?= $k['sifat'] == 'Benefit' ? 'bg-success' : 'bg-warning'; ?>"><?= $k['sifat']; ?></span></td>
                                            <td class="fw-bold"><?= $k['bobot_awal']; ?></td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#modalEditKriteria" data-id="<?= $k['id_kriteria']; ?>" data-nama="<?= htmlspecialchars($k['nama_kriteria']); ?>" data-bobot="<?= $k['bobot_awal']; ?>"><i class="bi bi-pencil"></i></button>
                                                <a href="admin_dashboard.php?id=<?= $k['id_kriteria']; ?>&aksi=hapus_kriteria" class="btn btn-outline-danger btn-sm rounded-pill px-3" onclick="return confirm('Hapus kriteria ini?')"><i class="bi bi-trash"></i></a>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 5. TAB MANAJEMEN USER -->
        <div class="tab-pane fade" id="tab-user">
            <div class="pb-card p-4 border-top border-5" style="border-color: #8b5cf6;">
                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                    <h5 class="fw-bold mb-0"><i class="bi bi-people-fill me-2" style="color: #8b5cf6;"></i>Manajemen Pengguna</h5>
                    <form action="admin_dashboard.php" method="GET" id="userSearchForm">
                        <div class="input-group" style="max-width: 300px;">
                            <input type="text" name="search_user" class="form-control" placeholder="Cari username atau nama..." value="<?= htmlspecialchars($search_user) ?>">
                            <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search"></i></button>
                        </div>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr><th>ID</th><th>Username</th><th>Nama Lengkap</th><th>Nomor HP</th><th>Level</th><th class="text-center">Aksi</th></tr>
                        </thead>
                        <tbody>
                            <?php while($u = $list_user->fetch_assoc()): ?>
                                <tr>
                                    <td>#<?= $u['id_user'] ?></td>
                                    <td><strong><?= $u['username'] ?></strong></td>
                                    <td><?= $u['nama_lengkap'] ?></td>
                                    <td><?= htmlspecialchars($u['no_hp'] ?? '-') ?></td>
                                    <td>
                                        <span class="badge bg-primary-subtle text-primary fw-semibold"><?= ucfirst($u['level']) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3 me-1" 
                                                data-bs-toggle="modal" data-bs-target="#modalEditUser" 
                                                data-id="<?= $u['id_user'] ?>" 
                                                data-username="<?= htmlspecialchars($u['username']) ?>" 
                                                data-nama="<?= htmlspecialchars($u['nama_lengkap']) ?>"
                                                data-level="<?= $u['level'] ?>"
                                                data-nohp="<?= htmlspecialchars($u['no_hp'] ?? '') ?>">
                                            <i class="bi bi-pencil me-1"></i>Edit
                                        </button>
                                        <a href="admin_dashboard.php?id=<?= $u['id_user'] ?>&aksi=hapus_user" class="btn btn-outline-danger btn-sm rounded-pill px-3" onclick="return confirm('Hapus user ini?')"><i class="bi bi-person-x me-1"></i>Hapus</a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 6. TAB LAPORAN GLOBAL -->
        <div class="tab-pane fade" id="tab-laporan">
            <div class="pb-card p-4 border-top border-5" style="border-color: #10b981;">
                <h5 class="fw-bold mb-4 text-dark"><i class="bi bi-file-earmark-text me-2 text-success"></i>Rekapitulasi Data Asesmen</h5>
                <form method="POST" class="row g-3 mb-4 d-print-none">
                    <div class="col-md-3"><label class="form-label">Mulai Tanggal</label><input type="date" name="start_date" class="form-control" value="<?= $start_date ?>"></div>
                    <div class="col-md-3"><label class="form-label">Sampai Tanggal</label><input type="date" name="end_date" class="form-control" value="<?= $end_date ?>"></div>
                    <div class="col-md-6 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">Filter Data</button>
                        <button type="button" onclick="window.print()" class="btn btn-outline-danger w-100"><i class="bi bi-printer me-2"></i>Cetak</button>
                        <a href="export_excel.php?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="btn btn-success w-100"><i class="bi bi-file-earmark-excel me-2"></i>Excel</a>
                    </div>
                </form>

                <div class="table-responsive printable-area">
                    <h4 class="d-none d-print-block text-center mb-4">REKAPITULASI DATA ASESMEN SIMBIM<br><small><?= $start_date ?> s/d <?= $end_date ?></small></h4>
                    <table class="table table-striped">
                        <thead class="table-dark">
                            <tr><th>Tanggal</th><th>Nama Anak</th><th>Usia</th><th>Nama Wali (User)</th><th class="d-print-none text-center">Export</th></tr>
                        </thead>
                        <tbody>
                            <?php while($r = $result_rekap->fetch_assoc()): ?>
                                <tr>
                                    <td><?= date('d/m/Y', strtotime($r['tgl_asesmen'])) ?></td>
                                    <td><?= $r['nama_anak'] ?></td>
                                    <td><?= $r['usia'] ?> Thn</td>
                                    <td><?= $r['wali'] ?></td>
                                    <td class="d-print-none text-center">
                                        <form action="hitung_wp.php" method="GET" target="_blank" class="d-inline">
                                            <input type="hidden" name="id_asesmen" value="<?= $r['id_asesmen'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-pdf"></i> PDF</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 7. TAB PENGATURAN WEB -->
        <div class="tab-pane fade" id="tab-settings">
            <div class="pb-card p-4 border-top border-5" style="border-color: #06b6d4;">
                <h5 class="fw-bold mb-4 text-dark"><i class="bi bi-sliders me-2 text-info"></i>Pengaturan Konfigurasi Situs</h5>
                <form action="" method="POST">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold small">Nama Situs</label>
                                <input type="text" name="setting[site_name]" class="form-control" value="<?= htmlspecialchars($settings['site_name'] ?? '') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold small">Tagline</label>
                                <input type="text" name="setting[tagline]" class="form-control" value="<?= htmlspecialchars($settings['tagline'] ?? '') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold small">WhatsApp Admin</label>
                                <input type="text" name="setting[whatsapp_admin]" class="form-control" value="<?= htmlspecialchars($settings['whatsapp_admin'] ?? '') ?>" placeholder="628123xxx">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold small">Banner Hero Beranda (URL Gambar)</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-image"></i></span>
                                    <input type="text" name="setting[hero_banner]" id="heroBannerInput" class="form-control" value="<?= htmlspecialchars($settings['hero_banner'] ?? 'https://img.freepik.com/free-vector/kids-learning-online-home_23-2148518135.jpg') ?>" placeholder="https://link-gambar.com/banner.jpg">
                                    <button class="btn btn-outline-secondary" type="button" onclick="window.open(document.getElementById('heroBannerInput').value, '_blank')">Preview</button>
                                </div>
                                <small class="text-muted italic">Masukkan link gambar untuk banner utama yang tampil di beranda depan.</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold small">Logo Situs (URL Gambar)</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-box"></i></span>
                                    <input type="text" name="setting[site_logo]" id="siteLogoInput" class="form-control" value="<?= htmlspecialchars($settings['site_logo'] ?? '') ?>" placeholder="https://link-gambar.com/logo.png">
                                    <button class="btn btn-outline-secondary" type="button" onclick="window.open(document.getElementById('siteLogoInput').value, '_blank')">Preview</button>
                                </div>
                                <small class="text-muted italic">Logo yang muncul di navbar dan halaman login (kosongkan untuk ikon default).</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold small">Favicon (URL Gambar .ico/.png)</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-browser-chrome"></i></span>
                                    <input type="text" name="setting[site_favicon]" id="faviconInput" class="form-control" value="<?= htmlspecialchars($settings['site_favicon'] ?? '') ?>" placeholder="https://link-gambar.com/favicon.ico">
                                    <button class="btn btn-outline-secondary" type="button" onclick="window.open(document.getElementById('faviconInput').value, '_blank')">Preview</button>
                                </div>
                                <small class="text-muted italic">Ikon kecil yang muncul di tab browser.</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold small">Email Kontak</label>
                                <input type="email" name="setting[contact_email]" class="form-control" value="<?= htmlspecialchars($settings['contact_email'] ?? '') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold small">Mode Pemeliharaan (Maintenance)</label>
                                <select name="setting[maintenance_mode]" class="form-select">
                                    <option value="0" <?= ($settings['maintenance_mode'] ?? '0') == '0' ? 'selected' : '' ?>>Nonaktif (Normal)</option>
                                    <option value="1" <?= ($settings['maintenance_mode'] ?? '0') == '1' ? 'selected' : '' ?>>Aktif (Maintenance)</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold small">Pendaftaran Asesmen</label>
                                <select name="setting[registration_open]" class="form-select">
                                    <option value="1" <?= ($settings['registration_open'] ?? '1') == '1' ? 'selected' : '' ?>>Dibuka</option>
                                    <option value="0" <?= ($settings['registration_open'] ?? '1') == '0' ? 'selected' : '' ?>>Ditutup</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold small">Pengumuman Global (In-App Alert)</label>
                                <textarea name="setting[global_announcement]" class="form-control" rows="3" placeholder="Masukkan pengumuman global untuk orang tua..."><?= htmlspecialchars($settings['global_announcement'] ?? '') ?></textarea>
                                <small class="text-muted italic">Akan muncul sebagai banner alert penting di dashboard milik orang tua.</small>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="mb-4">
                                <label class="form-label fw-bold small">Teks Footer</label>
                                <textarea name="setting[footer_text]" class="form-control" rows="2"><?= htmlspecialchars($settings['footer_text'] ?? '') ?></textarea>
                            </div>
                            <button type="submit" name="update_settings" class="btn btn-primary px-5 shadow-sm">
                                <i class="bi bi-save me-2"></i>Simpan Seluruh Perubahan
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- 8. TAB SISTEM & LOG -->
        <div class="tab-pane fade" id="tab-system">
            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="pb-card p-4 mb-4 border-top border-5" style="border-color: #ef4444;">
                        <h5 class="fw-bold mb-4 text-dark"><i class="bi bi-key-fill me-2 text-danger"></i>Ganti Password Admin</h5>
                        <form action="" method="POST" name="change_admin_password_form">
                            <div class="mb-3">
                                <label class="form-label small">Password Lama</label>
                                <div class="input-group">
                                    <input type="password" name="current_password" id="currentPasswordField" class="form-control" required>
                                    <button class="btn btn-outline-secondary" type="button" id="toggleCurrentPassword">
                                        <i class="bi bi-eye-slash text-muted"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">Password Baru</label>
                                <div class="input-group">
                                    <input type="password" name="new_password" id="newPasswordField" class="form-control" required>
                                    <button class="btn btn-outline-secondary" type="button" id="toggleNewPassword">
                                        <i class="bi bi-eye-slash text-muted"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="mb-4">
                                <label class="form-label small">Konfirmasi Password Baru</label>
                                <div class="input-group">
                                    <input type="password" name="confirm_new_password" id="confirmNewPasswordField" class="form-control" required>
                                    <button class="btn btn-outline-secondary" type="button" id="toggleConfirmNewPassword">
                                        <i class="bi bi-eye-slash text-muted"></i>
                                    </button>
                                </div>
                            </div>
                            <button type="submit" name="change_admin_password" class="btn btn-primary w-100">
                                <i class="bi bi-save me-2"></i>Ubah Password
                            </button>
                        </form>
                    </div>

                    <div class="pb-card p-4 mb-3 border-top border-5" style="border-color: #f59e0b;">
                        <h5 class="fw-bold mb-3 text-dark"><i class="bi bi-people-gear me-2" style="color: #f59e0b;"></i>Manajemen Role Staf</h5>
                        <p class="text-muted small mb-3">Jalankan migrasi untuk mengaktifkan level <strong>Staf</strong> dan membuat akun demo pertama.</p>
                        <a href="database/migrate2.php" class="btn btn-warning w-100" target="_blank"
                           onclick="return confirm('Jalankan migrasi role staf? Pastikan database sudah di-backup sebelumnya.')">
                            <i class="bi bi-database-gear me-2"></i>Jalankan Migrasi Role Staf
                        </a>
                        <small class="text-muted d-block mt-2">Setelah berhasil, hapus file <code>migrate2.php</code> dari server.</small>
                    </div>

                    <div class="pb-card p-4 border-top border-5" style="border-color: #10b981;">
                        <h5 class="fw-bold mb-4 text-dark"><i class="bi bi-database-down me-2 text-success"></i>Backup Database</h5>
                        <p class="text-muted small">Unduh salinan database SIMBIM saat ini untuk cadangan keamanan.</p>
                        <a href="export_db.php" class="btn btn-success w-100" target="_blank"><i class="bi bi-download me-2"></i>Ekspor Database (.sql)</a>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="pb-card p-4 h-100 border-top border-5" style="border-color: #4A90E2;">
                        <h5 class="fw-bold mb-4 text-dark"><i class="bi bi-journal-text me-2 text-primary"></i>Log Aktivitas Terbaru</h5>
                        <div class="table-responsive">
                            <table class="table table-sm small">
                                <thead class="table-light">
                                    <tr><th>Waktu</th><th>User</th><th>Aktivitas</th></tr>
                                </thead>
                                <tbody>
                                    <?php while($l = $logs->fetch_assoc()): ?>
                                        <tr>
                                            <td><?= date('H:i, d/m', strtotime($l['waktu'])) ?></td>
                                            <td class="fw-bold"><?= $l['username'] ?></td>
                                            <td><?= $l['aktivitas'] ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Edit Pertanyaan -->
<div class="modal fade" id="modalEdit" tabindex="-1" aria-labelledby="modalEditLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalEditLabel"><i class="bi bi-pencil-square me-2"></i>Edit Pertanyaan</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="id_pertanyaan" id="edit_id">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Aspek Kriteria Kecerdasan</label>
                        <select name="id_kriteria" id="edit_kriteria" class="form-select" required>
                            <option value="">-- Pilih Aspek --</option>
                            <?php
                            $list_kriteria->data_seek(0);
                            while ($k = $list_kriteria->fetch_assoc()) { ?>
                                <option value="<?= $k['id_kriteria']; ?>">C<?= $k['id_kriteria']; ?> -
                                    <?= htmlspecialchars($k['nama_kriteria']); ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Teks Pertanyaan</label>
                        <textarea name="teks_pertanyaan" id="edit_teks" class="form-control" rows="4"
                            required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="edit_pertanyaan" class="btn btn-primary shadow-sm">Simpan
                        Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit Kriteria -->
<div class="modal fade" id="modalEditKriteria" tabindex="-1" aria-labelledby="modalEditKriteriaLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalEditKriteriaLabel"><i class="bi bi-gear-fill me-2"></i>Edit Parameter Kriteria</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="id_kriteria" id="kr_edit_id">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nama Kriteria</label>
                        <input type="text" name="nama_kriteria" id="kr_edit_nama" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Bobot Awal</label>
                        <input type="number" step="0.01" name="bobot_awal" id="kr_edit_bobot" class="form-control"
                            required>
                        <small class="text-muted">Bobot ini digunakan sebagai basis normalisasi pada perhitungan
                            Weighted Product.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="edit_kriteria_submit" class="btn btn-primary shadow-sm">Simpan
                        Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit Kelas -->
<div class="modal fade" id="modalEditKelas" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit Kelas</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="id_kelas" id="ek_id">
                    <div class="mb-3">
                        <label class="form-label">Nama Kelas</label>
                        <input type="text" name="nama_kelas" id="ek_nama" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status Operasional</label>
                        <select name="status_kelas" id="ek_status" class="form-select">
                            <option value="Aktif">Aktif</option>
                            <option value="Coming Soon">Coming Soon</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="edit_kelas_submit" class="btn btn-primary">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit User -->
<div class="modal fade" id="modalEditUser" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-gear me-2"></i>Edit Data Pengguna</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="id_user" id="eu_id">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nama Lengkap</label>
                        <input type="text" name="nama_lengkap" id="eu_nama" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Username</label>
                        <input type="text" name="username" id="eu_username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nomor HP</label>
                        <input type="text" name="no_hp" id="eu_nohp" class="form-control" placeholder="0812...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Level Hak Akses</label>
                        <select name="level" id="eu_level" class="form-select" required>
                            <option value="admin">Admin (Akses Penuh)</option>
                            <option value="staf">Staf (Hanya Baca Laporan)</option>
                            <option value="orang_tua">Orang Tua (User Biasa)</option>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-semibold">Ganti Password</label>
                        <input type="password" name="password" class="form-control" placeholder="Kosongkan jika tidak ingin diubah">
                        <small class="text-muted italic">Isi hanya jika ingin mereset password user.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" name="edit_user_submit" class="btn btn-primary shadow-sm">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Matriks Nilai (Core SPK) -->
<div class="modal fade" id="modalMatriks" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-grid-3x3-gap me-2"></i>Matriks Kecocokan: <span id="mtx_nama" class="text-info fw-bold"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="id_kelas" id="mtx_id">
                    <p class="small text-muted mb-4">Tentukan nilai kecocokan kelas ini terhadap kriteria (Skala 1-5). Nilai ini akan dipangkatkan dengan bobot dari orang tua dalam hitung WP.</p>
                    
                    <div id="mtx_fields">
                        <!-- Load via JS -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="update_matriks" class="btn btn-primary">Update Matriks</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Script pendukung untuk mengambil data matriks via AJAX sederhana atau Data Attributes -->
<script>
    // Fungsi helper untuk modal matriks
    const modalMatriks = document.getElementById('modalMatriks');
    modalMatriks.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const id = button.getAttribute('data-id');
        const nama = button.getAttribute('data-nama');
        document.getElementById('mtx_id').value = id;
        document.getElementById('mtx_nama').innerText = nama;

        // Fetch nilai matriks saat ini (Idealnya pake fetch/AJAX, tapi kita buat placeholder dulu)
        // Untuk kemudahan, kita buat field input secara dinamis berdasarkan data yang ada
        document.getElementById('mtx_fields').innerHTML = '<div class="text-center p-3"><div class="spinner-border text-primary"></div></div>';
        
        fetch('includes/get_matriks.php?id_kelas=' + id)
            .then(response => response.text())
            .then(data => {
                document.getElementById('mtx_fields').innerHTML = data;
            });
    });

    const modalEditKelas = document.getElementById('modalEditKelas');
    modalEditKelas.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        document.getElementById('ek_id').value = button.getAttribute('data-id');
        document.getElementById('ek_nama').value = button.getAttribute('data-nama');
        document.getElementById('ek_status').value = button.getAttribute('data-status');
    });
</script>

<footer class="py-5 text-center text-muted border-top bg-light mt-5">
    <div class="container">
        <p class="mb-2">&copy; <?= date('Y'); ?> <strong><?= htmlspecialchars($settings['site_name'] ?? 'SIMBIM Indonesia') ?></strong></p>
        <small>Panel Administrasi Sistem Identifikasi Minat Bakat</small>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const modalEdit = document.getElementById('modalEdit');
    if (modalEdit) {
        modalEdit.addEventListener('show.bs.modal', event => {
            const button = event.relatedTarget;
            const id = button.getAttribute('data-id');
            const teks = button.getAttribute('data-teks');
            const kriteria = button.getAttribute('data-kriteria');

            document.getElementById('edit_id').value = id;
            document.getElementById('edit_teks').value = teks;
            document.getElementById('edit_kriteria').value = kriteria;
        });
    }

    const modalEditKriteria = document.getElementById('modalEditKriteria');
    if (modalEditKriteria) {
        modalEditKriteria.addEventListener('show.bs.modal', event => {
            const button = event.relatedTarget;
            const id = button.getAttribute('data-id');
            const nama = button.getAttribute('data-nama');
            const bobot = button.getAttribute('data-bobot');

            document.getElementById('kr_edit_id').value = id;
            document.getElementById('kr_edit_nama').value = nama;
            document.getElementById('kr_edit_bobot').value = bobot;
        });
    }

    const modalEditUser = document.getElementById('modalEditUser');
    if (modalEditUser) {
        modalEditUser.addEventListener('show.bs.modal', event => {
            const button = event.relatedTarget;
            document.getElementById('eu_id').value = button.getAttribute('data-id');
            document.getElementById('eu_nama').value = button.getAttribute('data-nama');
            document.getElementById('eu_username').value = button.getAttribute('data-username');
            document.getElementById('eu_level').value = button.getAttribute('data-level');
            document.getElementById('eu_nohp').value = button.getAttribute('data-nohp');
        });
    }

    // JavaScript untuk toggle Show/Hide Password di form Ganti Password Admin
    document.getElementById('toggleCurrentPassword').addEventListener('click', function () {
        const passwordField = document.getElementById('currentPasswordField');
        const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordField.setAttribute('type', type);
        this.querySelector('i').classList.toggle('bi-eye');
        this.querySelector('i').classList.toggle('bi-eye-slash');
    });

    document.getElementById('toggleNewPassword').addEventListener('click', function () {
        const passwordField = document.getElementById('newPasswordField');
        const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordField.setAttribute('type', type);
        this.querySelector('i').classList.toggle('bi-eye');
        this.querySelector('i').classList.toggle('bi-eye-slash');
    });

    document.getElementById('toggleConfirmNewPassword').addEventListener('click', function () {
        const passwordField = document.getElementById('confirmNewPasswordField');
        const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordField.setAttribute('type', type);
        this.querySelector('i').classList.toggle('bi-eye');
        this.querySelector('i').classList.toggle('bi-eye-slash');
    });

    // Client-side validation: pastikan password baru dan konfirmasi cocok sebelum submit
    const changePassForm = document.querySelector('form[name="change_admin_password_form"]');
    if (changePassForm) {
        changePassForm.addEventListener('submit', function(event) {
            const newPassword = document.getElementById('newPasswordField').value;
            const confirmNewPassword = document.getElementById('confirmNewPasswordField').value;
            if (newPassword !== confirmNewPassword) {
                alert('Password baru dan konfirmasi password tidak cocok!');
                event.preventDefault();
            }
        });
    }

    // Script untuk menjaga tab 'user' tetap aktif setelah melakukan pencarian
    const userSearchForm = document.getElementById('userSearchForm');
    if (userSearchForm) {
        userSearchForm.addEventListener('submit', function() {
            // Set tab 'user' sebagai tab aktif di localStorage sebelum form disubmit
            localStorage.setItem('activeTab', '#tab-user');
        });
    }
</script>
<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
    // 1. Chart Monthly (Bar)
    const ctxBar = document.getElementById('barMonthly').getContext('2d');
    new Chart(ctxBar, {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_reverse($chart_labels)) ?>,
            datasets: [{
                label: 'Jumlah Anak Ikut Tes',
                data: <?= json_encode(array_reverse($chart_data)) ?>,
                backgroundColor: '#4A90E2',
                borderRadius: 5
            }]
        },
        options: { responsive: true, plugins: { legend: { display: false } } }
    });

    // 2. Chart Interest (Pie)
    const ctxPie = document.getElementById('pieInterest').getContext('2d');
    new Chart(ctxPie, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($pie_labels) ?>,
            datasets: [{
                data: <?= json_encode($pie_data) ?>,
                backgroundColor: ['#4A90E2', '#FF6384', '#FFCE56', '#4BC0C0', '#9966FF']
            }]
        },
        options: { plugins: { legend: { position: 'bottom' } } }
    });

    // 3. Chart Top Kelas (Horizontal Bar)
    const ctxTopKelas = document.getElementById('barTopKelas').getContext('2d');
    new Chart(ctxTopKelas, {
        type: 'bar',
        data: {
            labels: <?= json_encode($top_kelas_labels) ?>,
            datasets: [{
                label: 'Jumlah Jadi Rekomendasi',
                data: <?= json_encode($top_kelas_data) ?>,
                backgroundColor: ['#10b981','#3b82f6','#f59e0b','#8b5cf6','#ef4444'],
                borderRadius: 6
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } }
        }
    });

    // Script untuk menjaga tab tetap aktif setelah reload (Optional but recommended)
    document.addEventListener("DOMContentLoaded", function(){
        var activeTab = localStorage.getItem('activeTab');
        if(activeTab){
            var tabEl = document.querySelector('button[data-bs-target="' + activeTab + '"]');
            if(tabEl) bootstrap.Tab.getOrCreateInstance(tabEl).show();
        }
        document.querySelectorAll('button[data-bs-toggle="tab"]').forEach(t => {
            t.addEventListener('shown.bs.tab', e => localStorage.setItem('activeTab', e.target.getAttribute('data-bs-target')));
        });
    });
</script>

<style>
@media print {
    .d-print-none, .navbar, .footer, .nav-pills { display: none !important; }
    .container { width: 100% !important; max-width: 100% !important; margin: 0 !important; padding: 0 !important; }
    .pb-card { box-shadow: none !important; border: none !important; }
    .printable-area { display: block !important; }
}
</style>
</body>

</html>