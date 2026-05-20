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
                'conditionMatchMode' => $this->string(8)->notNull()->defaultValue('all'),
                'conditionRules' => $this->text(),
                'phpCondition' => $this->text(),
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

        $this->createEmailLogsTable();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%super_mailer_email_logs}}');
        $this->dropTableIfExists('{{%super_mailer_notifications}}');
        return true;
    }

    private function createEmailLogsTable(): void
    {
        if ($this->db->tableExists('{{%super_mailer_email_logs}}')) {
            return;
        }

        $this->createTable('{{%super_mailer_email_logs}}', [
            'id' => $this->primaryKey(),
            'notificationId' => $this->integer(),
            'notificationTitle' => $this->string(),
            'status' => $this->string(16)->notNull(),
            'error' => $this->mediumText(),
            'subject' => $this->string(),
            'toEmails' => $this->mediumText(),
            'ccEmails' => $this->mediumText(),
            'bccEmails' => $this->mediumText(),
            'fromEmail' => $this->text(),
            'replyTo' => $this->text(),
            'htmlTemplatePath' => $this->string(),
            'plainTextTemplatePath' => $this->string(),
            'eventClass' => $this->string(),
            'eventName' => $this->string(),
            'senderClass' => $this->string(),
            'elementType' => $this->string(),
            'elementId' => $this->integer(),
            'siteId' => $this->integer(),
            'eventContext' => $this->mediumText(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%super_mailer_email_logs}}', ['notificationId'], false);
        $this->createIndex(null, '{{%super_mailer_email_logs}}', ['status'], false);
        $this->createIndex(null, '{{%super_mailer_email_logs}}', ['dateCreated'], false);
        $this->createIndex(null, '{{%super_mailer_email_logs}}', ['elementType', 'elementId'], false);
        $this->addForeignKey(
            null,
            '{{%super_mailer_email_logs}}',
            ['notificationId'],
            '{{%super_mailer_notifications}}',
            ['id'],
            'SET NULL',
            'CASCADE'
        );
    }
}
