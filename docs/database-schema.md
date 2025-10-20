# Skema Basis Data ReadIDX

Skema ini dirancang agar backend dapat menampilkan laporan keuangan perusahaan berdasarkan **kode saham**, **tahun**, dan **kuartal**. Diagram berikut menggambarkan tabel utama:

```
companies (1) ───< financial_reports (1) ───< financial_lines
```

## Tabel `companies`
- `id` (`INT`, PK, auto increment)
- `ticker` (`VARCHAR(16)`, unik): kode emiten BEI.
- `name` (`VARCHAR(255)`): nama lengkap perusahaan.
- `created_at` (`TIMESTAMP`): waktu pencatatan baris.

## Tabel `financial_reports`
- `id` (`BIGINT`, PK, auto increment).
- `company_id` (`INT`, FK → `companies.id`).
- `fiscal_year` (`SMALLINT`): tahun laporan.
- `fiscal_quarter` (`TINYINT` 1-4): kuartal laporan.
- `source_file` (`VARCHAR(255)`, opsional): nama berkas XBRL sumber.
- `created_at` (`TIMESTAMP`).
- Indeks unik `(company_id, fiscal_year, fiscal_quarter)` mencegah duplikasi.

## Tabel `financial_lines`
- `id` (`BIGINT`, PK, auto increment).
- `report_id` (`BIGINT`, FK → `financial_reports.id`).
- `line_item` (`VARCHAR(255)`): nama pos laporan, mis. "Pendapatan".
- `value` (`DECIMAL(24,4)`): nilai numerik.
- `unit` (`VARCHAR(32)`): mata uang atau satuan, mis. `IDR`.
- `display_order` (`INT`): urutan tampil.
- `created_at` (`TIMESTAMP`).
- Indeks `(report_id, display_order)` mempercepat pengambilan data untuk tabel UI.

## Relasi dan Penggunaan
- Setiap perusahaan memiliki banyak laporan (`1:N`).
- Setiap laporan terdiri dari banyak baris keuangan (`1:N`).
- Backend mengambil data dengan join dari ketiga tabel dan menampilkannya melalui endpoint `public/api/report.php`.

## Migrasi
Lihat `db/migrations/001_create_tables.sql` untuk perintah SQL detail yang membuat tabel beserta indeks dan relasi.
