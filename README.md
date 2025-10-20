# ReadIDX

Aplikasi sederhana untuk menampilkan laporan keuangan emiten Bursa Efek Indonesia berdasarkan kode saham, tahun, dan kuartal. Backend ditulis dengan PHP menggunakan PDO, sedangkan frontend memakai HTML, CSS, dan JavaScript tanpa framework.

## Struktur Proyek

```
public/              # Frontend statis dan endpoint API
src/backend/         # Kode backend (repository, service, koneksi DB)
db/migrations/       # Skrip SQL untuk membuat skema database
tools/               # Skrip CLI untuk ETL dari arsip XBRL
instance_files/      # Contoh arsip XBRL mentah
```

## Menjalankan Backend
1. Pastikan PHP 8.1+ tersedia beserta ekstensi `pdo`, `pdo_mysql`, `zip`, dan `dom`.
2. Salin file konfigurasi database `src/backend/config/database.php` lalu sesuaikan kredensial jika diperlukan (bisa juga menggunakan variabel lingkungan `DB_HOST`, `DB_USERNAME`, dll.).
3. Jalankan migrasi SQL:
   ```bash
   mysql -u root -p readidx < db/migrations/001_create_tables.sql
   ```
4. Jalankan server PHP bawaan:
   ```bash
   php -S 0.0.0.0:8000 -t public
   ```
5. Buka `http://localhost:8000` di peramban untuk menggunakan UI.

## Mengimpor Data XBRL
Gunakan skrip CLI `tools/import_xbrl.php` untuk mem-parsing arsip Inline XBRL dan memuat nilai ke database.

```bash
php tools/import_xbrl.php \
  --file=instance_files/AALI_2025_Q1_inlinexbrl.zip \
  --ticker=AALI \
  --name="Astra Agro Lestari Tbk" \
  --year=2025 \
  --quarter=1
```

Skrip akan:
- Membuat/menyunting entri perusahaan pada tabel `companies`.
- Membuat laporan pada `financial_reports` (menghindari duplikasi dengan kuartal/tahun yang sama).
- Memasukkan fakta keuangan dari tag `ix:nonFraction` atau `ix:nonNumeric` ke tabel `financial_lines`.

## API
Endpoint utama berada di `public/api/report.php` dan menerima parameter `ticker`, `year`, `quarter` (POST JSON ataupun query string). Respons sukses:

```json
{
  "status": "success",
  "data": {
    "company": { "ticker": "AALI", "name": "Astra Agro Lestari Tbk" },
    "fiscal_year": 2025,
    "fiscal_quarter": 1,
    "lines": [
      { "line_item": "Pendapatan", "value": 123456789, "unit": "IDR" }
    ]
  }
}
```

Jika parameter tidak valid atau data tidak ditemukan, endpoint akan mengembalikan status 422 dengan pesan error yang jelas.

## Pengembangan Frontend
- Formulir menerima input kode saham, tahun, dan kuartal.
- Setelah submit, JavaScript (`public/assets/app.js`) memanggil API dan menampilkan hasil dalam tabel responsif.
- `public/assets/styles.css` menyediakan gaya minimalis dan responsif.

## Lisensi
Proyek ini dibuat sebagai contoh; gunakan dan modifikasi sesuai kebutuhan.
