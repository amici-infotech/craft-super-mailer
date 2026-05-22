<?php
namespace amici\SuperMailer\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

/**
 * Custom element query for filtering and hydrating Super Mailer notification elements from their record table.
 */
class MailerNotificationQuery extends ElementQuery
{
    public mixed $handle = null;
    public mixed $eventClass = null;
    public mixed $eventConstant = null;
    public mixed $eventName = null;
    public mixed $enabledNotification = null;

    /**
     * Handles handle behavior for this Super Mailer component.
     *
     * @param mixed $value value value used by this method.
     * @return static Return value produced by this method.
     */
    public function handle(mixed $value): static
    {
        $this->handle = $value;
        return $this;
    }

    /**
     * Handles event class behavior for this Super Mailer component.
     *
     * @param mixed $value value value used by this method.
     * @return static Return value produced by this method.
     */
    public function eventClass(mixed $value): static
    {
        $this->eventClass = $value;
        return $this;
    }

    /**
     * Handles event constant behavior for this Super Mailer component.
     *
     * @param mixed $value value value used by this method.
     * @return static Return value produced by this method.
     */
    public function eventConstant(mixed $value): static
    {
        $this->eventConstant = $value;
        return $this;
    }

    /**
     * Handles event name behavior for this Super Mailer component.
     *
     * @param mixed $value value value used by this method.
     * @return static Return value produced by this method.
     */
    public function eventName(mixed $value): static
    {
        $this->eventName = $value;
        return $this;
    }

    /**
     * Handles enabled notification behavior for this Super Mailer component.
     *
     * @param mixed $value value value used by this method.
     * @return static Return value produced by this method.
     */
    public function enabledNotification(mixed $value = true): static
    {
        $this->enabledNotification = $value;
        return $this;
    }

    /**
     * Handles before prepare behavior for this Super Mailer component.
     *
     * @return bool Return value produced by this method.
     */
    protected function beforePrepare(): bool
    {
        $this->joinElementTable('super_mailer_notifications');

        if ($this->handle !== null) {
            $this->subQuery->andWhere(Db::parseParam('super_mailer_notifications.handle', $this->handle));
        }

        if ($this->eventClass !== null) {
            $this->subQuery->andWhere(Db::parseParam('super_mailer_notifications.eventClass', $this->eventClass));
        }

        if ($this->eventConstant !== null) {
            $this->subQuery->andWhere(Db::parseParam('super_mailer_notifications.eventConstant', $this->eventConstant));
        }

        if ($this->eventName !== null) {
            $this->subQuery->andWhere(Db::parseParam('super_mailer_notifications.eventName', $this->eventName));
        }

        if ($this->enabledNotification !== null) {
            $this->subQuery->andWhere(Db::parseParam('super_mailer_notifications.enabled', $this->enabledNotification));
        }

        $this->query->select([
            'super_mailer_notifications.handle',
            'super_mailer_notifications.eventClass',
            'super_mailer_notifications.eventConstant',
            'super_mailer_notifications.eventName',
            'super_mailer_notifications.toEmails',
            'super_mailer_notifications.ccEmails',
            'super_mailer_notifications.bccEmails',
            'super_mailer_notifications.fromEmail',
            'super_mailer_notifications.fromName',
            'super_mailer_notifications.replyTo',
            'super_mailer_notifications.emailSubject',
            'super_mailer_notifications.htmlTemplatePath',
            'super_mailer_notifications.plainTextTemplatePath',
            'super_mailer_notifications.conditionMatchMode',
            'super_mailer_notifications.conditionRules',
            'super_mailer_notifications.phpCondition',
            'super_mailer_notifications.enabled AS enabledNotification',
        ]);

        return parent::beforePrepare();
    }
}
