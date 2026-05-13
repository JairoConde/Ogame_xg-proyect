<?php

namespace App\Libraries\BattleEngine\Utils;

class LangManager
{
    private $impl;
    private static $instance;

    public function setImplementation(Lang $implementation): void
    {
        $this->impl = $implementation;
    }

    public static function getInstance(): LangManager
    {
        if (empty(self::$instance)) {
            self::$instance = new LangManager();
        }

        return self::$instance;
    }

    public function __call(string $name, array $arguments): mixed
    {
        if (empty($this->impl)) {
            if (empty($arguments)) {
                return $name;
            }

            return $arguments[0];
        }

        return call_user_func_array([$this->impl, $name], $arguments);
    }

    public function implementationExist(): bool
    {
        return !empty($this->impl);
    }
}
