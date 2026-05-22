<?php
namespace amici\SuperMailer\migrations;

use craft\db\Migration;

/**
 * Migration that adds the legacy Twig condition column used by earlier notification builds.
 */
class m260518_173100_add_twig_condition extends Migration
{
    /**
     * Applies this database migration safely.
     *
     * @return bool Return value produced by this method.
     */
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%super_mailer_notifications}}', 'phpCondition')) {
            $this->addColumn(
                '{{%super_mailer_notifications}}',
                'phpCondition',
                $this->text()->after('conditionRules')
            );
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
        if ($this->db->columnExists('{{%super_mailer_notifications}}', 'phpCondition')) {
            $this->dropColumn('{{%super_mailer_notifications}}', 'phpCondition');
        }

        return true;
    }
}
