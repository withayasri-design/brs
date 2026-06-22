<?php
declare(strict_types=1);

class Config
{
    private static array $data = [];

    public static function init(string $configPath): void
    {
        self::$data = require $configPath;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $value = self::$data;
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
