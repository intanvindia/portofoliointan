<?php
require_once 'config/koneksi.php';
session_start();

// Jika sudah login, tendang ke halaman yang sesuai
if (isset($_SESSION['level'])) {
    header("Location: " . ($_SESSION['level'] == 'admin' ? 'admin_dashboard.php' : 'asesmen.php'));
    exit;
}

$pesan = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $nama_lengkap = $_POST['nama_lengkap'];
    $password = $_POST['password'];
    $no_hp = $_POST['no_hp'];

    // Hash password demi keamanan database
    $password_hashed = password_hash($password, PASSWORD_DEFAULT);

    // Cek apakah username sudah terdaftar
    $stmt_cek = $conn->prepare("SELECT id_user FROM user WHERE username = ?");
    $stmt_cek->bind_param("s", $username);
    $stmt_cek->execute();
    $res_cek = $stmt_cek->get_result();
    
    if ($res_cek->num_rows > 0) {
        $pesan = "<div class='alert alert-danger border-0 shadow-sm'><i class='bi bi-exclamation-triangle-fill me-2'></i>Username sudah digunakan!</div>";
    } else {
        // Simpan dengan role 'orang_tua' secara default
        $stmt_ins = $conn->prepare("INSERT INTO user (username, password, nama_lengkap, no_hp, level) VALUES (?, ?, ?, ?, 'orang_tua')");
        $stmt_ins->bind_param("ssss", $username, $password_hashed, $nama_lengkap, $no_hp);
        
        if ($stmt_ins->execute()) {
            $pesan = "<div class='alert alert-success border-0 shadow-sm'><i class='bi bi-check-circle-fill me-2'></i>Pendaftaran berhasil! Silakan <a href='login.php' class='alert-link'>Login di sini</a></div>";
        } else {
            $pesan = "<div class='alert alert-danger border-0 shadow-sm'><i class='bi bi-x-circle-fill me-2'></i>Gagal mendaftar: " . $conn->error . "</div>";
        }
        $stmt_ins->close();
    }
    $stmt_cek->close();
}

$page_title = "Daftar Akun - SIMBIM";
include 'includes/header.php';
?>

<div class="container">
    <div class="row justify-content-center align-items-center min-vh-100">
        <div class="col-md-5">
            <div class="pb-card p-4 p-lg-5 shadow-lg border-0">
                <div class="text-center mb-4">
                    <?php if(!empty($settings['site_logo'])): ?>
                        <img src="<?= htmlspecialchars($settings['site_logo']) ?>" alt="Logo" class="img-fluid mb-3" style="max-height: 80px;">
                    <?php else: ?>
                        <div class="bg-primary text-white d-inline-block p-3 rounded-circle mb-3 shadow">
                            <i class="bi bi-person-plus-fill fs-2"></i>
                        </div>
                    <?php endif; ?>
                    <h2 class="fw-bold text-dark">Daftar Akun Baru</h2>
                    <p class="text-muted">Bergabunglah dengan SIMBIM untuk menemukan potensi anak Anda</p>
                </div>

                <?= $pesan; ?>

                <form id="formRegister" action="" method="POST" class="needs-validation" novalidate>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nama Lengkap</label>
                        <div class="input-group has-validation">
                            <span class="input-group-text bg-light border-end-0"><i class="bi bi-person-vcard text-muted"></i></span>
                            <input type="text" name="nama_lengkap" id="nama_lengkap" class="form-control bg-light border-start-0 ps-0" placeholder="Nama Lengkap Anda" required>
                            <div class="invalid-feedback">Nama lengkap hanya boleh berisi huruf dan spasi.</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Nomor HP (WhatsApp)</label>
                        <div class="input-group has-validation">
                            <span class="input-group-text bg-light border-end-0"><i class="bi bi-whatsapp text-muted"></i></span>
                            <input type="text" name="no_hp" id="no_hp" class="form-control bg-light border-start-0 ps-0" placeholder="Contoh: 08123456789" required>
                            <div class="invalid-feedback">Nomor HP tidak valid. Gunakan format Indonesia (cth: 0812...).</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Username</label>
                        <div class="input-group has-validation">
                            <span class="input-group-text bg-light border-end-0"><i class="bi bi-person text-muted"></i></span>
                            <input type="text" name="username" id="username" class="form-control bg-light border-start-0 ps-0" placeholder="Username unik" required>
                            <div class="invalid-feedback">Username minimal 4 karakter (hanya huruf, angka, dan underscore).</div>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label fw-bold">Password</label>
                        <div class="input-group has-validation">
                            <span class="input-group-text bg-light border-end-0"><i class="bi bi-lock text-muted"></i></span> 
                            <input type="password" name="password" id="passwordFieldRegister" class="form-control bg-light border-start-0 ps-0" placeholder="Buat password" required>
                            <button class="btn btn-outline-secondary bg-light border-start-0" type="button" id="togglePasswordRegister">
                                <i class="bi bi-eye-slash text-muted"></i>
                            </button>
                            <div class="invalid-feedback">Password minimal harus terdiri dari 6 karakter.</div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100 py-2 fs-5 fw-bold shadow-sm rounded-3">Daftar Sekarang <i class="bi bi-arrow-right-short ms-1"></i></button>
                </form>

                <script>
                    document.getElementById('togglePasswordRegister').addEventListener('click', function () {
                        const passwordField = document.getElementById('passwordFieldRegister');
                        const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
                        passwordField.setAttribute('type', type);
                        this.querySelector('i').classList.toggle('bi-eye');
                        this.querySelector('i').classList.toggle('bi-eye-slash');
                    });

                    // Client-Side Validation
                    (() => {
                        'use strict'
                        const form = document.getElementById('formRegister');
                        
                        form.addEventListener('submit', event => {
                            const namaInput = document.getElementById('nama_lengkap');
                            const usernameInput = document.getElementById('username');
                            const passwordInput = document.getElementById('passwordFieldRegister');
                            const noHpInput = document.getElementById('no_hp');
                            
                            let isValid = true;
                            
                            // 1. Validasi Nama Lengkap: hanya huruf dan spasi
                            const namaPattern = /^[a-zA-Z\s]+$/;
                            if (!namaPattern.test(namaInput.value.trim())) {
                                namaInput.setCustomValidity('invalid');
                                isValid = false;
                            } else {
                                namaInput.setCustomValidity('');
                            }
                            
                            // 2. Validasi Username: min 4 karakter, alphanumeric + underscore
                            const usernamePattern = /^[a-zA-Z0-9_]{4,}$/;
                            if (!usernamePattern.test(usernameInput.value.trim())) {
                                usernameInput.setCustomValidity('invalid');
                                isValid = false;
                            } else {
                                usernameInput.setCustomValidity('');
                            }
                            
                            // 3. Validasi Password: min 6 karakter
                            if (passwordInput.value.length < 6) {
                                passwordInput.setCustomValidity('invalid');
                                isValid = false;
                            } else {
                                passwordInput.setCustomValidity('');
                            }
                            
                            // 4. Validasi Nomor HP: format Indonesia
                            const hpPattern = /^08[0-9]{8,11}$/;
                            if (!hpPattern.test(noHpInput.value.trim())) {
                                noHpInput.setCustomValidity('invalid');
                                isValid = false;
                            } else {
                                noHpInput.setCustomValidity('');
                            }

                            if (!form.checkValidity() || !isValid) {
                                event.preventDefault();
                                event.stopPropagation();
                            }
                            
                            form.classList.add('was-validated');
                        }, false);
                        
                        // Reset custom validity on input
                        document.getElementById('nama_lengkap').addEventListener('input', function() {
                            this.setCustomValidity('');
                        });
                        document.getElementById('username').addEventListener('input', function() {
                            this.setCustomValidity('');
                        });
                        document.getElementById('passwordFieldRegister').addEventListener('input', function() {
                            this.setCustomValidity('');
                        });
                        document.getElementById('no_hp').addEventListener('input', function() {
                            this.setCustomValidity('');
                        });
                    })();
                </script>

                <div class="text-center mt-4">
                    <p class="text-muted small mb-0">Sudah punya akun? <a href="login.php" class="text-primary fw-bold text-decoration-none">Login di sini</a></p>
                </div>
            </div>
            <div class="text-center mt-4">
                <a href="index.php" class="text-muted text-decoration-none small"><i class="bi bi-house-door me-1"></i> Kembali ke Beranda</a>
            </div>
        </div>
    </div>
</body>
</html>