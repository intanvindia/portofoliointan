<?php
ob_start(); // Mencegah output sebelum PDF di-render
require_once 'config/koneksi.php';
require_once __DIR__ . '/assets/dompdf/vendor/autoload.php'; 

use Dompdf\Dompdf;
use Dompdf\Options;

session_start();

// Proteksi Halaman
if (!isset($_SESSION['id_user'])) {
    die("Akses ditolak.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id_asesmen'])) {
    die("Data tidak valid.");
}

$id_asesmen = intval($_POST['id_asesmen']);
$chart_image = isset($_POST['chart_image']) ? $_POST['chart_image'] : '';

// Validasi: Pastikan gambar grafik tidak kosong atau tidak valid
if (empty($chart_image) || $chart_image == 'data:,' || strlen($chart_image) < 100) {
    die("<div style='padding:20px; border:1px solid red; color:red; font-family:sans-serif;'>
            <h4>Gagal Mencetak PDF</h4>
            <p>Gambar grafik tidak terdeteksi. Pastikan grafik muncul sepenuhnya di layar sebelum menekan tombol cetak.</p>
            <a href='hitung_wp.php?id_asesmen=$id_asesmen'>Kembali</a>
         </div>");
}

// 1. Ambil Info Anak
$stmt_anak = $conn->prepare("SELECT a.nama_anak, a.usia, asm.tgl_asesmen FROM asesmen asm 
                             JOIN anak a ON asm.id_anak = a.id_anak 
                             WHERE asm.id_asesmen = ?");
$stmt_anak->bind_param("i", $id_asesmen);
$stmt_anak->execute();
$res_anak = $stmt_anak->get_result();

if (!$res_anak || $res_anak->num_rows == 0) {
    if ($stmt_anak) $stmt_anak->close();
    die("Data asesmen tidak ditemukan.");
}
$info_anak = $res_anak->fetch_assoc();
$stmt_anak->close();

// Ambil Pengaturan Global untuk logo dan nama situs
$settings = [];
$res_set = $conn->query("SELECT * FROM pengaturan");
while($s = $res_set->fetch_assoc()) {
    $settings[$s['nama_key']] = $s['nilai_value'];
}

// 2. Jalankan Logika WP (Sama seperti di hitung_wp.php)
// STEP 1: Bobot W
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
if ($total_skor_anak <= 0) $total_skor_anak = 1;

// STEP 2: Max Kriteria
$sql_max = "SELECT id_kriteria, MAX(nilai_default) as max_val FROM nilai_kriteria_kelas GROUP BY id_kriteria";
$res_max = $conn->query($sql_max);
$max_kriteria = [];
while($row = $res_max->fetch_assoc()) {
    $max_kriteria[$row['id_kriteria']] = $row['max_val'];
}

// STEP 3: Vektor S
// Mengoptimalkan dengan satu JOIN query untuk mengambil semua data kelas dan nilai kriteria
$sql_kelas_data = "SELECT
                        kb.id_kelas,
                        kb.nama_kelas,
                        kb.status_kelas,
                        nkk.id_kriteria,
                        nkk.nilai_default
                    FROM
                        kelas_bimbel kb
                    JOIN
                        nilai_kriteria_kelas nkk ON kb.id_kelas = nkk.id_kelas
                    WHERE kb.deleted_at IS NULL -- Ambil semua kelas yg tidak dihapus
                    ORDER BY
                        kb.id_kelas, nkk.id_kriteria";
$res_kelas_data = $conn->query($sql_kelas_data);

// Kelompokkan data yang diambil berdasarkan id_kelas
$kelas_grouped_data = [];
while ($row = $res_kelas_data->fetch_assoc()) {
    $id_kelas = $row['id_kelas'];
    if (!isset($kelas_grouped_data[$id_kelas])) {
        $kelas_grouped_data[$id_kelas] = [
            'nama_kelas' => $row['nama_kelas'],
            'status_kelas' => $row['status_kelas'],
            'kriteria_nilai' => []
        ];
    }
    $kelas_grouped_data[$id_kelas]['kriteria_nilai'][] = [
        'id_kriteria' => $row['id_kriteria'],
        'nilai_default' => $row['nilai_default']
    ];
}

$vektor_s_aktif = [];
$vektor_s_coming = [];
$total_s_aktif = 0;
$total_s_coming = 0;

foreach ($kelas_grouped_data as $id_kelas => $kelas_info) {
    $S = 1; // Inisialisasi awal Vektor S untuk kelas ini
    foreach ($kelas_info['kriteria_nilai'] as $matriks) {
        $id_kr = $matriks['id_kriteria'];
        $max_val = isset($max_kriteria[$id_kr]) ? $max_kriteria[$id_kr] : 5; // Ambil max_val dari hasil STEP 2
        $x_ij = $matriks['nilai_default'] / $max_val;
        $w_j = (isset($skor_anak[$id_kr]) ? $skor_anak[$id_kr] : 1) / $total_skor_anak; // Ambil bobot dari hasil STEP 1
        $S *= pow($x_ij, $w_j); // Rumus inti WP
    }
    if ($kelas_info['status_kelas'] == 'Aktif') {
        $vektor_s_aktif[$id_kelas] = ['nama' => $kelas_info['nama_kelas'], 'nilai_s' => $S];
        $total_s_aktif += $S;
    } else {
        $vektor_s_coming[$id_kelas] = ['nama' => $kelas_info['nama_kelas'], 'nilai_s' => $S];
        $total_s_coming += $S;
    }
}

// STEP 4: Hitung Vektor V (Normalisasi Vektor S untuk Ranking)
$ranking_aktif = [];
$ranking_coming = [];
$highest_name = '';
$highest_val = 0;
foreach ($vektor_s_aktif as $data) {
    $ranking_aktif[] = [
        'nama' => $data['nama'],
        'v' => ($total_s_aktif > 0) ? ($data['nilai_s'] / $total_s_aktif) : 0
    ];
}
foreach ($vektor_s_coming as $data) {
    $ranking_coming[] = [
        'nama' => $data['nama'],
        'v' => ($total_s_coming > 0) ? ($data['nilai_s'] / $total_s_coming) : 0
    ];
}
// Urutkan hasil ranking berdasarkan nilai tertinggi
usort($ranking_aktif, function($a, $b) { return $b['v'] <=> $a['v']; });
usort($ranking_coming, function($a, $b) { return $b['v'] <=> $a['v']; });

// Fetch all criteria names once for efficiency
$kriteria_names = [];
$res_kriteria_names = $conn->query("SELECT id_kriteria, nama_kriteria FROM kriteria");
while ($row = $res_kriteria_names->fetch_assoc()) {
    $kriteria_names[$row['id_kriteria']] = $row['nama_kriteria'];
}

foreach ($skor_anak as $id_kriteria => $skor) {
    if ($skor > $highest_val) {
        $highest_val = $skor;
        $highest_name = $kriteria_names[$id_kriteria] ?? 'Tidak Diketahui';
    }
}

// Prepare logo HTML
$logo_html = '';
if (!empty($settings['site_logo']) && filter_var($settings['site_logo'], FILTER_VALIDATE_URL)) {
    $logo_data = @file_get_contents($settings['site_logo']);
    if ($logo_data) {
        $logo_base64 = 'data:image/' . pathinfo($settings['site_logo'], PATHINFO_EXTENSION) . ';base64,' . base64_encode($logo_data);
        $logo_html = '<img src="' . $logo_base64 . '" style="max-height: 50px;">';
    }
}

// 3. Konstruksi HTML untuk PDF
$html = '
<!DOCTYPE html>
<html>
<head>
    <style>
        @page {
            margin: 25px 40px; /* top/bottom, left/right */
        }
        body { 
            font-family: "Helvetica", "Arial", sans-serif; 
            color: #333; 
            line-height: 1.4; 
        }
        .header-table {
            width: 100%;
            border-bottom: 2px solid #0d6efd;
            padding-bottom: 8px;
            margin-bottom: 20px;
        }
        .site-logo {
            width: 70px;
            text-align: left;
        }
        .report-title h2 {
            margin: 0;
            font-size: 18px;
            font-weight: bold;
            color: #0d6efd;
            text-transform: uppercase;
            text-align: right;
        }
        .report-title p {
            margin: 0;
            font-size: 11px;
            color: #6c757d;
            text-align: right;
        }
        .info-table { 
            width: 100%; 
            margin-bottom: 20px; 
            border-collapse: collapse; 
            font-size: 11px;
        }
        .info-table td { 
            padding: 6px; 
            border: 1px solid #dee2e6;
        }
        .info-table td:first-child {
            background-color: #f8f9fa;
            font-weight: bold;
            width: 22%;
        }
        .chart-container { 
            text-align: center; 
            margin-bottom: 20px; 
            page-break-inside: avoid;
        }
        .chart-image { 
            max-width: 100%;
            height: auto; 
        }
        .section-title {
            font-weight: bold; 
            font-size: 14px; 
            margin-bottom: 8px;
            color: #0d6efd;
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: 5px;
        }
        .table-result { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 8px; 
        }
        .table-result th { 
            background-color: #0d6efd; 
            color: white; 
            padding: 8px; 
            font-size: 11px; 
            text-align: left; 
        }
        .table-result td { 
            border: 1px solid #dee2e6; 
            padding: 6px; 
            font-size: 11px; 
        }
        .footer { 
            position: fixed; 
            bottom: -20px;
            left: 0px; 
            right: 0px; 
            height: 50px;
            font-size: 10px; 
            text-align: center; 
            color: #999; 
        }
        .pagenum:before {
            content: counter(page);
        }
        .highlight { 
            background-color: #d1e7dd; /* Bootstrap success-subtle */
            font-weight: bold; 
        }
        .note-box {
            margin-top: 15px; 
            font-size: 11px; 
            background: #f8f9fa; 
            padding: 10px; 
            border-radius: 5px; 
            border-left: 4px solid #0dcaf0; /* Bootstrap info */
            page-break-inside: avoid;
        }
    </style>
</head>
<body>
    <div class="footer">
        Dokumen ini dibuat otomatis oleh Sistem SIMBIM pada ' . date('d/m/Y H:i') . ' | Halaman <span class="pagenum"></span>
    </div>

    <table class="header-table">
        <tr>
            <td class="site-logo">' . $logo_html . '</td>
            <td class="report-title">
                <h2>Laporan Hasil Rekomendasi</h2>
                <p>' . htmlspecialchars($settings['site_name'] ?? 'SIMBIM Indonesia') . '</p>
            </td>
        </tr>
    </table>

    <table class="info-table">
        <tr>
            <td>Nama Ananda</td>
            <td>' . htmlspecialchars($info_anak['nama_anak']) . '</td>
            <td>Tanggal Tes</td>
            <td>' . date('d F Y', strtotime($info_anak['tgl_asesmen'])) . '</td>
        </tr>
        <tr>
            <td>Usia</td>
            <td>' . $info_anak['usia'] . ' Tahun</td>
            <td>Metode Analisis</td>
            <td>Weighted Product (WP)</td>
        </tr>
    </table>

    <div class="section-title" style="margin-top: 20px;">Visualisasi Potensi Kecerdasan</div>
    <div class="chart-container">
        <img src="' . $chart_image . '" class="chart-image">
    </div>

    <div class="section-title">Hasil Peringkat Rekomendasi</div>
    <table class="table-result">
        <thead>
            <tr>
                <th width="15%">Peringkat</th>
                <th width="55%">Nama Program Bimbel</th>
                <th width="30%">Nilai Preferensi</th>
            </tr>
        </thead>
        <tbody>';

$no = 1;
foreach($ranking_aktif as $r) {
    $class = ($no == 1) ? 'class="highlight"' : '';
    $html .= '<tr ' . $class . '>
                <td align="center">#' . $no . '</td>
                <td>' . htmlspecialchars($r['nama']) . ($no == 1 ? ' (Rekomendasi Utama)' : '') . '</td>
                <td>' . round($r['v'] * 100, 2) . '%</td>
              </tr>';
    $no++;
}

$html .= '
        </tbody>
    </table>';

if (!empty($ranking_coming)) {
    $html .= '
    <div style="margin-top: 20px; page-break-inside: avoid;">
        <div class="section-title">Potensi Kelas Ekstensi (Coming Soon)</div>
        <table class="table-result">
            <thead>
                <tr><th width="15%">Peringkat</th><th width="55%">Nama Program Bimbel</th><th width="30%">Nilai Preferensi</th></tr>
            </thead>
            <tbody>';
    $no_soon = 1;
    foreach($ranking_coming as $rc) {
        $html .= '<tr><td align="center">#' . $no_soon++ . '</td><td>' . htmlspecialchars($rc['nama']) . '</td><td>' . round($rc['v'] * 100, 2) . '%</td></tr>';
    }
    $html .= '
            </tbody>
        </table>
    </div>';
}

$html .= '
    <div class="note-box">
        <strong>Catatan:</strong> Hasil ini merupakan analisis kecenderungan berdasarkan kuesioner minat dan bakat yang diisi oleh orang tua. Rekomendasi ini bersifat objektif untuk membantu mengoptimalkan potensi Ananda.
    </div>
</body>
</html>';

// 4. Inisialisasi Dompdf
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true); // Penting agar gambar base64 bisa dirender

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);

// Set Ukuran Kertas
$dompdf->setPaper('A4', 'portrait');

// Render HTML ke PDF
$dompdf->render();

// Output ke Browser
$filename = "Hasil_SIMBIM_" . str_replace(' ', '_', $info_anak['nama_anak']) . ".pdf";
$dompdf->stream($filename, ["Attachment" => false]); // false = preview di browser, true = langsung download
?>