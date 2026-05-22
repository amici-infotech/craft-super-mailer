<?php
namespace amici\SuperMailer\models;

use craft\base\Model;

/**
 * Settings model for configurable Super Mailer plugin options stored by Craft.
 */
class Settings extends Model
{
    public string $pluginName = 'Super Mailer';
    public int $emailLogRetentionDays = 120;

    /**
     * Defines validation rules for this model or record.
     *
     * @return array Return value produced by this method.
     */
    public function rules(): array
    {
        return [
            [['pluginName'], 'required'],
            [['pluginName'], 'string', 'max' => 255],
            [['emailLogRetentionDays'], 'integer', 'min' => 0],
        ];
    }
}
