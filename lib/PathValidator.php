<?php
declare(strict_types=1);

class PathValidator
{
    /**
     * Returns true only if $path is within $allowedBase after resolving symlinks/traversal.
     * On Windows, comparison is case-insensitive.
     */
    public static function isWithinAllowedBase(string $path, string $allowedBase): bool
    {
        // Normalize separators
        $normalize = static fn(string $p): string => rtrim(
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $p),
            DIRECTORY_SEPARATOR
        );

        $path        = $normalize($path);
        $allowedBase = $normalize($allowedBase);

        // Resolve real paths if they exist; fall back to lexical check
        $realPath = realpath($path);
        $realBase = realpath($allowedBase);

        if ($realPath !== false && $realBase !== false) {
            $realPath = $normalize($realPath);
            $realBase = $normalize($realBase);
        } else {
            // Lexical check: resolve '..' manually
            $realPath = self::lexicalResolve($path);
            $realBase = self::lexicalResolve($allowedBase);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $realPath = strtolower($realPath);
            $realBase = strtolower($realBase);
        }

        return str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR)
            || $realPath === $realBase;
    }

    private static function lexicalResolve(string $path): string
    {
        $parts  = explode(DIRECTORY_SEPARATOR, $path);
        $result = [];
        foreach ($parts as $part) {
            if ($part === '..') {
                array_pop($result);
            } elseif ($part !== '.') {
                $result[] = $part;
            }
        }
        return implode(DIRECTORY_SEPARATOR, $result);
    }
}
