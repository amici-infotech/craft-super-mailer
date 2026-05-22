<?php
namespace amici\SuperMailer\controllers;

use amici\SuperMailer\elements\MailerNotification;
use amici\SuperMailer\records\EmailLogRecord;
use amici\SuperMailer\Plugin;
use Craft;
use craft\db\Query;
use craft\helpers\Json;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Controller for email log browsing, detail views, deletion, resending, and console log maintenance, depending on namespace.
 */
class LogsController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    /**
     * Renders the default listing screen for this controller.
     *
     * @return Response Return value produced by this method.
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('super-mailer:view-notifications');

        $logs = (new Query())
            ->from(EmailLogRecord::tableName())
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit(100)
            ->all();

        foreach ($logs as &$log) {
            $log['toEmailsList'] = $this->decodeList($log['toEmails'] ?? null);
            $log['ccEmailsList'] = $this->decodeList($log['ccEmails'] ?? null);
            $log['bccEmailsList'] = $this->decodeList($log['bccEmails'] ?? null);
        }
        unset($log);

        return $this->renderTemplate('super-mailer/logs/index', [
            'logs' => $logs,
        ]);
    }

    /**
     * Renders a detailed email log page with decoded context and rendered output.
     *
     * @param int $logId logId value used by this method.
     * @return Response Return value produced by this method.
     */
    public function actionView(int $logId): Response
    {
        $this->requirePermission('super-mailer:view-notifications');

        $log = EmailLogRecord::findOne($logId);
        if (!$log instanceof EmailLogRecord) {
            throw new NotFoundHttpException('Email log not found');
        }

        $eventContext = $this->decodeValue($log->eventContext);
        $notification = $log->notificationId
            ? MailerNotification::find()->id((int)$log->notificationId)->status(null)->one()
            : null;
        $renderedPreview = $notification instanceof MailerNotification && is_array($eventContext)
            ? Plugin::getInstance()->getMailer()->renderPreviewFromContext($notification, $eventContext)
            : null;

        return $this->renderTemplate('super-mailer/logs/view', [
            'log' => $log,
            'toEmailsList' => $this->decodeList($log->toEmails),
            'ccEmailsList' => $this->decodeList($log->ccEmails),
            'bccEmailsList' => $this->decodeList($log->bccEmails),
            'fromEmail' => $this->decodeValue($log->fromEmail),
            'replyTo' => $this->decodeValue($log->replyTo),
            'eventContext' => $eventContext,
            'renderedPreview' => $renderedPreview,
        ]);
    }

    /**
     * Deletes the requested notification or email log after permission checks.
     *
     * @return ?Response Return value produced by this method.
     */
    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('super-mailer:manage-notifications');

        $ids = $this->requestedLogIds();
        $deleted = Plugin::getInstance()->getLogs()->deleteByIds(is_array($ids) ? $ids : [$ids]);

        Craft::$app->getSession()->setNotice(Craft::t('super-mailer', 'Deleted {count} email log(s).', [
            'count' => $deleted,
        ]));

        return $this->redirectToPostedUrl();
    }

    /**
     * Queues selected email logs for resend.
     *
     * @return ?Response Return value produced by this method.
     */
    public function actionResend(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('super-mailer:manage-notifications');

        $ids = $this->requestedLogIds();
        $result = Plugin::getInstance()->getLogs()->resendByIds(is_array($ids) ? $ids : [$ids]);

        if ($result['queued'] > 0) {
            Craft::$app->getSession()->setNotice(Craft::t('super-mailer', 'Queued {queued} email(s) for resend. {skipped} skipped.', [
                'queued' => $result['queued'],
                'skipped' => $result['skipped'],
            ]));
        } else {
            Craft::$app->getSession()->setError(Craft::t('super-mailer', 'Could not queue any emails for resend.'));
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Deletes all email logs from the Control Panel.
     *
     * @return ?Response Return value produced by this method.
     */
    public function actionDeleteAll(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('super-mailer:manage-notifications');

        $deleted = Plugin::getInstance()->getLogs()->purgeAll();
        Craft::$app->getSession()->setNotice(Craft::t('super-mailer', 'Deleted {count} email log(s).', [
            'count' => $deleted,
        ]));

        return $this->redirectToPostedUrl();
    }

    /**
     * Decodes a JSON-stored email list value into an array for display.
     *
     * @param string $value value value used by this method.
     * @return array Return value produced by this method.
     */
    private function decodeList(?string $value): array
    {
        if (!$value) {
            return [];
        }

        try {
            $decoded = Json::decode($value);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Decodes a JSON-stored log value, falling back to the raw value when needed.
     *
     * @param string $value value value used by this method.
     * @return mixed Return value produced by this method.
     */
    private function decodeValue(?string $value): mixed
    {
        if (!$value) {
            return null;
        }

        try {
            return Json::decode($value);
        } catch (\Throwable) {
            return $value;
        }
    }

    /**
     * Combines bulk and single log ID POST values into one ID list.
     *
     * @return array Return value produced by this method.
     */
    private function requestedLogIds(): array
    {
        $ids = Craft::$app->getRequest()->getBodyParam('logIds', []);
        $singleId = Craft::$app->getRequest()->getBodyParam('singleLogId');
        $ids = is_array($ids) ? $ids : [$ids];

        if ($singleId) {
            $ids[] = $singleId;
        }

        return $ids;
    }
}
