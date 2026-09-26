<?php
require_once 'config/koneksi.php';
session_start();

// Validasi Guard: Hanya Admin & Staf yang boleh download rekap excel
if (!isset($_SESSION['id_user']) || !in_array($_SESSION['level'], ['admin', 'staf'])) {
    die("Akses ditolak.");
}

// Ambil parameter filter
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$usia_min = isset($_GET['usia_min']) && is_numeric($_GET['usia_min']) ? intval($_GET['usia_min']) : '';
$usia_max = isset($_GET['usia_max']) && is_numeric($_GET['usia_max']) ? intval($_GET['usia_max']) : '';
$id_kelas_filter = isset($_GET['id_kelas']) ? intval($_GET['id_kelas']) : 0;

// Set Headers for Excel Download
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=Rekap_Laporan_SIMBIM_" . $start_date . "_to_" . $end_date . ".xls");
header("Pragma: no-cache");
header("Expires: 0");

// 1. Ambil semua Kriteria
$res_kriteria = $conn->query("SELECT id_kriteria, nama_kriteria FROM kriteria ORDER BY id_kriteria ASC");
$kriteria_list = [];
while ($kr = $res_kriteria->fetch_assoc()) {
    $kriteria_list[$kr['id_kriteria']] = $kr['nama_kriteria'];
}

// 2. Ambil Nilai Maksimal per Kriteria (untuk WP)
$res_max = $conn->query("SELECT id_kriteria, MAX(nilai_default) as max_val FROM nilai_kriteria_kelas GROUP BY id_kriteria");
$max_kriteria = [];
while ($row = $res_max->fetch_assoc()) {
    $max_kriteria[$row['id_kriteria']] = $row['max_val'];
}

// 3. Ambil data kelas aktif dan matriks kriteria
$res_kelas_data = $conn->query("SELECT kb.id_kelas, kb.nama_kelas, nkk.id_kriteria, nkk.nilai_default
                                FROM kelas_bimbel kb
                                JOIN nilai_kriteria_kelas nkk ON kb.id_kelas = nkk.id_kelas
                                WHERE kb.status_kelas = 'Aktif' AND kb.deleted_at IS NULL
                                ORDER BY kb.id_kelas, nkk.id_kriteria");
$kelas_grouped_data = [];
while ($row = $res_kelas_data->fetch_assoc()) {
    $id_kelas = $row['id_kelas'];
    if (!isset($kelas_grouped_data[$id_kelas])) {
        $kelas_grouped_data[$id_kelas] = [
            'nama_kelas' => $row['nama_kelas'],
            'kriteria_nilai' => []
        ];
    }
    $kelas_grouped_data[$id_kelas]['kriteria_nilai'][] = [
        'id_kriteria' => $row['id_kriteria'],
        'nilai_default' => $row['nilai_default']
    ];
}

// 4. Ambil rata-rata skor per kriteria untuk setiap asesmen dalam rentang tanggal
$sql_scores = "SELECT da.id_asesmen, p.id_kriteria, AVG(da.nilai_input) as rata_nilai 
               FROM detail_asesmen da
               JOIN pertanyaan p ON da.id_pertanyaan = p.id_pertanyaan
               JOIN asesmen asm ON da.id_asesmen = asm.id_asesmen
               WHERE asm.tgl_asesmen BETWEEN ? AND ?
               GROUP BY da.id_asesmen, p.id_kriteria";
$stmt_scores = $conn->prepare($sql_scores);
$stmt_scores->bind_param("ss", $start_date, $end_date);
$stmt_scores->execute();
$res_scores = $stmt_scores->get_result();

$scores = [];
while ($row = $res_scores->fetch_assoc()) {
    $scores[$row['id_asesmen']][$row['id_kriteria']] = floatval($row['rata_nilai']);
}
$stmt_scores->close();

// 5. Ambil data Rekap Asesmen
$sql_rekap = "SELECT asm.id_asesmen, a.nama_anak, a.usia, asm.tgl_asesmen, u.nama_lengkap as wali 
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
?>

<!-- HTML table formatted for Excel -->
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <style>
        .title {
            font-family: Arial, sans-serif;
            font-size: 16px;
            font-weight: bold;
            text-align: center;
        }
        .header {
            background-color: #4f81bd;
            color: #ffffff;
            font-family: Arial, sans-serif;
            font-size: 11px;
            font-weight: bold;
            text-align: center;
            border: 0.5pt solid #000000;
        }
        .data-cell {
            font-family: Arial, sans-serif;
            font-size: 10px;
            border: 0.5pt solid #d9d9d9;
        }
        .text-center {
            text-align: center;
        }
        .highlight {
            background-color: #d7e4bc;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <table>
        <tr>
            <td colspan="12" class="title">REKAPITULASI LAPORAN ASESMEN MINAT BAKAT (SIMBIM)</td>
        </tr>
        <tr>
            <td colspan="12" class="title" style="font-size: 12px; font-weight: normal;">Periode: <?php echo date('d M Y', strtotime($start_date)); ?> s/d <?php echo date('d M Y', strtotime($end_date)); ?></td>
        </tr>
        <tr>
            <td colspan="12"></td>
        </tr>
        <thead>
            <tr>
                <th class="header">No</th>
                <th class="header">Tanggal Asesmen</th>
                <th class="header">Nama Anak</th>
                <th class="header">Usia</th>
                <th class="header">Wali (User)</th>
                <?php foreach ($kriteria_list as $id_k => $nama_k): ?>
                    <th class="header"><?php echo htmlspecialchars($nama_k); ?></th>
                <?php endforeach; ?>
                <th class="header">Rekomendasi Utama</th>
                <th class="header">Nilai Preferensi (%)</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $no = 1;
            if ($result_rekap->num_rows > 0) {
                while ($r = $result_rekap->fetch_assoc()) {
                    $id_asm = $r['id_asesmen'];
                    
                    // Hitung WP untuk anak ini
                    $skor_anak = isset($scores[$id_asm]) ? $scores[$id_asm] : [];
                    $total_skor_anak = array_sum($skor_anak);
                    if ($total_skor_anak <= 0) $total_skor_anak = 1;
                    
                    $vektor_s = [];
                    $total_s = 0;
                    
                    foreach ($kelas_grouped_data as $id_kelas => $kelas_info) {
                        $S = 1;
                        foreach ($kelas_info['kriteria_nilai'] as $matriks) {
                            $id_kr = $matriks['id_kriteria'];
                            $max_val = isset($max_kriteria[$id_kr]) ? $max_kriteria[$id_kr] : 5;
                            $x_ij = $matriks['nilai_default'] / $max_val;
                            $skor_k = isset($skor_anak[$id_kr]) ? $skor_anak[$id_kr] : 1;
                            $w_j = $skor_k / $total_skor_anak;
                            $S *= pow($x_ij, $w_j);
                        }
                        $vektor_s[$id_kelas] = ['nama' => $kelas_info['nama_kelas'], 'nilai_s' => $S];
                        $total_s += $S;
                    }
                    
                    // Cari rekomendasi utama
                    $top_class = "-";
                    $top_v = 0;
                    foreach ($vektor_s as $id_kelas => $data) {
                        $v = ($total_s > 0) ? ($data['nilai_s'] / $total_s) : 0;
                        if ($v > $top_v) {
                            $top_v = $v;
                            $top_class = $data['nama'];
                        }
                    }
                    ?>
                    <tr>
                        <td class="data-cell text-center"><?php echo $no++; ?></td>
                        <td class="data-cell text-center"><?php echo date('d/m/Y', strtotime($r['tgl_asesmen'])); ?></td>
                        <td class="data-cell"><strong><?php echo htmlspecialchars($r['nama_anak']); ?></strong></td>
                        <td class="data-cell text-center"><?php echo $r['usia']; ?> Thn</td>
                        <td class="data-cell"><?php echo htmlspecialchars($r['wali']); ?></td>
                        
                        <?php foreach ($kriteria_list as $id_k => $nama_k): ?>
                            <td class="data-cell text-center">
                                <?php 
                                $s = isset($skor_anak[$id_k]) ? round($skor_anak[$id_k], 2) : 0; 
                                echo $s;
                                ?>
                            </td>
                        <?php endforeach; ?>
                        
                        <td class="data-cell highlight"><?php echo htmlspecialchars($top_class); ?></td>
                        <td class="data-cell text-center highlight"><?php echo round($top_v * 100, 2); ?>%</td>
                    </tr>
                    <?php
                }
            } else {
                ?>
                <tr>
                    <td colspan="12" class="data-cell text-center">Tidak ada data asesmen dalam rentang tanggal ini.</td>
                </tr>
                <?php
            }
            ?>
        </tbody>
    </table>
</body>
</html>
<?php
$stmt_rekap->close();
?>
