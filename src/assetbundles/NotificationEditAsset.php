<?php
namespace amici\SuperMailer\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class NotificationEditAsset extends AssetBundle
{
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
