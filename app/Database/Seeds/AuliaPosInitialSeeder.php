<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Initial master data for a fresh AuliaPos v2.x baseline database.
 *
 * Deliberately seeds only the agreed baseline data:
 * - all 16 users;
 * - all 5 categories;
 * - products where is_locked = 1 (40 rows).
 *
 * Primary keys are inserted explicitly so IDs remain identical to the
 * verified v2.0 database. Operational data is not seeded.
 */
class AuliaPosInitialSeeder extends Seeder
{
    public function run()
    {
        $db = $this->db;

        $users = [
            ['id' => 1, 'username' => 'admin', 'nama' => null, 'inisial' => null, 'divisi' => null, 'no_hp' => null, 'profile_photo' => null, 'password_hash' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'role' => 'admin', 'is_active' => 1, 'created_at' => '2026-08-11 17:08:28'],
            ['id' => 2, 'username' => 'aankasir', 'nama' => null, 'inisial' => null, 'divisi' => null, 'no_hp' => null, 'profile_photo' => null, 'password_hash' => '$2y$10$NBQoq2eXgAA2GdbS4WA68unFyOP0MJctHsixTaRJ/47KB4ffNImke', 'role' => 'kasir', 'is_active' => 1, 'created_at' => '2026-08-25 19:32:27'],
            ['id' => 3, 'username' => 'aan', 'nama' => 'Anshar', 'inisial' => 'ANS', 'divisi' => null, 'no_hp' => null, 'profile_photo' => null, 'password_hash' => '$2y$10$Zjj2KgOdNvqqnKILPUClXuAx.hIiq94WbJDiBCTEgp4cnev1kynTK', 'role' => 'admin', 'is_active' => 1, 'created_at' => '2026-08-29 13:31:09'],
            ['id' => 4, 'username' => 'epo', 'nama' => 'Sayiful', 'inisial' => 'EPO', 'divisi' => 'Pria', 'no_hp' => null, 'profile_photo' => null, 'password_hash' => '$2y$10$NE/HQXbyvsOaX4c9bb4sLesJnQzEkMp2HyoPi5WhFbqft2I7z5A6C', 'role' => 'kasir', 'is_active' => 1, 'created_at' => '2026-08-29 13:31:09'],
            ['id' => 5, 'username' => 'yud', 'nama' => 'Wahyudi', 'inisial' => 'YUD', 'divisi' => 'Banner', 'no_hp' => null, 'profile_photo' => null, 'password_hash' => '$2y$10$7EIbALzQEdDqZppwOQd1XOizUhApkt57hotT2i4GHWQaoKdN3.GSO', 'role' => 'kasir', 'is_active' => 1, 'created_at' => '2026-08-29 13:31:09'],
            ['id' => 6, 'username' => 'yan', 'nama' => 'Yulianto', 'inisial' => 'YAN', 'divisi' => 'Pria', 'no_hp' => null, 'profile_photo' => null, 'password_hash' => '$2y$10$FBEuDUN59O/qGa0Rz1qCBeFUP8ibbovnOgtdRT6abvHFR1dakh50O', 'role' => 'kasir', 'is_active' => 1, 'created_at' => '2026-08-29 13:31:09'],
            ['id' => 7, 'username' => 'wah', 'nama' => 'Wahyu', 'inisial' => 'WAH', 'divisi' => 'Pria', 'no_hp' => null, 'profile_photo' => null, 'password_hash' => '$2y$10$srYzUN2ln34LV/oflIVMre0f05e5WygK2y9YC9ZX8PvytGvCqefTu', 'role' => 'kasir', 'is_active' => 1, 'created_at' => '2026-08-29 13:31:09'],
            ['id' => 8, 'username' => 'mad', 'nama' => 'Muhammad', 'inisial' => 'MAD', 'divisi' => 'Pria', 'no_hp' => null, 'profile_photo' => null, 'password_hash' => '$2y$10$DBkVHSzJQHqeNihTKXnvm.V/uXOtTGjl5mqgWwjtGkfH2emN62ua6', 'role' => 'kasir', 'is_active' => 1, 'created_at' => '2026-08-29 13:31:09'],
            ['id' => 9, 'username' => 'rob', 'nama' => 'Robbi', 'inisial' => 'ROB', 'divisi' => 'Pria', 'no_hp' => null, 'profile_photo' => null, 'password_hash' => '$2y$10$RkT1QsbIbZ1QWyJUgjzGMeqTcO.OkLZ7mroT0Hdp9tp6X7BBg412a', 'role' => 'kasir', 'is_active' => 1, 'created_at' => '2026-08-29 13:31:09'],
            ['id' => 10, 'username' => 'kar', 'nama' => 'Karim', 'inisial' => 'KAR', 'divisi' => 'Pria', 'no_hp' => null, 'profile_photo' => null, 'password_hash' => '$2y$10$3fGPMrLjLC/VKO4nb3mvT.Sz2.KG9h7IToNxF7OlLZsP2UEC2vcCS', 'role' => 'kasir', 'is_active' => 1, 'created_at' => '2026-08-29 13:31:09'],
            ['id' => 11, 'username' => 'roh', 'nama' => 'Rohmah', 'inisial' => 'ROH', 'divisi' => 'Wanita', 'no_hp' => null, 'profile_photo' => null, 'password_hash' => '$2y$10$SCQGmBYUCuhF/LB8HTZvh.NKM6CmQtBzdyb2XAPOh/j0tjTr9Q60C', 'role' => 'kasir', 'is_active' => 1, 'created_at' => '2026-08-29 13:31:09'],
            ['id' => 12, 'username' => 'ila', 'nama' => 'Ila', 'inisial' => 'ILA', 'divisi' => 'Wanita', 'no_hp' => null, 'profile_photo' => null, 'password_hash' => '$2y$10$4D5sIwGQtVs1Zozseh970eZJJkMStDh.lGuNydXSZml.5N3ktHeri', 'role' => 'kasir', 'is_active' => 1, 'created_at' => '2026-08-29 13:31:09'],
            ['id' => 13, 'username' => 'beb', 'nama' => 'Bibeh', 'inisial' => 'BEB', 'divisi' => 'Wanita', 'no_hp' => null, 'profile_photo' => null, 'password_hash' => '$2y$10$6lpMrZS.a3.yosqSySujbepPYrK7u41nhJY.iL5a9SaMopQctMz3.', 'role' => 'kasir', 'is_active' => 1, 'created_at' => '2026-08-29 13:31:09'],
            ['id' => 14, 'username' => 'rum', 'nama' => 'Rum', 'inisial' => 'RUM', 'divisi' => 'Wanita', 'no_hp' => null, 'profile_photo' => null, 'password_hash' => '$2y$10$eUHRhiUhMm12Z/gifB9BGug0pZcf9k.8Yba9XhfRJdzVep4Gm/Hpy', 'role' => 'kasir', 'is_active' => 1, 'created_at' => '2026-08-29 13:31:09'],
            ['id' => 15, 'username' => 'tin', 'nama' => 'Titin', 'inisial' => 'TIN', 'divisi' => 'Wanita', 'no_hp' => null, 'profile_photo' => null, 'password_hash' => '$2y$10$Zai.8q7pIvouRs1H2Ho1au5p.vakyEBDvUF0GYN.hZ3KEqYMykWYq', 'role' => 'kasir', 'is_active' => 1, 'created_at' => '2026-08-29 13:31:09'],
            ['id' => 16, 'username' => 'far', 'nama' => 'Fari', 'inisial' => 'FAR', 'divisi' => 'Banner', 'no_hp' => null, 'profile_photo' => null, 'password_hash' => '$2y$10$bVHKQIXkOCC7UKDrU0V6hOR7fk5wYXeiK1IVCm2YRsGIstYjmNZsy', 'role' => 'kasir', 'is_active' => 1, 'created_at' => '2026-08-29 13:31:09'],
        ];

        $kategori = [
            ['id' => 1, 'nama' => 'Penjualan Umum', 'parent_id' => null, 'created_at' => '2026-08-11 17:08:28', 'updated_at' => '2026-08-11 17:08:28'],
            ['id' => 2, 'nama' => 'Fotokopi', 'parent_id' => null, 'created_at' => '2026-08-11 17:08:28', 'updated_at' => '2026-08-11 17:08:28'],
            ['id' => 3, 'nama' => 'Minuman', 'parent_id' => null, 'created_at' => '2026-08-11 17:08:28', 'updated_at' => '2026-08-11 17:08:28'],
            ['id' => 4, 'nama' => 'Digital Printing', 'parent_id' => null, 'created_at' => '2026-08-11 17:08:28', 'updated_at' => '2026-08-11 17:08:28'],
            ['id' => 16, 'nama' => 'Foto', 'parent_id' => null, 'created_at' => '2026-08-15 05:40:26', 'updated_at' => '2026-08-15 05:40:26'],
        ];

        $produk = [
            ['id' => 1, 'barcode' => null, 'nama' => 'Banner', 'kategori_id' => 4, 'satuan' => 'pcs', 'sort_order' => 0, 'panjang' => null, 'lebar' => null, 'harga_jual' => '0.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-12 15:40:08', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 2, 'barcode' => null, 'nama' => 'Penjualan Manual', 'kategori_id' => 1, 'satuan' => 'item', 'sort_order' => 0, 'panjang' => null, 'lebar' => null, 'harga_jual' => '0.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-12 15:40:08', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 4, 'barcode' => null, 'nama' => 'Ukuran Custom', 'kategori_id' => 16, 'satuan' => 'pcs', 'sort_order' => 0, 'panjang' => null, 'lebar' => null, 'harga_jual' => '0.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-13 08:45:27', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 51, 'barcode' => null, 'nama' => 'Studio', 'kategori_id' => 16, 'satuan' => 'paket', 'sort_order' => 1, 'panjang' => 0, 'lebar' => 0, 'harga_jual' => '20000.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 52, 'barcode' => null, 'nama' => 'Studio tanpa cd', 'kategori_id' => 16, 'satuan' => 'paket', 'sort_order' => 2, 'panjang' => 0, 'lebar' => 0, 'harga_jual' => '15000.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 53, 'barcode' => null, 'nama' => 'Studio foto ke 2 dst', 'kategori_id' => 16, 'satuan' => 'paket', 'sort_order' => 3, 'panjang' => 0, 'lebar' => 0, 'harga_jual' => '10000.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 54, 'barcode' => null, 'nama' => 'Scan', 'kategori_id' => 16, 'satuan' => 'paket', 'sort_order' => 4, 'panjang' => 0, 'lebar' => 0, 'harga_jual' => '1000.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 55, 'barcode' => null, 'nama' => 'Ganti Background', 'kategori_id' => 16, 'satuan' => 'paket', 'sort_order' => 5, 'panjang' => 0, 'lebar' => 0, 'harga_jual' => '2000.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 56, 'barcode' => null, 'nama' => 'Paket Studio 1', 'kategori_id' => 16, 'satuan' => 'paket', 'sort_order' => 1, 'panjang' => 0, 'lebar' => 0, 'harga_jual' => '50000.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 57, 'barcode' => null, 'nama' => 'Paket Studio 2', 'kategori_id' => 16, 'satuan' => 'paket', 'sort_order' => 1, 'panjang' => 0, 'lebar' => 0, 'harga_jual' => '100000.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 58, 'barcode' => null, 'nama' => 'Paket Studio 3', 'kategori_id' => 16, 'satuan' => 'paket', 'sort_order' => 1, 'panjang' => 0, 'lebar' => 0, 'harga_jual' => '200000.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 59, 'barcode' => null, 'nama' => 'Edit Tambahan', 'kategori_id' => 16, 'satuan' => 'paket', 'sort_order' => 7, 'panjang' => 0, 'lebar' => 0, 'harga_jual' => '5000.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 60, 'barcode' => null, 'nama' => '2X3', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 6, 'panjang' => 22, 'lebar' => 29, 'harga_jual' => '600.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 61, 'barcode' => null, 'nama' => '3x3', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 7, 'panjang' => 32, 'lebar' => 32, 'harga_jual' => '800.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 62, 'barcode' => null, 'nama' => '3x4', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 8, 'panjang' => 32, 'lebar' => 42, 'harga_jual' => '800.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 63, 'barcode' => null, 'nama' => '3x4 VISA', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 9, 'panjang' => 35, 'lebar' => 45, 'harga_jual' => '900.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 64, 'barcode' => null, 'nama' => '4x6', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 10, 'panjang' => 40, 'lebar' => 56, 'harga_jual' => '1000.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 65, 'barcode' => null, 'nama' => '4x6 VISA', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 11, 'panjang' => 45, 'lebar' => 60, 'harga_jual' => '1100.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 66, 'barcode' => null, 'nama' => '5x5', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 12, 'panjang' => 52, 'lebar' => 52, 'harga_jual' => '1100.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 67, 'barcode' => null, 'nama' => '6x6', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 13, 'panjang' => 62, 'lebar' => 62, 'harga_jual' => '1100.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 68, 'barcode' => null, 'nama' => 'DOMPET', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 14, 'panjang' => 55, 'lebar' => 75, 'harga_jual' => '1100.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 69, 'barcode' => null, 'nama' => 'POLAROID KCL', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 15, 'panjang' => 60, 'lebar' => 73, 'harga_jual' => '1200.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 70, 'barcode' => null, 'nama' => 'POLAROID', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 16, 'panjang' => 89, 'lebar' => 108, 'harga_jual' => '1800.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 71, 'barcode' => null, 'nama' => '2R', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 17, 'panjang' => 60, 'lebar' => 90, 'harga_jual' => '1200.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 72, 'barcode' => null, 'nama' => '3R', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 18, 'panjang' => 89, 'lebar' => 127, 'harga_jual' => '2000.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 73, 'barcode' => null, 'nama' => '4R', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 19, 'panjang' => 102, 'lebar' => 152, 'harga_jual' => '2500.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 74, 'barcode' => null, 'nama' => '5R', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 20, 'panjang' => 127, 'lebar' => 178, 'harga_jual' => '4500.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 75, 'barcode' => null, 'nama' => '6R', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 21, 'panjang' => 152, 'lebar' => 203, 'harga_jual' => '7500.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 76, 'barcode' => null, 'nama' => '10R', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 22, 'panjang' => 200, 'lebar' => 250, 'harga_jual' => '11000.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 77, 'barcode' => null, 'nama' => '10RS', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 23, 'panjang' => 200, 'lebar' => 300, 'harga_jual' => '13000.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 78, 'barcode' => null, 'nama' => '10R FULL', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 24, 'panjang' => 250, 'lebar' => 300, 'harga_jual' => '16000.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 79, 'barcode' => null, 'nama' => '10RS FULL', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 25, 'panjang' => 250, 'lebar' => 350, 'harga_jual' => '20000.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 80, 'barcode' => null, 'nama' => '12R', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 26, 'panjang' => 300, 'lebar' => 400, 'harga_jual' => '34000.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 81, 'barcode' => null, 'nama' => '12R SALON', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 27, 'panjang' => 300, 'lebar' => 450, 'harga_jual' => '38000.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 82, 'barcode' => null, 'nama' => '16R', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 28, 'panjang' => 400, 'lebar' => 500, 'harga_jual' => '65000.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 83, 'barcode' => null, 'nama' => '16R SALON', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 29, 'panjang' => 400, 'lebar' => 600, 'harga_jual' => '80000.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 84, 'barcode' => null, 'nama' => '20R', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 30, 'panjang' => 500, 'lebar' => 600, 'harga_jual' => '95000.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 85, 'barcode' => null, 'nama' => '20R SALON', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 31, 'panjang' => 500, 'lebar' => 750, 'harga_jual' => '115000.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 86, 'barcode' => null, 'nama' => '24R', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 32, 'panjang' => 600, 'lebar' => 800, 'harga_jual' => '175000.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
            ['id' => 87, 'barcode' => null, 'nama' => '24R SALON', 'kategori_id' => 16, 'satuan' => 'lembar', 'sort_order' => 33, 'panjang' => 600, 'lebar' => 900, 'harga_jual' => '195000.00', 'harga_beli' => '0.00', 'bonus_rule_id' => null, 'stok' => 0, 'is_active' => 1, 'is_locked' => 1, 'created_at' => '2026-08-15 13:38:57', 'updated_at' => '2026-09-05 02:26:57'],
        ];

        // This seeder is intended for a fresh baseline database. Do not
        // silently overwrite existing operational/master data.
        foreach (['users' => $users, 'kategori' => $kategori, 'produk' => $produk] as $table => $rows) {
            if ($db->table($table)->countAllResults() > 0) {
                throw new \RuntimeException(
                    "Initial seeder dibatalkan: tabel {$table} sudah berisi data. " .
                    "Gunakan hanya pada database baseline yang masih kosong."
                );
            }
        }

        $db->transStart();

        $db->table('users')->insertBatch($users);
        $db->table('kategori')->insertBatch($kategori);
        $db->table('produk')->insertBatch($produk);

        // Keep future AUTO_INCREMENT values above the explicit baseline IDs.
        $db->query('ALTER TABLE `users` AUTO_INCREMENT = 17');
        $db->query('ALTER TABLE `kategori` AUTO_INCREMENT = 17');
        $db->query('ALTER TABLE `produk` AUTO_INCREMENT = 88');

        $db->transComplete();

        if ($db->transStatus() === false) {
            throw new \RuntimeException('Initial seeder gagal dan transaksi database dibatalkan.');
        }
    }
}
