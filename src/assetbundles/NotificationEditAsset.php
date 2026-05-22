<?php
namespace amici\SuperMailer\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Asset bundle that registers Super Mailer notification editor styles and JavaScript in the Craft CP.
 */
class NotificationEditAsset extends AssetBundle
{
    /**
     * Initializes the class and registers its Craft components or assets.
     *
     * @return void Return value produced by this method.
     */
    public function init(): void
    {
        $this->sourcePath = '@amici/SuperMailer/resources/dist';

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = [
            'css/notification-edit.css',
        ];

        $this->js = [
            'js/notification-edit.js',
        ];

        parent::init();
    }
}
