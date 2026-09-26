# 🛡️ Kebijakan Keamanan (Security Policy)

Keamanan aplikasi SIMBIM adalah prioritas utama. Kami berkomitmen untuk melindungi data pengguna dan menjaga integritas sistem. Dokumen ini menguraikan kebijakan keamanan kami, termasuk cara melaporkan kerentanan dan praktik keamanan yang telah kami terapkan.

##  versões suportadas

Kami hanya memberikan pembaruan keamanan untuk versi terbaru yang dirilis di branch `main`.

| Versi | Didukung          |
| :---- | :---------------- |
| 1.4.x | :white_check_mark: |
| < 1.4 | :x:               |

##  Vulnerability Reporting

Jika Anda menemukan kerentanan keamanan, kami sangat menghargai bantuan Anda untuk mengungkapkannya secara bertanggung jawab.

**Mohon untuk TIDAK mempublikasikan kerentanan tersebut secara publik.**

Sebagai gantinya, kirimkan email ke **bot.hunting101@gmail.com** dengan detail berikut:

*   **Judul:** Laporan Keamanan SIMBIM - [Jenis Kerentanan]
*   **Deskripsi:** Penjelasan detail mengenai kerentanan.
*   **Langkah-langkah untuk Mereproduksi:** Cara kami dapat mereplikasi masalah tersebut.
*   **Dampak:** Potensi dampak dari kerentanan ini.
*   **Saran Perbaikan (Opsional):** Jika Anda memiliki ide untuk memperbaikinya.

Kami akan berusaha untuk merespons laporan Anda dalam waktu 48 jam dan akan memberikan informasi mengenai proses perbaikan.

## 🔐 Praktik Keamanan yang Diterapkan

Berikut adalah beberapa langkah keamanan yang telah diimplementasikan dalam kode SIMBIM:

### 1. Pencegahan SQL Injection
Seluruh query ke database yang melibatkan input dari pengguna menggunakan **Prepared Statements** (melalui `mysqli::prepare`, `bind_param`, dan `execute`). Ini adalah metode paling efektif untuk mencegah serangan SQL Injection.

### 2. Keamanan Password
*   **Hashing:** Password pengguna disimpan dalam format *hash* menggunakan algoritma `PASSWORD_BCRYPT` (via `password_hash()` dengan `PASSWORD_DEFAULT`).
*   **Verifikasi:** Proses login menggunakan `password_verify()` untuk membandingkan password yang dimasukkan dengan *hash* yang tersimpan, sehingga password asli tidak pernah terekspos.

### 3. Pencegahan Cross-Site Scripting (XSS)
Setiap data yang berasal dari pengguna atau database dan akan ditampilkan di halaman HTML disanitasi menggunakan `htmlspecialchars()`. Ini mencegah eksekusi skrip berbahaya di browser pengguna.

### 4. Kontrol Akses Berbasis Peran (Role-Based Access Control)
Aplikasi menerapkan validasi hak akses yang ketat di setiap halaman:
*   Hanya pengguna dengan level `admin` yang dapat mengakses `admin_dashboard.php`.
*   Hanya pengguna dengan level `staf` yang dapat mengakses `staff_dashboard.php`.
*   Halaman yang memerlukan login dilindungi dengan validasi `$_SESSION`.

### 5. Validasi Input
*   **Server-side:** Input dari pengguna divalidasi di sisi server. Contohnya, ID dari URL atau form dikonversi menjadi integer menggunakan `intval()`.
*   **Client-side:** Form pendaftaran dan asesmen memiliki validasi dasar untuk memastikan format data yang benar sebelum dikirim.

### 6. Keamanan Sesi (Session Security)
*   Sesi pengguna dikelola dengan aman.
*   Fungsi `logout.php` memastikan sesi dihancurkan dengan benar saat pengguna keluar.

### 7. Pencegahan Paparan Informasi
*   File-file sensitif seperti `koneksi.php` ditempatkan di dalam folder `config/`.
*   Skrip migrasi (`migrate2.php`) menyertakan instruksi untuk dihapus setelah digunakan untuk mencegah eksekusi ulang yang tidak diinginkan.

### Area untuk Peningkatan di Masa Depan
*   **Proteksi CSRF (Cross-Site Request Forgery):** Mengimplementasikan token CSRF pada semua form yang melakukan perubahan data (POST) dan menghindari operasi perubahan data melalui metode GET.

---
Terima kasih telah membantu menjaga keamanan SIMBIM.