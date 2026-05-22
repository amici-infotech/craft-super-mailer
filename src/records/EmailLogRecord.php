<?php
namespace amici\SuperMailer\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int|null $notificationId
 * @property string|null $notificationTitle
 * @property string $status
 * @property string|null $error
 * @property string|null $subject
 * @property string|null $toEmails
 * @property string|null $ccEmails
 * @property string|null $bccEmails
 * @property string|null $fromEmail
 * @property string|null $replyTo
 * @property string|null $htmlTemplatePath
 * @property string|null $plainTextTemplatePath
 * @property string|null $eventClass
 * @property string|null $eventName
 * @property string|null $senderClass
 * @property string|null $elementType
 * @property int|null $elementId
 * @property int|null $siteId
 * @property string|null $eventContext
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class EmailLogRecord extends ActiveRecord
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    /**
     * Returns the database table name used by this ActiveRecord.
     *
     * @return string Return value produced by this method.
     */
    public static function tableName(): string
    {
        return '{{%super_mailer_email_logs}}';
    }

    /**
     * Defines validation rules for this model or record.
     *
     * @return array Return value produced by this method.
     */
    public function rules(): array
    {
        return [
            [['status'], 'required'],
            [['notificationId', 'elementId', 'siteId'], 'integer'],
            [['error', 'toEmails', 'ccEmails', 'bccEmails', 'eventContext'], 'string'],
            [['status'], 'in', 'range' => [self::STATUS_SUCCESS, self::STATUS_FAILED]],
            [['notificationTitle', 'subject', 'fromEmail', 'replyTo', 'htmlTemplatePath', 'plainTextTemplatePath', 'eventClass', 'eventName', 'senderClass', 'elementType'], 'string', 'max' => 255],
        ];
    }
}
