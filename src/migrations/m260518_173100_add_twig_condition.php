<?php
namespace amici\SuperMailer\migrations;

use craft\db\Migration;

class m260518_173100_add_twig_condition extends Migration
{
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

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%super_mailer_notifications}}', 'phpCondition')) {
            $this->dropColumn('{{%super_mailer_notifications}}', 'phpCondition');
        }

        return true;
    }
}
