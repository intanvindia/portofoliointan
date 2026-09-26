<?php
require_once 'config/koneksi.php';
session_start();

if (!isset($_SESSION['id_user']) || $_SESSION['level'] != 'admin') {
    die("Akses ditolak.");
}

$tables = array();
$result = $conn->query("SHOW TABLES");
while ($row = $result->fetch_row()) {
    $tables[] = $row[0];
}

$return = "-- SIMBIM Database Backup\n";
$return .= "-- Generatad at: " . date('Y-m-d H:i:s') . "\n\n";

foreach ($tables as $table) {
    $result = $conn->query("SELECT * FROM $table");
    $num_fields = $result->field_count;

    $return .= "DROP TABLE IF EXISTS $table;";
    $row2 = $conn->query("SHOW CREATE TABLE $table")->fetch_row();
    $return .= "\n\n" . $row2[1] . ";\n\n";

    for ($i = 0; $i < $num_fields; $i++) {
        while ($row = $result->fetch_row()) {
            $return .= "INSERT INTO $table VALUES(";
            for ($j = 0; $j < $num_fields; $j++) {
                if (isset($row[$j])) {
                    // Escape string values
                    $row[$j] = addslashes($row[$j]);
                    $row[$j] = str_replace("\n", "\\n", $row[$j]);
                    $return .= '"' . $row[$j] . '"';
                } else {
                    // Use NULL for null values
                    $return .= 'NULL';
                }
                if ($j < ($num_fields - 1)) { $return .= ','; }
            }
            $return .= ");\n";
        }
    }
    $return .= "\n\n\n";
}

// Simpan file
$filename = 'db-backup-simbim-' . date('Ymd-His') . '.sql';
header('Content-Type: application/octet-stream');
header("Content-Transfer-Encoding: Binary");
header("Content-disposition: attachment; filename=\"" . $filename . "\"");
echo $return;
exit;