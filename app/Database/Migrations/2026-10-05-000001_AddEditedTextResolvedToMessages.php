<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TODO-F8 Phase 2 -- persist whether an edited message text was successfully
 * resolved and stored by AuliaPos.
 *
 * The marker belongs to POS only; no Gateway cryptographic detail is stored.
 * Existing rows remain NULL because there is no backfill.
 */
class AddEditedTextResolvedToMessages extends Migration
{
    protected $DBGroup = 'inbox';

    private const TABLE = 'messages';
    private const COLUMN = 'edited_text_resolved_at';

    public function up()
    {
        if (! $this->columnExists(self::COLUMN)) {
            $this->forge->addColumn(self::TABLE, [
                self::COLUMN => [
                    'type'  => 'DATETIME',
                    'null'  => true,
                    'after' => 'edited_at',
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->columnExists(self::COLUMN)) {
            $this->forge->dropColumn(self::TABLE, self::COLUMN);
        }
    }

    /**
     * Check information_schema directly so a DDL change made in this migration
     * is visible immediately even if CodeIgniter's field cache is stale.
     */
    private function columnExists(string $column): bool
    {
        $row = $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$this->db->database, self::TABLE, $column]
        )->getRowArray();

        return $row !== null;
    }
}
