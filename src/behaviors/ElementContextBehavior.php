<?php
namespace amici\SuperMailer\behaviors;

use yii\base\Behavior;

/**
 * Behavior attached to rehydrated event elements so normalized context values can be read like element properties.
 */
class ElementContextBehavior extends Behavior
{
    public array $data = [];

    /**
     * Allows normalized element context data to be read as dynamic properties.
     *
     * @param mixed $name name value used by this method.
     * @param mixed $checkVars checkVars value used by this method.
     * @return bool Return value produced by this method.
     */
    public function canGetProperty($name, $checkVars = true): bool
    {
        return true;
    }

    /**
     * Provides property-style access to normalized event context data.
     *
     * @param mixed $name name value used by this method.
     */
    public function __get($name)
    {
        if (array_key_exists($name, $this->data)) {
            return $this->data[$name];
        }

        return null;
    }
}
