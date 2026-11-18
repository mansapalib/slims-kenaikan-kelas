# 🎓 Plugin SLiMS - Mass Class Rollover & Member Promotion (v1.1.0)

Plugin administrasi keanggotaan untuk [SLiMS (Senayan Library Management System)](https://slims.web.id/web/) yang memudahkan pustakawan sekolah/madrasah memperbarui data kelas anggota secara massal pada pergantian tahun ajaran.

Menu plugin terdaftar otomatis di modul **Keanggotaan (Membership) → Rollover Kelas Massal**.

---

## ✨ Fitur Utama

1. **Naik Kelas Massal (*Promote*)**
   - Memindahkan seluruh anggota dari satu kelas asal (`inst_name`) ke kelas tujuan baru (mis. `X-1` → `XI-1`).
   - Opsi perpanjangan masa aktif kartu otomatis (`+1 Tahun`), mempertahankan tanggal lama, atau menetapkan tanggal tertentu.
   - Opsi mengubah tipe anggota bila diperlukan.

2. **Kelulusan / Arsip Alumni (*Graduate*)**
   - Mengarsipkan anggota tingkat akhir (mis. kelas `XII`) ke tipe anggota **Alumni**.
   - Jika tipe anggota **Alumni** belum ada di `mst_member_type`, tersedia opsi **pembuatan tipe "Alumni" otomatis** (`loan_limit = 0`).
   - Opsi perubahan nama kelas menjadi `Alumni - [Kelas]` (anti-duplikasi prefix), nama kustom (mis. `Alumni 2026`), dikosongkan, atau dipertahankan.
   - Opsi menonaktifkan akun (`is_pending = 1`) dan mengkadaluarsakan kartu pada hari eksekusi.

3. **Perpanjangan Masa Aktif Kartu (*Renew*)**
   - Memperpanjang masa berlaku kartu anggota satu kelas penuh (`+1 Tahun` atau tanggal tertentu) tanpa mengubah nama kelas.

4. **Pratinjau Anggota Langsung (*Live Preview*)**
   - Menampilkan jumlah anggota dan rincian daftar anggota di kelas yang dipilih secara langsung sebelum dieksekusi.

5. **Siap *Plug and Play* & Aman**
   - Deteksi otomatis ID tipe anggota **Siswa/Pelajar/Santri/Mahasiswa** dan **Alumni** dari tabel `mst_member_type` tanpa mengunci angka ID tertentu.
   - Daftar kelas asal otomatis memfilter sesuai tipe anggota yang dipilih.
   - Dilindungi transaksi database (`PDO Transaction`), token keamanan **CSRF**, pencatatan `system_log` SLiMS, dan kompatibel dengan PHP 7.4 hingga PHP 8.3+.

---

## 🛠️ Cara Instalasi

1. Salin folder `mass-rollover` ke dalam direktori `plugins/` pada instalasi SLiMS Anda:
   ```text
   plugins/
   └── mass-rollover/
       ├── mass_rollover.plugin.php
       ├── index.php
       └── README.md
   ```
2. Masuk ke Admin SLiMS, buka menu **Sistem → Plugin**.
3. Cari **Mass Class Rollover & Member Promotion**, lalu ubah statusnya menjadi **Aktif (On)**.
4. *(Khusus migrasi database dari lokal Windows ke hosting Linux/cPanel)*: nonaktifkan lalu aktifkan ulang plugin satu kali di **Sistem → Plugin** agar path file di database diperbarui ke direktori server Linux.
5. Buka menu **Keanggotaan (Membership)** → klik **Rollover Kelas Massal** di panel menu kiri.

---

## 📋 Prasyarat Data Keanggotaan

Plugin ini membaca dan memperbarui kolom **Institusi (`inst_name`)** pada tabel `member` sebagai penanda **Kelas / Rombel** (praktik standar SLiMS sekolah/madrasah). Pastikan data kelas siswa Anda disimpan pada kolom **Institusi** di menu Keanggotaan SLiMS.

---

## 🚀 Petunjuk Penggunaan

### A. Urutan Kerja yang Direkomendasikan Setiap Tahun Ajaran Baru
> **Penting:** Proses selalu dimulai dari **tingkat paling atas terlebih dahulu** agar nama kelas tidak saling menimpa!
> 1. Luluskan kelas **XII** menjadi **Alumni** terlebih dahulu.
> 2. Naikkan kelas **XI** ke kelas **XII**.
> 3. Naikkan kelas **X** ke kelas **XI**.

### B. Langkah-langkah Eksekusi
1. Buka **Keanggotaan → Rollover Kelas Massal**.
2. Pilih **Jenis Operasi**:
   - **Naik Kelas**
   - **Kelulusan / Alumni**
   - **Perpanjang Masa Aktif**
3. Pastikan **Filter Tipe Anggota** sesuai (default otomatis memilih tipe **Siswa** agar akun guru/staf yang memiliki nama ruangan/institusi tidak ikut berubah).
4. Pilih **Kelas Asal** dari dropdown. Panel pratinjau akan menampilkan jumlah anggota dan tombol **Lihat Rincian Anggota**.
5. Isi pengaturan tujuan sesuai operasi yang dipilih (mis. **Nama Kelas Tujuan** untuk Naik Kelas, atau penanganan nama kelas & status untuk Kelulusan).
6. Klik tombol **⚡ Eksekusi Perubahan Massal** dan konfirmasi dialog yang muncul.

---

## ✍️ Author

**MANSAPA Iqra Labs**

## 📄 Lisensi

Plugin ini dirilis di bawah lisensi [GNU General Public License v3.0 (GPL-3.0)](LICENSE), selaras dengan lisensi SLiMS.
