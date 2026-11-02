# MeSH Subject Manager

**Plugin pengelolaan subjek Medical Subject Headings (MeSH) 2026 dan rekomendasi NLM Classification untuk SLiMS 9.8.0.**

**Versi:** 1.9.3  
**Platform:** SLiMS 9.8.0 · PHP · MariaDB/MySQL  
**Bahasa antarmuka:** Indonesia

## Tentang proyek

MeSH Subject Manager membantu pustakawan melakukan pengindeksan subjek bibliografi menggunakan kosakata terkendali **Medical Subject Headings (MeSH)** dari U.S. National Library of Medicine (NLM). Plugin ini mengintegrasikan pencarian descriptor, penjelajahan hierarki MeSH Tree, dan rekomendasi nomor **NLM Classification** ke formulir Add/Edit Bibliography pada SLiMS.

Mulai versi **1.9.3**, dataset MeSH 2026 dan NLM Classification telah dipaketkan dalam installer plugin. Perpustakaan pengguna tidak perlu menjalankan harvester Python atau mengimpor SQL secara manual melalui Adminer.

> **Penting:** Rekomendasi nomor NLM merupakan bantuan pengambilan keputusan bagi pustakawan, bukan penetapan nomor panggil final secara otomatis. Verifikasi dengan NLM Classification Schedules dan kebijakan katalogisasi setempat tetap diperlukan.

## Fitur utama

- **Cari MeSH:** mencari descriptor berdasarkan istilah dan memilih beberapa subjek untuk bibliografi.
- **Jelajahi MeSH Tree:** menelusuri hierarki subjek melalui pemuatan cabang bertahap (AJAX).
- **Integrasi bibliografi:** tombol **Cari MeSH** pada Add/Edit Bibliography; subjek terpilih tersimpan menggunakan mekanisme `mst_topic` dan `biblio_topic` SLiMS.
- **Rekomendasi klasifikasi NLM:** menampilkan nomor klasifikasi yang dipetakan ke descriptor MeSH, disertai rujukan sumber.
- **Pengisian klasifikasi bibliografi:** nomor NLM yang dipilih dapat diterapkan pada field *Classification* di formulir bibliografi, kemudian disimpan melalui mekanisme bawaan SLiMS ke `biblio.classification`. Periksa kembali field *Call Number* sebelum menyimpan.
- **Installer dataset terintegrasi:** data MeSH dan NLM dipasang bertahap dari berkas `.sql.gz` dalam paket.
- **Instalasi dapat dilanjutkan:** status batch NLM disimpan sehingga proses yang terputus dapat dilanjutkan.
- **Tanpa perubahan core SLiMS:** integrasi menggunakan plugin dan hook bibliografi.

## Dataset yang disertakan

### MeSH 2026

Dataset descriptor, istilah terkait, dan struktur hierarki MeSH Tree disertakan secara offline untuk instalasi melalui plugin.

### NLM Classification — dataset hasil harvesting

| Tabel | Jumlah baris yang ditargetkan |
|---|---:|
| `nlm_entries` | 48.917 |
| `nlm_classes` | 8.209 |
| `nlm_references` | 43.023 |
| `nlm_pages` | 992 |
| `nlm_mapping` | 8.209 |

Mapping langsung didasarkan pada pencocokan nama descriptor yang sama (*exact descriptor name*). Tidak semua descriptor MeSH memiliki mapping NLM langsung; ketiadaan rekomendasi bukan berarti suatu subjek tidak memiliki nomor klasifikasi yang relevan.

## Persyaratan

- SLiMS **9.8.0** dengan akses administrator dan izin mengaktifkan plugin.
- PHP dan MariaDB/MySQL sesuai kebutuhan instalasi SLiMS.
- Ekstensi PHP untuk akses database dan dekompresi gzip/zlib tersedia.
- Izin tulis database untuk membuat dan mengisi tabel dataset plugin.
- Waktu eksekusi server yang memadai untuk instalasi dataset secara bertahap.

> Paket ini disusun untuk SLiMS 9.8.0. Kompatibilitas dengan versi lain perlu diuji terlebih dahulu.

## Instalasi

1. **Cadangkan** database SLiMS dan folder plugin sebelumnya, jika ada.
2. Unduh ZIP rilis **MeSH Subject Manager v1.9.3**.
3. Ekstrak folder `mesh_subject_manager` ke direktori `plugins/` instalasi SLiMS.
4. Buka administrasi SLiMS dan **aktifkan plugin** MeSH Subject Manager.
5. Login menggunakan **administrator utama** (`uid=1`), lalu buka menu **MeSH Subject Manager** pada kelompok Bibliography.
6. Ikuti proses instalasi dataset MeSH dan NLM sampai keduanya dinyatakan selesai.
7. Jika proses terputus, buka kembali halaman plugin untuk melanjutkan instalasi.
8. Buka **Bibliography → Add New Bibliography** atau edit bibliografi, lalu klik **Cari MeSH** untuk mulai menggunakan plugin.

**Tidak diperlukan:** impor manual `nlm_v05_mariadb.sql`, menjalankan Python, ataupun harvesting ulang NLM pada perpustakaan pengguna.

### Pemeriksaan setelah instalasi

Jalankan kueri berikut melalui Adminer apabila ingin memverifikasi data NLM:

```sql
SELECT COUNT(*) AS total_mapping FROM nlm_mapping;
```

Hasil yang diharapkan untuk dataset bawaan v1.9.3 adalah **8.209**. Periksa pula status instalasi MeSH pada halaman plugin.

## Cara menggunakan

1. Buka formulir **Add/Edit Bibliography** di SLiMS.
2. Pada bagian **Subject(s)**, klik **Cari MeSH**.
3. Cari descriptor atau buka tab **Jelajahi Tree MeSH**.
4. Centang subjek yang sesuai dengan isi dokumen dan klik **Simpan subjek terpilih**.
5. Bila tersedia, periksa **Rekomendasi NLM** pada hasil pencarian.
6. Gunakan nomor klasifikasi yang dipilih pada formulir bibliografi, lalu verifikasi kesesuaiannya dengan dokumen dan NLM Schedules.
7. Klik **Save/Update** pada formulir bibliografi untuk menyimpan data.

**Contoh:** descriptor *Sex Chromatin* (`D012728`) memiliki rekomendasi `QU 470` pada dataset bawaan. Contoh ini bukan pengganti analisis subjek untuk dokumen tertentu.

## Integrasi database SLiMS

Plugin menggunakan tabel bawaan SLiMS untuk pengelolaan subjek dan bibliografi, termasuk:

- `mst_topic`: daftar subjek dan kolom `classification` untuk kode klasifikasi subjek.
- `biblio_topic`: hubungan subjek dengan rekaman bibliografi.
- `biblio`: metadata bibliografi, termasuk `classification` dan `call_number`.

Dataset NLM disimpan dalam tabel terpisah berawalan `nlm_`, sehingga tidak memerlukan perubahan struktur tabel inti SLiMS.

## Keamanan dan pembaruan

- Pemasangan dataset NLM dibatasi untuk administrator utama dan menggunakan perlindungan CSRF pada installer plugin.
- Installer menggunakan pendekatan batch dan `INSERT IGNORE` untuk data NLM yang sudah ada; tidak mengosongkan tabel `nlm_*` pada instalasi normal.
- Simpan backup sebelum upgrade atau mengubah dataset.
- Jangan menghapus direktori `data/` sebelum instalasi selesai.
- Setelah instalasi, pertimbangkan pembatasan akses langsung ke berkas dataset melalui konfigurasi server web.

## Struktur berkas penting

```text
mesh_subject_manager/
├── mesh_subject_manager.plugin.php
├── index.php
├── installer.php
├── nlm_installer.php
├── nlm_helper.php
├── mesh_picker.php
├── mesh_biblio_button.inc.php
├── mesh_tree_api.php
├── manifest.json
├── nlm_manifest.json
└── data/
    ├── *.sql.gz
    └── nlm/
        └── *.sql.gz
```

## Pemecahan masalah

**Rekomendasi NLM tidak muncul:** pastikan instalasi NLM selesai, tabel `nlm_mapping` terisi, dan descriptor yang dipilih memang memiliki mapping langsung.

**Classification tidak tersimpan pada bibliografi:** pastikan nomor sudah diterapkan pada field Classification di formulir Add/Edit Bibliography, kemudian klik Save/Update. Penyimpanan subjek saja tidak selalu berarti bibliografi telah disimpan.

**Instalasi terhenti:** buka kembali halaman MeSH Subject Manager sebagai administrator utama; installer NLM menyimpan progres batch. Periksa log PHP dan database bila kegagalan berulang.

**Modal tidak menampilkan perubahan:** lakukan hard refresh (`Ctrl+F5`) dan periksa konsol browser serta log PHP.

## Sumber dan atribusi

- **Medical Subject Headings (MeSH):** U.S. National Library of Medicine — https://www.nlm.nih.gov/mesh/
- **NLM Classification:** U.S. National Library of Medicine — https://classification.nlm.nih.gov/
- **SLiMS:** Senayan Library Management System — https://slims.web.id/

MeSH dan NLM Classification adalah sumber terminologi dan klasifikasi milik lembaga penerbitnya. Plugin ini merupakan alat integrasi pihak ketiga dan **bukan produk resmi atau mendapat pengesahan dari NLM**.

## Kontribusi dan lisensi

Masukan, laporan bug, dan kontribusi pengembangan dapat disampaikan melalui fitur **Issues** pada repositori GitHub tempat proyek dipublikasikan.

**Lisensi kode:** tambahkan berkas `LICENSE` yang sesuai sebelum mendistribusikan proyek secara publik. Lisensi kode plugin tidak otomatis mengubah ketentuan penggunaan dataset pihak ketiga; periksa ketentuan distribusi sumber data MeSH/NLM secara terpisah.

---

*MeSH Subject Manager v1.9.3 — integrasi pengindeksan subjek dan rekomendasi klasifikasi kesehatan pada SLiMS.*
