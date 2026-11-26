<?php
/**
 * Copyright © David Stone. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace DevStone\ImageQuality\Image\Adapter;

/**
 * Stores remote watermark sources and their derived assets outside public paths.
 */
final class WatermarkCache
{
    private const ERROR_WATERMARK_IMAGE_ABSENT = 'Watermark Image absent.';
    private const MAX_SOURCE_BYTES = 20971520;

    /** @var string|null */
    private static $cacheDirectory;

    public static function isRemote($source)
    {
        if (!is_string($source) || $source === '') {
            return false;
        }

        $scheme = strtolower((string) parse_url($source, PHP_URL_SCHEME));
        return $scheme === 'https' || $scheme === 's3';
    }

    public static function resolveSource($source)
    {
        if (!self::isRemote($source)) {
            return $source;
        }

        $cacheFile = self::cacheDirectory() . DIRECTORY_SEPARATOR . hash('sha256', $source) . '.source';
        if (self::isValidImage($cacheFile)) {
            return $cacheFile;
        }

        $lockPath = $cacheFile . '.lock';
        $lock = self::openExclusiveFile($lockPath, 'c+b');

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock the watermark cache.');
            }
            if (self::isValidImage($cacheFile)) {
                return $cacheFile;
            }
            if (is_link($cacheFile)) {
                throw new \RuntimeException('Unsafe watermark cache entry.');
            }
            if (is_file($cacheFile)) {
                @unlink($cacheFile);
            }

            self::download($source, $cacheFile);
            return $cacheFile;
        } catch (\Throwable $exception) {
            throw new \LogicException(self::ERROR_WATERMARK_IMAGE_ABSENT, 0, $exception);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function derivedPath($source, $suffix)
    {
        return self::cacheDirectory() . DIRECTORY_SEPARATOR
            . hash('sha256', $source . "\0" . $suffix) . '.png';
    }

    public static function writeImageAtomically($destination, callable $writer)
    {
        self::assertCachePath($destination);
        if (is_link($destination)) {
            throw new \RuntimeException('Unsafe watermark cache entry.');
        }

        $temporary = self::temporaryPath();
        try {
            $result = $writer($temporary);
            if ($result === false || !self::isValidImage($temporary)) {
                throw new \RuntimeException('Unable to create a valid cached watermark image.');
            }
            @chmod($temporary, 0600);
            if (!@rename($temporary, $destination)) {
                throw new \RuntimeException('Unable to publish the cached watermark image.');
            }
        } finally {
            if (is_file($temporary) && !is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private static function download($source, $destination)
    {
        $contextOptions = [];
        if (strtolower((string) parse_url($source, PHP_URL_SCHEME)) === 'https') {
            $contextOptions = [
                'http' => ['timeout' => 15, 'follow_location' => 1, 'max_redirects' => 3],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ];
        }

        $input = @fopen($source, 'rb', false, stream_context_create($contextOptions));
        if ($input === false) {
            throw new \RuntimeException('Unable to read the remote watermark.');
        }

        $temporary = self::temporaryPath();
        $output = null;
        try {
            $output = self::openExclusiveFile($temporary, 'x+b');
            $bytes = 0;
            while (!feof($input)) {
                $chunk = fread($input, 8192);
                if ($chunk === false) {
                    throw new \RuntimeException('Unable to read the remote watermark.');
                }
                $bytes += strlen($chunk);
                if ($bytes > self::MAX_SOURCE_BYTES) {
                    throw new \RuntimeException('Remote watermark exceeds the cache limit.');
                }
                if ($chunk !== '' && fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('Unable to write the watermark cache.');
                }
            }
            fflush($output);
            fclose($output);
            $output = null;

            if ($bytes === 0 || !self::isValidImage($temporary)) {
                throw new \RuntimeException('Remote watermark is not a valid image.');
            }
            @chmod($temporary, 0600);
            if (!@rename($temporary, $destination)) {
                throw new \RuntimeException('Unable to publish the remote watermark cache.');
            }
        } finally {
            fclose($input);
            if (is_resource($output)) {
                fclose($output);
            }
            if (is_file($temporary) && !is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private static function cacheDirectory()
    {
        if (self::$cacheDirectory !== null) {
            return self::$cacheDirectory;
        }

        if (defined('BP')) {
            $applicationVar = BP . DIRECTORY_SEPARATOR . 'var';
            $applicationBase = $applicationVar . DIRECTORY_SEPARATOR . 'cache';
            if (!is_link(BP) && !is_link($applicationVar) && !is_link($applicationBase)) {
                $applicationCache = self::prepareDirectory($applicationBase);
                if ($applicationCache !== null) {
                    self::$cacheDirectory = $applicationCache;
                    return self::$cacheDirectory;
                }
            }
        }

        $runtimeBase = realpath(sys_get_temp_dir());
        if ($runtimeBase === false || is_link($runtimeBase) || !is_writable($runtimeBase)) {
            throw new \RuntimeException('No safe writable watermark cache is available.');
        }
        $identity = function_exists('posix_geteuid') ? (string) posix_geteuid() : hash('sha256', __FILE__);
        $runtimeUserBase = $runtimeBase . DIRECTORY_SEPARATOR . 'devstone-imagequality-' . $identity;
        if (!is_dir($runtimeUserBase)
            && !@mkdir($runtimeUserBase, 0700, false)
            && !is_dir($runtimeUserBase)
        ) {
            throw new \RuntimeException('No safe writable watermark cache is available.');
        }
        if (is_link($runtimeUserBase)
            || realpath(dirname($runtimeUserBase)) !== $runtimeBase
            || (function_exists('posix_geteuid') && fileowner($runtimeUserBase) !== posix_geteuid())
        ) {
            throw new \RuntimeException('No safe writable watermark cache is available.');
        }
        @chmod($runtimeUserBase, 0700);
        $runtimeCache = self::prepareDirectory($runtimeUserBase);
        if ($runtimeCache === null) {
            throw new \RuntimeException('No safe writable watermark cache is available.');
        }

        self::$cacheDirectory = $runtimeCache;
        return self::$cacheDirectory;
    }

    private static function prepareDirectory($base)
    {
        if (!is_dir($base) || is_link($base) || !is_writable($base)) {
            return null;
        }

        $base = realpath($base);
        if ($base === false) {
            return null;
        }
        $directory = $base . DIRECTORY_SEPARATOR . 'devstone-imagequality-watermarks';
        if (!is_dir($directory) && !@mkdir($directory, 0700, false) && !is_dir($directory)) {
            return null;
        }
        if (is_link($directory)
            || !is_writable($directory)
            || (function_exists('posix_geteuid') && fileowner($directory) !== posix_geteuid())
        ) {
            return null;
        }

        $resolved = realpath($directory);
        if ($resolved === false || dirname($resolved) !== $base) {
            return null;
        }
        @chmod($resolved, 0700);
        return $resolved;
    }

    private static function temporaryPath()
    {
        return self::cacheDirectory() . DIRECTORY_SEPARATOR . '.tmp-' . bin2hex(random_bytes(16));
    }

    private static function openExclusiveFile($path, $mode)
    {
        self::assertCachePath($path);
        if (is_link($path)) {
            throw new \RuntimeException('Unsafe watermark cache entry.');
        }
        $handle = @fopen($path, $mode);
        if ($handle === false) {
            throw new \RuntimeException('Unable to open the watermark cache.');
        }
        return $handle;
    }

    private static function assertCachePath($path)
    {
        $directory = self::cacheDirectory();
        if (dirname($path) !== $directory || is_link($directory) || realpath($directory) !== $directory) {
            throw new \RuntimeException('Unsafe watermark cache path.');
        }
    }

    private static function isValidImage($path)
    {
        return is_file($path) && !is_link($path) && @getimagesize($path) !== false;
    }
}
