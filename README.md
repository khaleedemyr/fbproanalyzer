# FB Pro Analyzer

Dashboard analisa konten Facebook Professional untuk niche **psikologi** (KEA Abbasy).

Stack: **Laravel API + Vue 3 SPA** (tanpa Inertia, tanpa Docker, tanpa Node di hosting).

## Fitur MVP

- Login/register dashboard
- Hubungkan Meta (Personal Pro + Pages)
- Sync Insights Page + viral score heuristik
- Board performa & pola format/topik
- Watchlist kompetitor (manual)
- Antrian ide konten + generate dari pola menang

## Setup lokal

1. Copy env: `.env` sudah ada; isi `META_APP_SECRET`
2. `composer install`
3. `php artisan key:generate` (jika perlu)
4. `php artisan migrate`
5. `npm install && npm run build` (atau `npm run dev`)
6. `php artisan serve`

Buka `http://localhost:8000`

## Meta App settings

App: **KEA Abbasy Analyzer**  
App ID: isi di `.env` → `META_APP_ID`

Di Meta Developer:

1. Facebook Login → Settings
2. Valid OAuth Redirect URIs:
   - Lokal: `http://localhost:8000/meta/callback`
   - Hostinger: `https://domainkamu.com/meta/callback`
3. App Domains + Website site URL sesuai domain

Mode Development cukup untuk akun Kea Abbasy sendiri.

## Deploy Hostinger (PHP only)

1. Di lokal: `npm run build`
2. Upload project (kecuali `node_modules`)
3. Document root → `public/`
4. Set `.env` production (MySQL Hostinger, `APP_URL`, Meta secret, redirect URI)
5. Wajib di `.env` production:
   ```env
   APP_URL=https://heng.justusku.co.id
   SANCTUM_STATEFUL_DOMAINS=heng.justusku.co.id,www.heng.justusku.co.id
   SESSION_DRIVER=database
   ```
6. `php artisan migrate --force`
7. Pastikan folder `storage/` dan `bootstrap/cache/` writable
8. Cron (opsional nanti): `* * * * * php /path/to/artisan schedule:run`

Tidak ada user default. Klik **Daftar** di web, atau jalankan `php artisan db:seed` (test@example.com / password).

Node **tidak** perlu di Hostinger.

## Alur pakai

1. Daftar/login
2. Akun Meta → Hubungkan Meta (login Kea Abbasy)
3. Sync Insights pada Page
4. Lihat Dashboard / Performa
5. Isi Watchlist kompetitor
6. Generate Ide Konten sebelum produksi
