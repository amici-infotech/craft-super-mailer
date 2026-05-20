<?php
namespace amici\SuperMailer\controllers;

use amici\SuperMailer\records\EmailLogRecord;
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
}
