<?php
namespace amici\SuperMailer\migrations;

use craft\db\Migration;

/**
 * Migration that adds table-based condition settings to existing notification records.
 */
class m260518_154000_add_notification_conditions extends Migration
{
    /**
     * Applies this database migration safely.
     *
     * @return bool Return value produced by this method.
     */
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%super_mailer_notifications}}', 'conditionMatchMode')) {
            $this->addColumn(
                '{{%super_mailer_notifications}}',
                'conditionMatchMode',
                $this->string(8)->notNull()->defaultValue('all')->after('plainTextTemplatePath')
            );
        }

        if (!$this->db->columnExists('{{%super_mailer_notifications}}', 'conditionRules')) {
            $this->addColumn(
                '{{%super_mailer_notifications}}',
                'conditionRules',
                $this->text()->after('conditionMatchMode')
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
        if ($this->db->columnExists('{{%super_mailer_notifications}}', 'conditionRules')) {
            $this->dropColumn('{{%super_mailer_notifications}}', 'conditionRules');
        }

        if ($this->db->columnExists('{{%super_mailer_notifications}}', 'conditionMatchMode')) {
            $this->dropColumn('{{%super_mailer_notifications}}', 'conditionMatchMode');
        }

        return true;
    }
}
