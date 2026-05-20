<?php
namespace amici\SuperMailer\controllers;

use amici\SuperMailer\records\EmailLogRecord;
use amici\SuperMailer\Plugin;
use Craft;
use craft\db\Query;
use craft\helpers\Json;
use craft\web\Controller;
use yii\web\Response;

class LogsController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

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
