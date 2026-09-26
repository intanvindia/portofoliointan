<?php
ob_start();
require_once 'config/koneksi.php';
require_once __DIR__ . '/assets/dompdf/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

session_start();

// Proteksi: Hanya staf atau admin yang bisa cetak
if (!isset($_SESSION['id_user']) || !in_array($_SESSION['level'], ['staf', 'admin'])) {
    die("Akses ditolak.");
}

// Fungsi helper untuk mencatat log aktivitas
function createLog($conn, $aktivitas) {
    if (isset($_SESSION['id_user'])) {
        $id_user_log = $_SESSION['id_user'];
        $stmt = $conn->prepare("INSERT INTO logs (id_user, aktivitas) VALUES (?, ?)");
        $stmt->bind_param("is", $id_user_log, $aktivitas);
        $stmt->execute();
        $stmt->close();
    }
}

// Ambil Pengaturan Global untuk logo dan nama situs
$settings = [];
$res_set = $conn->query("SELECT * FROM pengaturan");
while($s = $res_set->fetch_assoc()) {
    $settings[$s['nama_key']] = $s['nilai_value'];
}

$id_user = isset($_GET['id_user']) ? intval($_GET['id_user']) : 0;
if ($id_user <= 0) die("ID User tidak valid.");

// 1. Ambil data user 
$stmt_user = $conn->prepare("SELECT username, nama_lengkap FROM user WHERE id_user = ? AND level = 'orang_tua'");
$stmt_user->bind_param("i", $id_user);
$stmt_user->execute();
$res_user = $stmt_user->get_result();
if ($res_user->num_rows == 0) die("User tidak ditemukan.");
$info_user = $res_user->fetch_assoc();
$stmt_user->close();

// Catat log aktivitas
createLog($conn, "Mencetak kartu pendaftaran untuk: " . htmlspecialchars($info_user['username']));

// 2. Ambil data anak-anaknya
$stmt_anak = $conn->prepare("SELECT nama_anak, usia FROM anak WHERE id_user = ?");
$stmt_anak->bind_param("i", $id_user);
$stmt_anak->execute();
$res_anak = $stmt_anak->get_result();

// 3. Generate QR Code dan Logo (disematkan sebagai Base64)
$login_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF']) . "/login.php";
$qr_code_api_url = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . urlencode($login_url);
$qr_code_data = @file_get_contents($qr_code_api_url);
$qr_code_base64 = 'data:image/png;base64,' . base64_encode($qr_code_data);

$logo_html = '';
if (!empty($settings['site_logo'])) {
    $logo_path = $settings['site_logo'];
    if (file_exists($logo_path)) {
        $logo_data = file_get_contents($logo_path);
        $logo_base64 = 'data:image/' . pathinfo($logo_path, PATHINFO_EXTENSION) . ';base64,' . base64_encode($logo_data);
        $logo_html = '<img src="' . $logo_base64 . '" style="max-height: 40px; margin-right: 10px;">';
    }
}

// 4. Konstruksi HTML baru 
$html = '
<!DOCTYPE html>
<html>
<head>
<style>
    @page { 
        margin: 25px; /* Margin diperbesar agar aman dari tepi kertas */
    }
    body { 
        font-family: "Helvetica", Arial, sans-serif; 
        color: #1e293b; 
        margin: 0; 
        padding: 0;
    }
    .card-container {
        border: 2px solid #0d6efd;
        border-radius: 12px;
        background-color: #f8fafc;
        padding: 15px 20px;
        /* Dihapus width: 100% agar kotak mengikuti sisa margin secara alami */
    }
    .header-table {
        width: 100%;
        border-bottom: 2px solid #e2e8f0;
        padding-bottom: 10px;
        margin-bottom: 15px;
    }
    .site-name {
        font-size: 18px;
        font-weight: bold;
        color: #0d6efd;
        text-transform: uppercase;
    }
    .subtitle {
        font-size: 11px;
        color: #64748b;
        margin-top: 3px;
    }
    .content-table {
        width: 100%;
    }
    .label {
        font-size: 10px;
        color: #64748b;
        text-transform: uppercase;
        font-weight: bold;
        margin-bottom: 3px;
    }
    .value {
        font-size: 16px;
        font-weight: bold;
        color: #0f172a;
        margin-bottom: 12px;
    }
    .qr-container {
        text-align: center;
        background: #ffffff;
        border: 1px solid #cbd5e1;
        padding: 5px;
        border-radius: 8px;
        width: 90px;
        margin: 0 auto;
    }
    .qr-container img {
        width: 90px;
        height: 90px;
    }
    .qr-text {
        font-size: 9px;
        color: #475569;
        margin-top: 5px;
        font-weight: bold;
    }
    .footer-section {
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px dashed #cbd5e1;
    }
    .child-badge {
        display: inline-block;
        background-color: #dbeafe;
        color: #1e40af;
        padding: 4px 10px;
        border-radius: 5px;
        font-size: 12px;
        font-weight: bold;
        margin-right: 5px;
        margin-bottom: 5px;
        border: 1px solid #bfdbfe;
    }
</style>
</head>
<body>
    <div class="card-container">
        <table class="header-table" cellspacing="0" cellpadding="0">
            <tr>
                ' . (!empty($logo_html) ? '<td width="55" valign="middle">' . $logo_html . '</td>' : '') . '
                <td valign="middle">
                    <div class="site-name">' . htmlspecialchars($settings['site_name'] ?? 'SIMBIM INDONESIA') . '</div>
                    <div class="subtitle">KARTU AKSES ASESMEN ORANG TUA</div>
                </td>
            </tr>
        </table>

        <table class="content-table" cellspacing="0" cellpadding="0">
            <tr>
                <td width="70%" valign="top">
                    <div class="label">Nama Lengkap Wali</div>
                    <div class="value">' . htmlspecialchars($info_user['nama_lengkap']) . '</div>
                    
                    <div class="label">Username (Untuk Login)</div>
                    <div class="value">' . htmlspecialchars($info_user['username']) . '</div>
                    
                    <div class="label">Password Akun</div>
                    <div class="value" style="font-size: 12px; font-weight: normal; font-style: italic;">(Bersifat Rahasia & Tersimpan Aman)</div>
                </td>
                <td width="30%" valign="top" align="center">
                    <div class="qr-container">
                        <img src="' . $qr_code_base64 . '" alt="QR Code">
                        <div class="qr-text">SCAN LOGIN</div>
                    </div>
                </td>
            </tr>
        </table>

        <div class="footer-section">
            <div class="label" style="margin-bottom: 8px;">Daftar Anak Terdaftar:</div>
            ';
            if ($res_anak->num_rows > 0) {
                while ($anak = $res_anak->fetch_assoc()) {
                    $html .= '<span class="child-badge">' . htmlspecialchars($anak['nama_anak']) . ' (' . $anak['usia'] . ' thn)</span>';
                }
            } else {
                $html .= '<span style="font-size: 12px; color: #ef4444; font-style: italic;">Belum ada data anak.</span>';
            }
            $html .= '
        </div>
    </div>
</body>
</html>';

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);

// Tetap gunakan ukuran A6 Landscape 
$dompdf->setPaper('A6', 'landscape'); 
$dompdf->render();

$filename = "Kartu_Akses_" . str_replace(' ', '_', $info_user['nama_lengkap']) . ".pdf";
$dompdf->stream($filename, ["Attachment" => false]);
ob_end_flush();
?>