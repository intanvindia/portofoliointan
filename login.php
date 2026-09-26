<?php
require_once 'config/koneksi.php';
session_start();

if (isset($_SESSION['level'])) {
    header("Location: " . ($_SESSION['level'] == 'admin' ? 'admin_dashboard.php' : 'asesmen.php'));
    exit;
}

$pesan = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];
    
    $stmt = $conn->prepare("SELECT * FROM user WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        // Verifikasi kecocokan hash password
        if (password_verify($password, $user['password'])) {
            $_SESSION['id_user']      = $user['id_user'];
            $_SESSION['username']     = $user['username'];
            $_SESSION['nama_lengkap']  = $user['nama_lengkap'];
            $_SESSION['level']        = $user['level'];

            // Catat log aktivitas login
            $id_user_log = $user['id_user'];
            $stmt_log = $conn->prepare("INSERT INTO logs (id_user, aktivitas) VALUES (?, 'User login ke sistem.')");
            $stmt_log->bind_param("i", $id_user_log);
            $stmt_log->execute();
            $stmt_log->close();
            
            // Redirect sesuai hak akses
            if ($user['level'] == 'admin') {
                header("Location: admin_dashboard.php");
            } elseif ($user['level'] == 'staf') {
                header("Location: staff_dashboard.php");
            } else {
                header("Location: dashboard.php");
            }
            exit;
        } else {
            $pesan = "<div class='alert alert-danger border-0 shadow-sm'><i class='bi bi-exclamation-triangle-fill me-2'></i>Password salah!</div>";
        }
    } else {
        $pesan = "<div class='alert alert-danger border-0 shadow-sm'><i class='bi bi-person-x-fill me-2'></i>Username tidak ditemukan!</div>";
    }
    $stmt->close();
}

$page_title = "Masuk - SIMBIM";
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
                            <i class="bi bi-rocket-takeoff-fill fs-2"></i>
                        </div>
                    <?php endif; ?>
                    <h2 class="fw-bold text-dark">Selamat Datang</h2>
                    <p class="text-muted">Masuk untuk melanjutkan ke SIMBIM</p>
                </div>

                <?= $pesan; ?>

                <form action="" method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Username</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0"><i class="bi bi-person text-muted"></i></span>
                            <input type="text" name="username" class="form-control bg-light border-start-0 ps-0" placeholder="Masukkan username" required>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label fw-bold">Password</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0"><i class="bi bi-lock text-muted"></i></span> 
                            <input type="password" name="password" id="passwordFieldLogin" class="form-control bg-light border-start-0 ps-0" placeholder="Masukkan password" required>
                            <button class="btn btn-outline-secondary bg-light border-start-0" type="button" id="togglePasswordLogin">
                                <i class="bi bi-eye-slash text-muted"></i>
                            </button>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100 py-2 fs-5 fw-bold shadow-sm rounded-3">Masuk Sekarang <i class="bi bi-arrow-right-short ms-1"></i></button>
                </form>

                <script>
                    document.getElementById('togglePasswordLogin').addEventListener('click', function () {
                        const passwordField = document.getElementById('passwordFieldLogin');
                        const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
                        passwordField.setAttribute('type', type);
                        this.querySelector('i').classList.toggle('bi-eye');
                        this.querySelector('i').classList.toggle('bi-eye-slash');
                    });
                </script>

                <div class="text-center mt-4">
                    <p class="text-muted small mb-0">Belum punya akun? <a href="daftar.php" class="text-primary fw-bold text-decoration-none">Daftar di sini</a></p>
                </div>
            </div>
            <div class="text-center mt-4">
                <a href="index.php" class="text-muted text-decoration-none small"><i class="bi bi-house-door me-1"></i> Kembali ke Beranda</a>
            </div>
        </div>
    </div>
</div>

</body>
</html>