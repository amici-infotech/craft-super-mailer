<?php
namespace amici\SuperMailer\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%super_mailer_notifications}}')) {
            $this->createTable('{{%super_mailer_notifications}}', [
                'id' => $this->primaryKey(),
                'handle' => $this->string()->notNull(),
                'eventClass' => $this->string()->notNull(),
                'eventConstant' => $this->string()->notNull(),
                'eventName' => $this->string()->notNull(),
                'toEmails' => $this->text()->notNull(),
                'ccEmails' => $this->text(),
                'bccEmails' => $this->text(),
                'fromEmail' => $this->string(),
                'fromName' => $this->string(),
                'replyTo' => $this->string(),
                'emailSubject' => $this->string()->notNull(),
                'htmlTemplatePath' => $this->string(),
                'plainTextTemplatePath' => $this->string(),
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%super_mailer_notifications}}', ['handle'], true);
            $this->createIndex(null, '{{%super_mailer_notifications}}', ['eventClass', 'eventName'], false);
            $this->createIndex(null, '{{%super_mailer_notifications}}', ['enabled'], false);
            $this->addForeignKey(
                null,
                '{{%super_mailer_notifications}}',
                ['id'],
                '{{%elements}}',
                ['id'],
                'CASCADE',
                'CASCADE'
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%super_mailer_notifications}}');
        return true;
    }
}
