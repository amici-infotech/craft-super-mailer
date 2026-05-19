<?php
namespace amici\SuperMailer\behaviors;

use yii\base\Behavior;

class ElementContextBehavior extends Behavior
{
    public array $data = [];

    public function canGetProperty($name, $checkVars = true): bool
    {
        return true;
    }

    public function __get($name)
    {
        if (array_key_exists($name, $this->data)) {
            return $this->data[$name];
        }

        return null;
    }
}
