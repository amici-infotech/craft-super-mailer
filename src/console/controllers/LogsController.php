<?php
namespace amici\SuperMailer\console\controllers;

use amici\SuperMailer\Plugin;
use Craft;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

class LogsController extends Controller
{
    /**
     * Purges email logs older than the configured retention period, or the provided number of days.
     *
     * Example: php craft super-mailer/logs/purge 120
     */
    public function actionPurge(?int $days = null): int
    {
        $retentionDays = $days ?? Plugin::getInstance()->getSettings()->emailLogRetentionDays;
        if ((int)$retentionDays <= 0) {
            $this->stdout("Email log retention is disabled. Pass a day value or use purge-all.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $deleted = Plugin::getInstance()->getLogs()->purgeByRetention((int)$retentionDays);
        $this->stdout("Purged {$deleted} email log(s) older than {$retentionDays} day(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Purges all email logs.
     *
     * Example: php craft super-mailer/logs/purge-all
     */
    public function actionPurgeAll(): int
    {
        $deleted = Plugin::getInstance()->getLogs()->purgeAll();
        $this->stdout("Purged {$deleted} email log(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
