<?php
namespace amici\SuperMailer\migrations;

use craft\db\Migration;

class m260520_073500_add_email_logs extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%super_mailer_email_logs}}')) {
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

            if ($this->db->tableExists('{{%super_mailer_notifications}}')) {
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

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%super_mailer_email_logs}}');
        return true;
    }
}
