<?php
namespace amici\SuperMailer\migrations;

use craft\db\Migration;

/**
 * Migration that replaces the legacy Twig condition column with PHP condition support.
 */
class m260518_202800_replace_twig_condition_with_php_condition extends Migration
{
    /**
     * Applies this database migration safely.
     *
     * @return bool Return value produced by this method.
     */
    public function safeUp(): bool
    {
        $table = '{{%super_mailer_notifications}}';

        if ($this->db->columnExists($table, 'twigCondition') && !$this->db->columnExists($table, 'phpCondition')) {
            $this->renameColumn($table, 'twigCondition', 'phpCondition');
            return true;
        }

        if (!$this->db->columnExists($table, 'phpCondition')) {
            $this->addColumn($table, 'phpCondition', $this->text()->after('conditionRules'));
        }

        if ($this->db->columnExists($table, 'twigCondition')) {
            $this->dropColumn($table, 'twigCondition');
        }

        return true;
    }

    /**
     * Reverts this database migration safely.
     *
     * @return bool Return value produced by this method.
     */
    public function safeDown(): bool
    {
        $table = '{{%super_mailer_notifications}}';

        if ($this->db->columnExists($table, 'phpCondition') && !$this->db->columnExists($table, 'twigCondition')) {
            $this->renameColumn($table, 'phpCondition', 'twigCondition');
            return true;
        }

        if (!$this->db->columnExists($table, 'twigCondition')) {
            $this->addColumn($table, 'twigCondition', $this->text()->after('conditionRules'));
        }

        if ($this->db->columnExists($table, 'phpCondition')) {
            $this->dropColumn($table, 'phpCondition');
        }

        return true;
    }
}
