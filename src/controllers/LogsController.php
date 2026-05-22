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

        $request = Craft::$app->getRequest();
        $filters = [
            'status' => (string)$request->getQueryParam('status', ''),
            'notificationId' => (string)$request->getQueryParam('notificationId', ''),
            'dateFrom' => $this->dateFilterValue($request->getQueryParam('dateFrom', '')),
            'dateTo' => $this->dateFilterValue($request->getQueryParam('dateTo', '')),
        ];
        $limit = 50;
        $currentPage = max(1, (int)$request->getQueryParam('page', 1));
        $query = (new Query())->from(EmailLogRecord::tableName());

        if (in_array($filters['status'], [EmailLogRecord::STATUS_SUCCESS, EmailLogRecord::STATUS_FAILED], true)) {
            $query->andWhere(['status' => $filters['status']]);
        }

        if ($filters['notificationId'] !== '' && ctype_digit($filters['notificationId'])) {
            $query->andWhere(['notificationId' => (int)$filters['notificationId']]);
        }

        if ($filters['dateFrom'] !== '') {
            try {
                $query->andWhere(['>=', 'dateCreated', (new \DateTimeImmutable($filters['dateFrom'] . ' 00:00:00'))->format('Y-m-d H:i:s')]);
            } catch (\Throwable) {
                $filters['dateFrom'] = '';
            }
        }

        if ($filters['dateTo'] !== '') {
            try {
                $query->andWhere(['<=', 'dateCreated', (new \DateTimeImmutable($filters['dateTo'] . ' 23:59:59'))->format('Y-m-d H:i:s')]);
            } catch (\Throwable) {
                $filters['dateTo'] = '';
            }
        }

        $total = (int)(clone $query)->count();
        $totalPages = max(1, (int)ceil($total / $limit));
        $currentPage = min($currentPage, $totalPages);

        $logs = $query
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->offset(($currentPage - 1) * $limit)
            ->all();

        foreach ($logs as &$log) {
            $log['toEmailsList'] = $this->decodeList($log['toEmails'] ?? null);
            $log['ccEmailsList'] = $this->decodeList($log['ccEmails'] ?? null);
            $log['bccEmailsList'] = $this->decodeList($log['bccEmails'] ?? null);
        }
        unset($log);

        return $this->renderTemplate('super-mailer/logs/index', [
            'logs' => $logs,
            'filters' => $filters,
            'notificationOptions' => $this->notificationOptions(),
            'pagination' => [
                'currentPage' => $currentPage,
                'totalPages' => $totalPages,
                'total' => $total,
                'limit' => $limit,
            ],
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
     * Normalizes a logs date filter from Craft's date field query structure.
     *
     * @param mixed $value Raw query parameter value.
     * @return string Date string suitable for display and query parsing.
     */
    private function dateFilterValue(mixed $value): string
    {
        if (is_array($value)) {
            return trim((string)($value['date'] ?? ''));
        }

        return trim((string)$value);
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

    /**
     * Builds notification filter options from distinct notification IDs present in the log table.
     *
     * @return array Select options for the logs index notification filter.
     */
    private function notificationOptions(): array
    {
        $rows = (new Query())
            ->select(['notificationId', 'notificationTitle'])
            ->from(EmailLogRecord::tableName())
            ->where(['not', ['notificationId' => null]])
            ->groupBy(['notificationId', 'notificationTitle'])
            ->orderBy(['notificationTitle' => SORT_ASC])
            ->all();

        $options = [
            ['label' => Craft::t('super-mailer', 'All notifications'), 'value' => ''],
        ];

        foreach ($rows as $row) {
            $id = (int)($row['notificationId'] ?? 0);
            if (!$id) {
                continue;
            }

            $title = trim((string)($row['notificationTitle'] ?? ''));
            $options[] = [
                'label' => $title !== '' ? $title : Craft::t('super-mailer', 'Notification #{id}', ['id' => $id]),
                'value' => (string)$id,
            ];
        }

        return $options;
    }
}
