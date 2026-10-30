-- Jalankan di phpMyAdmin Hostinger jika artisan migrate gagal/belum:
ALTER TABLE content_blueprints
  ADD COLUMN vo_beat_seconds TINYINT UNSIGNED NOT NULL DEFAULT 5 AFTER beat_seconds;
