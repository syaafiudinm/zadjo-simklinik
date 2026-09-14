-- User MySQL untuk koneksi PUSAT aplikasi (DB_USERNAME).
--
-- Hanya berhak atas database pusat dan database test-nya. Tidak bisa membaca,
-- membuat, atau menghapus database tenant; pekerjaan itu milik koneksi
-- `tenancy_admin`. Dengan begitu kode pusat — landing, panel vendor — secara
-- struktural tidak bisa menjangkau tabel klinis mana pun (PRD §4.2).
--
-- Idempoten: aman dijalankan ulang (`make db-users`). Placeholder
-- @@APP_USER@@ / @@APP_PASSWORD@@ diganti Makefile dan workflow CI.

CREATE USER IF NOT EXISTS '@@APP_USER@@'@'%' IDENTIFIED BY '@@APP_PASSWORD@@';
ALTER USER '@@APP_USER@@'@'%' IDENTIFIED BY '@@APP_PASSWORD@@';

CREATE DATABASE IF NOT EXISTS `simklinik_central` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `simklinik_testing` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON `simklinik_central`.* TO '@@APP_USER@@'@'%';
GRANT ALL PRIVILEGES ON `simklinik_testing`.* TO '@@APP_USER@@'@'%';
