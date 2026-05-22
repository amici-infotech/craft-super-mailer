<?php
/**
 * Super Mailer plugin for Craft CMS 5.x
 *
 * Event-driven email notifications for Craft CMS.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperMailer;

use amici\SuperMailer\base\PluginTrait;
use amici\SuperMailer\elements\MailerNotification;
use amici\SuperMailer\models\Settings;
use Craft;
use craft\base\Model;
use craft\base\Plugin as CraftPlugin;
use craft\console\Application as ConsoleApplication;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use yii\base\Event;

/**
 * @property Settings $settings
 * @method Settings getSettings()
 */
class Plugin extends CraftPlugin
{
    use PluginTrait;

    public static ?Plugin $plugin = null;
    public static string $pluginHandle = 'super-mailer';

    public string $schemaVersion = '5.0.4';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    /**
     * Initializes the class and registers its Craft components or assets.
     *
     * @return void Return value produced by this method.
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'amici\SuperMailer\console\controllers';
        }

        $this->_setPluginComponents();
        $this->_registerRoutes();
        $this->_registerElementTypes();
        $this->_registerPermissions();
        $this->getNotifications()->registerEnabledNotificationListeners();

        Craft::info(
            Craft::t('super-mailer', '{name} plugin loaded', ['name' => $this->name]),
            __METHOD__
        );
    }

    /**
     * Creates the settings model used by Craft for plugin configuration.
     *
     * @return ?Model Return value produced by this method.
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * Redirects Craft plugin settings access to the custom Super Mailer settings page.
     *
     * @return mixed Return value produced by this method.
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('super-mailer/settings'));
    }

    /**
     * Builds the Control Panel navigation item and subnavigation for Super Mailer.
     *
     * @return ?array Return value produced by this method.
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = $this->getSettings()->pluginName;
        $item['url'] = 'super-mailer';

        $item['subnav']['notifications'] = [
            'label' => Craft::t('super-mailer', 'Notifications'),
            'url' => 'super-mailer/notifications',
        ];

        $item['subnav']['logs'] = [
            'label' => Craft::t('super-mailer', 'Logs'),
            'url' => 'super-mailer/logs',
        ];

        $item['subnav']['settings'] = [
            'label' => Craft::t('super-mailer', 'Settings'),
            'url' => 'super-mailer/settings',
        ];

        return $item;
    }

    /**
     * Returns the SVG mask icon path used by the Craft CP navigation.
     *
     * @return ?string Return value produced by this method.
     */
    protected function cpNavIconPath(): ?string
    {
        return $this->getBasePath() . DIRECTORY_SEPARATOR . 'icon-mask.svg';
    }

    /**
     * Registers Super Mailer Control Panel URL rules.
     *
     * @return void Return value produced by this method.
     */
    private function _registerRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event): void {
                $event->rules = array_merge($event->rules, [
                    'super-mailer' => 'super-mailer/notification/index',
                    'super-mailer/notifications' => 'super-mailer/notification/index',
                    'super-mailer/notifications/new' => 'super-mailer/notification/edit',
                    'super-mailer/notifications/<notificationId:\d+>' => 'super-mailer/notification/edit',
                    'super-mailer/notifications/<notificationId:\d+>/preview' => 'super-mailer/notification/preview',
                    'super-mailer/notifications/<notificationId:\d+>/test-send' => 'super-mailer/notification/test-send',
                    'super-mailer/logs' => 'super-mailer/logs/index',
                    'super-mailer/logs/<logId:\d+>' => 'super-mailer/logs/view',
                    'super-mailer/logs/delete' => 'super-mailer/logs/delete',
                    'super-mailer/logs/delete-all' => 'super-mailer/logs/delete-all',
                    'super-mailer/logs/resend' => 'super-mailer/logs/resend',
                    'super-mailer/settings' => 'super-mailer/settings/index',
                ]);
            }
        );
    }

    /**
     * Registers the notification element type with Craft.
     *
     * @return void Return value produced by this method.
     */
    private function _registerElementTypes(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = MailerNotification::class;
            }
        );
    }

    /**
     * Registers Super Mailer user permissions.
     *
     * @return void Return value produced by this method.
     */
    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => Craft::t('super-mailer', 'Super Mailer'),
                    'permissions' => [
                        'super-mailer:view-notifications' => [
                            'label' => Craft::t('super-mailer', 'View notifications'),
                        ],
                        'super-mailer:manage-notifications' => [
                            'label' => Craft::t('super-mailer', 'Manage notifications'),
                        ],
                    ],
                ];
            }
        );
    }
}
