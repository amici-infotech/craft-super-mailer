<?php
namespace amici\SuperMailer\controllers;

use amici\SuperMailer\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

class SettingsController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requirePermission('super-mailer:manage-notifications');

        return $this->renderTemplate('super-mailer/settings/index', [
            'settings' => Plugin::getInstance()->getSettings(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('super-mailer:manage-notifications');

        $settings = Plugin::getInstance()->getSettings();
        $settings->pluginName = Craft::$app->getRequest()->getBodyParam('pluginName', $settings->pluginName);
        $settings->emailLogRetentionDays = (int)Craft::$app->getRequest()->getBodyParam('emailLogRetentionDays', $settings->emailLogRetentionDays);

        if (!$settings->validate() || !Craft::$app->getPlugins()->savePluginSettings(Plugin::getInstance(), $settings->toArray())) {
            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $settings,
            ]);
            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('super-mailer', 'Settings saved.'));
        return $this->redirectToPostedUrl();
    }
}
