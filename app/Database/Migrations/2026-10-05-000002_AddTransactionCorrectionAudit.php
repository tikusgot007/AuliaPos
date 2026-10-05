<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTransactionCorrectionAudit extends Migration
{
    public function up()
    {
        $this->db->query(<<<'SQL'
CREATE TABLE IF NOT EXISTS payment_correction_audit (
  id BIGINT NOT NULL AUTO_INCREMENT,
  transaksi_id INT NOT NULL,
  pembayaran_lama_id INT NOT NULL,
  pembayaran_baru_id INT NOT NULL,
  metode_lama VARCHAR(20) NOT NULL,
  metode_baru VARCHAR(20) NOT NULL,
  operator_id INT NOT NULL,
  alasan VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pca_transaksi (transaksi_id),
  KEY idx_pca_pembayaran_lama (pembayaran_lama_id),
  KEY idx_pca_pembayaran_baru (pembayaran_baru_id),
  KEY idx_pca_operator (operator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE IF NOT EXISTS transaction_correction (
  id BIGINT NOT NULL AUTO_INCREMENT,
  original_transaction_id INT NOT NULL,
  replacement_transaction_id INT NOT NULL,
  operator_id INT NOT NULL,
  reason VARCHAR(255) NOT NULL,
  financial_delta DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tc_original (original_transaction_id),
  UNIQUE KEY uq_tc_replacement (replacement_transaction_id),
  KEY idx_tc_operator (operator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL);
    }

    public function down()
    {
        $this->db->query('DROP TABLE IF EXISTS transaction_correction');
        $this->db->query('DROP TABLE IF EXISTS payment_correction_audit');
    }
}
