<?php
// Panggil koneksi database dan aktifkan session
require_once 'config/koneksi.php';
session_start();

// Ambil Pengaturan Global
$settings = [];
$res_set = $conn->query("SELECT * FROM pengaturan");
while($s = $res_set->fetch_assoc()) {
    $settings[$s['nama_key']] = $s['nilai_value'];
}

// Cek apakah pendaftaran asesmen ditutup
if (($settings['registration_open'] ?? '1') == '0') {
    header("Location: dashboard.php"); // Atau halaman lain yang sesuai
    exit;
}

// Proteksi Halaman: Hanya orang tua yang sudah login yang bisa akses
if (!isset($_SESSION['id_user']) || !in_array($_SESSION['level'], ['orang_tua', 'admin', 'staf'])) {
    header("Location: login.php");
    exit;
}

// Flag untuk akun demo orang tua
$is_demo_account = in_array($_SESSION['level'], ['admin', 'staf']);

// Ambil data kriteria untuk pengelompokan soal
$sql_kriteria = "SELECT * FROM kriteria ORDER BY id_kriteria ASC";
$result_kriteria = $conn->query($sql_kriteria);

// Ambil daftar anak milik user yang login
if ($_SESSION['level'] == 'orang_tua') {
    $id_user = $_SESSION['id_user'];
    $stmt_anak = $conn->prepare("SELECT id_anak, nama_anak, usia FROM anak WHERE id_user = ?");
    $stmt_anak->bind_param("i", $id_user);
    $stmt_anak->execute();
    $result_anak = $stmt_anak->get_result();
    if ($result_anak->num_rows == 0) {
        die("<div class='container text-center py-5'><div class='alert alert-warning'>Anda belum memiliki data anak. Silakan <a href='dashboard.php'>kembali ke dashboard</a> dan tambahkan data anak terlebih dahulu.</div></div>");
    }
} else { // Untuk Admin dan Staf, ambil semua anak
    $result_anak = $conn->query("SELECT a.id_anak, a.nama_anak, a.usia, u.nama_lengkap as wali 
                                 FROM anak a 
                                 JOIN user u ON a.id_user = u.id_user 
                                 ORDER BY u.nama_lengkap, a.nama_anak");
    if ($result_anak->num_rows == 0) {
        die("<div class='container text-center py-5'><div class='alert alert-info'>Belum ada data anak yang terdaftar di sistem.</div></div>");
    }
}

$page_title = "Asesmen Potensi Ananda - SIMBIM";
include 'includes/header.php';
?>

<div class="container py-4">
    <div class="pb-card p-4 p-md-5 mb-5 shadow-sm">
        <div class="text-center">
            <div class="bg-primary text-white d-inline-block p-3 rounded-circle mb-3 shadow">
                <i class="bi bi-person-bounding-box fs-2"></i>
            </div>
            <h2 class="fw-bold text-dark">Asesmen Potensi Ananda</h2>
            <p class="text-muted lead">Bantu kami memahami keunikan buah hati Anda untuk rekomendasi kelas yang tepat.</p>
            <hr class="my-4">
            <p class="small text-muted mb-0">Berikan penilaian skor 1 (Sangat Tidak Sesuai) sampai 5 (Sangat Sesuai) berdasarkan kebiasaan sehari-hari anak.</p>
        </div>
    </div>

    <!-- Demo Auto-Fill Buttons -->
    <?php if ($is_demo_account): ?>
    <div class="pb-card p-3 mb-4 shadow-sm bg-light-subtle">
        <h5 class="fw-bold text-dark"><i class="bi bi-magic me-2 text-primary"></i>Demo Auto-Fill</h5>
        <p class="small text-muted mb-2">Klik tombol di bawah untuk simulasi pengisian kuesioner secara otomatis.</p>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="fillForm('logika')">Simulasi Dominan Logika (C1)</button>
            <button type="button" class="btn btn-sm btn-outline-success" onclick="fillForm('seni')">Simulasi Dominan Auditori (C3)</button>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="fillForm('ekstrem')">Simulasi Jawaban Ekstrem (Nilai 5)</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="fillForm('reset')">Reset Pilihan</button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Progress Bar (Sticky di bawah Navbar) -->
    <div class="sticky-top bg-white py-3 mb-4 border-bottom shadow-sm d-print-none" style="z-index: 1010; top: 56px;">
        <div class="progress mx-auto" style="height: 20px; max-width: 800px; border-radius: 10px;">
            <div id="asesmenProgress" class="progress-bar progress-bar-striped progress-bar-animated fw-bold" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
        </div>
    </div>

    <form id="formAsesmen" action="includes/proses_simpan.php" method="POST" class="pb-card p-4 mb-5 needs-validation" novalidate>
        <div class="row g-3 mb-5">
            <div class="col-md-12">
                <label for="id_anak" class="form-label fw-bold">Pilih Anak yang Akan Mengikuti Tes:</label>
                <select name="id_anak" id="id_anak" class="form-select form-select-lg" required>
                    <option value="">-- Pilih Nama Anak --</option>
                    <?php while($anak = $result_anak->fetch_assoc()): ?>
                        <?php if ($_SESSION['level'] == 'orang_tua'): ?>
                            <option value="<?= $anak['id_anak'] ?>"><?= htmlspecialchars($anak['nama_anak']) ?> (Usia: <?= $anak['usia'] ?> Tahun)</option>
                        <?php else: ?>
                            <option value="<?= $anak['id_anak'] ?>"><?= htmlspecialchars($anak['nama_anak']) ?> (Wali: <?= htmlspecialchars($anak['wali']) ?>)</option>
                        <?php endif; ?>
                    <?php endwhile; ?>
                </select>
                <div class="invalid-feedback">Harap pilih anak yang akan mengikuti asesmen.</div>
            </div>
        </div>

        <?php
        if ($result_kriteria->num_rows > 0) {
            while ($kriteria = $result_kriteria->fetch_assoc()) {
                $id_kriteria = $kriteria['id_kriteria'];
                ?>
                <div class="mb-5 pb-4 border-bottom" data-kriteria="C<?= $id_kriteria ?>">
                    <h4 class="fw-bold text-primary mb-4">
                        <i class="bi bi-lightbulb-fill me-2"></i>Aspek Kecerdasan: <?= htmlspecialchars($kriteria['nama_kriteria']); ?>
                    </h4>

                    <?php
                    $sql_p = "SELECT * FROM pertanyaan WHERE id_kriteria = $id_kriteria AND deleted_at IS NULL ORDER BY id_pertanyaan ASC";
                    $result_p = $conn->query($sql_p);

                    if ($result_p->num_rows > 0) {
                        $no = 1;
                        while ($pertanyaan = $result_p->fetch_assoc()) {
                            $id_p = $pertanyaan['id_pertanyaan'];
                            // Ambil nilai default jika ada, atau 3 jika tidak ada
                            $default_value = isset($_POST['skor'][$id_p]) ? intval($_POST['skor'][$id_p]) : 0;
                            ?>
                            <div class="mb-4 ps-lg-4 border-start border-4 border-primary-subtle ms-2 ps-3 py-2">
                                <p class="mb-3 fw-bold text-dark"><?= $no++; ?>. <?= htmlspecialchars($pertanyaan['teks_pertanyaan']); ?></p>
                                <div class="options-group d-flex flex-wrap gap-2" role="group" aria-label="Skor Pertanyaan">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <input type="radio" class="btn-check" name="skor[<?= $id_p; ?>]" id="q<?= $id_p . $i; ?>" value="<?= $i; ?>" <?= ($i == $default_value) ? 'checked' : ''; ?> required>
                                        <label class="btn btn-outline-primary flex-fill text-center py-2" style="min-width: 60px;" for="q<?= $id_p . $i; ?>">
                                            <div class="fw-bold fs-5"><?= $i; ?></div>
                                            <div style="font-size: 0.65rem; text-transform: uppercase;">
                                                <?php
                                                if ($i == 1) echo "Bukan";
                                                if ($i == 3) echo "Cukup";
                                                if ($i == 5) echo "Sesuai";
                                                ?>
                                            </div>
                                        </label>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <?php
                        }
                    } else {
                        echo "<p style='color:#64748b; font-size:0.9rem; font-style:italic;'>Belum ada daftar indikator pertanyaan untuk aspek ini.</p>";
                    }
                    ?>
                </div>
                <?php
            }
        }
        ?>
        <div class="text-center pt-4 mt-5 border-top">
            <button type="submit" class="btn btn-primary btn-lg shadow-sm">Selesai & Analisis Potensi <i
                    class="bi bi-arrow-right-short ms-1"></i></button>
        </div>
    </form>
</div>

<script>
(() => {
  'use strict'

  const form = document.getElementById('formAsesmen')
  form.addEventListener('submit', event => {

    // Cek validitas form menggunakan API browser
    if (!form.checkValidity()) {
      event.preventDefault()
      event.stopPropagation()
      
      // Fokus ke elemen pertama yang tidak valid agar pengguna tahu di mana letak kesalahannya
      const firstInvalid = form.querySelector(':invalid');
      if (firstInvalid) {
        firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }

    form.classList.add('was-validated')
  }, false)

  // Logika Perhitungan Progres
  const progressBar = document.getElementById('asesmenProgress')
  const updateProgress = () => {
      const inputs = document.querySelectorAll('#formAsesmen input[required]')
      const uniqueNames = new Set()
      inputs.forEach(input => uniqueNames.add(input.name))
      const totalItems = uniqueNames.size

      let filledItems = 0
      uniqueNames.forEach(name => {
          const fields = document.getElementsByName(name)
          if (fields.length > 1) { // Menangani Radio Group
              if (Array.from(fields).some(r => r.checked)) filledItems++
          } else { // Menangani Input Text/Number
              if (fields[0].value.trim() !== "") filledItems++
          }
      })

      const percentage = totalItems > 0 ? Math.round((filledItems / totalItems) * 100) : 0
      progressBar.style.width = percentage + '%'
      progressBar.setAttribute('aria-valuenow', percentage)
      progressBar.textContent = percentage + '%'
  }

  // Pasang event listener ke semua input di dalam form
  form.querySelectorAll('input').forEach(input => {
      input.addEventListener('input', updateProgress)
      input.addEventListener('change', updateProgress)
  })

  // Jalankan kalkulasi saat halaman pertama kali dimuat
  updateProgress()
})()

// Auto-fill script for demo
function fillForm(pattern) {
    const form = document.getElementById('formAsesmen');
    const allRadios = form.querySelectorAll('input[type="radio"]');

    if (pattern === 'reset') {
        allRadios.forEach(radio => radio.checked = false);
        if (allRadios.length > 0) allRadios[0].dispatchEvent(new Event('change'));
        return;
    }

    const getRandomInt = (min, max) => Math.floor(Math.random() * (max - min + 1)) + min;

    const kriteriaGroups = form.querySelectorAll('[data-kriteria]');
    kriteriaGroups.forEach(group => {
        const kriteriaId = group.getAttribute('data-kriteria');
        const questionRadios = group.querySelectorAll('input[type="radio"]');
        const questionNames = [...new Set(Array.from(questionRadios).map(r => r.name))];

        questionNames.forEach(name => {
            let valueToSet;
            switch (pattern) {
                case 'logika':
                    valueToSet = (kriteriaId === 'C1') ? 5 : getRandomInt(1, 2);
                    break;
                case 'seni':
                    // Kriteria C3 adalah "Kecenderungan Auditori & Ritme Musik"
                    valueToSet = (kriteriaId === 'C3') ? 5 : getRandomInt(1, 3);
                    break;
                case 'ekstrem':
                    valueToSet = 5;
                    break;
            }
            
            const radioToSelect = group.querySelector(`input[name="${name}"][value="${valueToSet}"]`);
            if (radioToSelect) {
                radioToSelect.checked = true;
            }
        });
    });

    if (allRadios.length > 0) {
        allRadios[0].dispatchEvent(new Event('change'));
    }
    alert('Form telah diisi secara otomatis sesuai simulasi. Silakan pilih anak dan klik "Selesai & Analisis Potensi".');
}
</script>

</body>

</html>