<?php
namespace amici\SuperMailer\models;

use craft\base\Model;

class Settings extends Model
{
    public string $pluginName = 'Super Mailer';
    public int $emailLogRetentionDays = 120;

    public function rules(): array
    {
        return [
            [['pluginName'], 'required'],
            [['pluginName'], 'string', 'max' => 255],
            [['emailLogRetentionDays'], 'integer', 'min' => 0],
        ];
    }
}
