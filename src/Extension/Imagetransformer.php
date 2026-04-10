<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.imagetransformer
 *
 * @copyright   (C) 2025 DI Philipp Salmutter <https://www.salmutter.net>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\System\Imagetransformer\Extension;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Exception\RouteNotFoundException;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;
use League\Glide\Responses\PsrResponseFactory;
use League\Glide\ServerFactory;
use League\Glide\Urls\UrlBuilderFactory;
use Joomla\CMS\Plugin\PluginHelper;
use League\Glide\Signatures\SignatureFactory;
use League\Glide\Signatures\SignatureException;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

require_once __DIR__ . '/../../vendor/autoload.php';

final class Imagetransformer extends CMSPlugin
{

    public function onAfterInitialise()
    {
        $app = Factory::getApplication();

        // Only run in the site application
        if (!$app->isClient('site'))
        {
            return;
        }

        $uri = Uri::getInstance();
        $path = $uri->getPath();
        $query = $uri->getQuery();

        // Check if the path starts with /img/
        if (preg_match('#^/img/(.+)#', $path, $matches))
        {
            $path = '/img/' . $matches[1];
            $realPath = rawurldecode($matches[1]);

            if ($realPath === ''
                || str_contains($realPath, "\0")
                || str_contains($realPath, '..')
                || str_contains($realPath, '\\')
                || str_starts_with($realPath, '/')) {
                $this->respondNotFound();
            }

            $params = [];
            foreach (explode('&', (string) $query) as $part) {
                if ($part === '') {
                    continue;
                }

                // Strict mode: any param without "=" makes the request invalid.
                if (!str_contains($part, '=')) {
                    $this->respondNotFound();
                }

                [$rawKey, $rawValue] = explode('=', $part, 2);
                $key = rawurldecode($rawKey);
                $value = rawurldecode($rawValue);

                if ($key === '') {
                    $this->respondNotFound();
                }

                $params[$key] = $value;
            }
            $this->imageResponse($path, $params, $realPath);
        }
    }
    public static function generateUrl($path, $params): string
    {

        // Clean imagepath
        $path = HTMLHelper::cleanImageURL( $path );
        if ( $path->url !== '' ) {
            $path = $path->url;
        }
        $path = urldecode($path);

        // Get plugin parameters
        $plugin = PluginHelper::getPlugin('system', 'imagetransformer');
        $pluginParams = new Registry($plugin->params);

        $imageFolder = $pluginParams->get('image-folder', 'images');

        // Remove "images/" from path, so it doesn't show up in URL
        $prefix = $imageFolder . '/';
        if ( str_starts_with( $path, $prefix ) ) {
            $path = substr($path, strlen($prefix));
        }

        // Set complicated sign key
        $signkey = (string) $pluginParams->get('signkey');
        if ($signkey === '' || strlen($signkey) < 32) {
            Log::add('PlgSystemImageTransformer: Signkey is empty or too short (min 32 bytes)', Log::WARNING, 'error' );

            $query = '';
            if (is_array($params) && !empty($params)) {
                $query = '?' . http_build_query($params);
            }

            return rtrim(Uri::root(), '/') . '/img/' . ltrim((string) $path, '/') . $query;
        }

        // Generate a URL
        return UrlBuilderFactory::create( Uri::base() . 'img/', $signkey )->getUrl($path, $params);
    }

    private function imageResponse($path, $params, $realPath): Stream
    {
        // Get plugin parameters
        $plugin = PluginHelper::getPlugin('system', 'imagetransformer');
        $pluginParams = new Registry($plugin->params);

        $imageFolder = $pluginParams->get('image-folder', 'images');
        $imageCacheFolder = JPATH_ROOT . DIRECTORY_SEPARATOR . $pluginParams->get('image-cache-folder', 'images-cache');
        $fetchMissingFromLive = (bool) $pluginParams->get('fetch-missing-from-live', 0);
        $liveBaseUrl = (string) $pluginParams->get('live-base-url', '');

        // Create cache directory, if not existent
        $concurrentDirectory = $imageCacheFolder;
        if (!is_dir($concurrentDirectory) && !mkdir($concurrentDirectory, 0775, true) && !is_dir($concurrentDirectory)) {
            $this->respondNotFound();
        }

        // Verify HTTP signature
        try {
            // Set complicated sign key
            $signkey = (string)$pluginParams->get('signkey');
            if ($signkey === '' || strlen($signkey) < 32) {
                $this->respondNotFound('Image Transformer misconfigured: signkey is empty or too short');
            }

            // Validate HTTP signature
            $path = urldecode($path);
            SignatureFactory::create($signkey)->validateRequest($path, $params);

        } catch (SignatureException $e) {
            $this->respondNotFound();
        }

        // Setup Glide server
        $imageTransformationServer = ServerFactory::create([
            'source' => JPATH_ROOT . DIRECTORY_SEPARATOR . $imageFolder,
            'cache' => $imageCacheFolder,
            'base_url' => Uri::base(false) . $imageFolder . '/',
            'response' => new PsrResponseFactory(new Response(), function ($stream) {
                return new Stream($stream);
            }),
        ]);

        // If the transformed version is already cached, we can serve it without needing the source file.
        if ($imageTransformationServer->cacheFileExists($realPath, $params)) {
            $imageTransformationServer->outputImage($realPath, $params);
            exit;
        }

        $sourceRoot = JPATH_ROOT . DIRECTORY_SEPARATOR . $imageFolder;
        $localSourcePath = $sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $realPath);

        if (!is_file($localSourcePath)) {
            if (!$fetchMissingFromLive) {
                $this->respondNotFound();
            }

            $tmpSourceRoot = $this->createTempSourceRoot();
            $tmpSourcePath = $tmpSourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $realPath);

            try {
                $this->fetchMissingImageFromLive($liveBaseUrl, $imageFolder, $realPath, $tmpSourcePath);

                $tmpServer = ServerFactory::create([
                    'source' => $tmpSourceRoot,
                    'cache' => $imageCacheFolder,
                    'base_url' => Uri::base(false) . $imageFolder . '/',
                    'response' => new PsrResponseFactory(new Response(), function ($stream) {
                        return new Stream($stream);
                    }),
                ]);

                $tmpServer->outputImage($realPath, $params);
                exit;
            } finally {
                $this->deleteTempSourceRoot($tmpSourceRoot);
            }
        }
        $prefix = $imageFolder . DIRECTORY_SEPARATOR;
        if ( str_starts_with( $path, $prefix ) ) {
            $path = substr($path, strlen($prefix));
        }

        $imageTransformationServer->outputImage($realPath, $params);
        exit;
    }

    private function fetchMissingImageFromLive(string $liveBaseUrl, string $imageFolder, string $realPath, string $localSourcePath): void
    {
        $liveBaseUrl = trim($liveBaseUrl);
        if ($liveBaseUrl === '') {
            $this->respondNotFound('Image Transformer misconfigured: LIVE Base URL is empty');
        }

        $parsed = parse_url($liveBaseUrl);
        $scheme = is_array($parsed) ? ($parsed['scheme'] ?? '') : '';
        $host = is_array($parsed) ? ($parsed['host'] ?? '') : '';

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            $this->respondNotFound('Image Transformer misconfigured: LIVE Base URL is invalid');
        }

        $remotePath = trim($imageFolder, "/ \t\n\r\0\x0B") . '/' . ltrim($realPath, '/');
        $remotePath = implode('/', array_map('rawurlencode', array_filter(explode('/', $remotePath), static fn ($p) => $p !== '')));
        $remoteUrl = rtrim($liveBaseUrl, '/') . '/' . $remotePath;

        $expectedHost = strtolower($host);
        if (str_starts_with($expectedHost, 'www.')) {
            $expectedHost = substr($expectedHost, 4);
        }

        $http = HttpFactory::getHttp([
            'timeout' => 8,
            'userAgent' => 'Joomla ImageTransformer',
            'follow_location' => false,
            'max_redirects' => 0,
        ]);

        $maxRedirects = 2;
        $response = null;
        for ($i = 0; $i <= $maxRedirects; $i++) {
            try {
                $response = $http->get($remoteUrl);
            } catch (\Throwable $e) {
                $this->respondNotFound('LIVE fetch failed: ' . $e->getMessage());
            }

            $status = (int) ($response->code ?? 0);
            if ($status === 200) {
                break;
            }

            if (!in_array($status, [301, 302, 303, 307, 308], true)) {
                $this->respondNotFound('LIVE fetch failed: HTTP ' . $status . ' for ' . $remoteUrl);
            }

            $headers = is_array($response->headers ?? null) ? $response->headers : [];
            $locationHeader = $headers['Location'] ?? $headers['location'] ?? null;
            $location = '';
            if (is_array($locationHeader) && !empty($locationHeader)) {
                $location = (string) $locationHeader[0];
            } elseif (is_string($locationHeader)) {
                $location = $locationHeader;
            }

            if ($location === '') {
                $this->respondNotFound('LIVE fetch failed: redirect without Location header');
            }

            $redirectUrl = $location;
            if (str_starts_with($redirectUrl, '/')) {
                $redirectUrl = $scheme . '://' . $host . $redirectUrl;
            }

            $redirectParts = parse_url($redirectUrl);
            $redirectHost = is_array($redirectParts) ? (string) ($redirectParts['host'] ?? '') : '';
            if ($redirectHost === '') {
                $this->respondNotFound('LIVE fetch failed: redirect URL invalid');
            }

            $redirectHost = strtolower($redirectHost);
            if (str_starts_with($redirectHost, 'www.')) {
                $redirectHost = substr($redirectHost, 4);
            }

            if ($redirectHost !== $expectedHost) {
                $this->respondNotFound('LIVE fetch failed: redirect to unexpected host');
            }

            $remoteUrl = $redirectUrl;
        }

        if ($response === null || (int) ($response->code ?? 0) !== 200) {
            $status = $response !== null ? (int) ($response->code ?? 0) : 0;
            $this->respondNotFound('LIVE fetch failed: HTTP ' . $status . ' for ' . $remoteUrl);
        }

        $maxBytes = 25 * 1024 * 1024;
        $contentLength = 0;
        $headers = is_array($response->headers ?? null) ? $response->headers : [];
        $contentLengthHeader = $headers['Content-Length'] ?? $headers['content-length'] ?? null;
        if (!empty($contentLengthHeader)) {
            $headerValue = $contentLengthHeader;
            if (is_array($headerValue) && !empty($headerValue)) {
                $contentLength = (int) $headerValue[0];
            } elseif (is_string($headerValue) || is_int($headerValue)) {
                $contentLength = (int) $headerValue;
            }
        }
        if ($contentLength > $maxBytes) {
            $this->respondNotFound('LIVE fetch failed: file too large');
        }

        $body = (string) ($response->body ?? '');
        if ($body === '' || strlen($body) > $maxBytes) {
            $this->respondNotFound('LIVE fetch failed: empty/too large body');
        }

        $dir = dirname($localSourcePath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->respondNotFound('LIVE fetch failed: cannot create temp directory');
        }

        if (file_put_contents($localSourcePath, $body, LOCK_EX) === false) {
            $this->respondNotFound('LIVE fetch failed: cannot write temp file');
        }

        @chmod($localSourcePath, 0644);
    }

    private function createTempSourceRoot(): string
    {
        $tmpRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'joomla-imagetransformer-' . bin2hex(random_bytes(8));
        if (!mkdir($tmpRoot, 0775, true) && !is_dir($tmpRoot)) {
            $this->respondNotFound();
        }

        return $tmpRoot;
    }

    private function deleteTempSourceRoot(string $tmpRoot): void
    {
        $tmpRoot = rtrim($tmpRoot, DIRECTORY_SEPARATOR);
        if ($tmpRoot === '' || !str_contains($tmpRoot, 'joomla-imagetransformer-')) {
            return;
        }

        if (!is_dir($tmpRoot)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tmpRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($tmpRoot);
    }

    private function respondNotFound(string $message = 'Invalid image transformation request'): void
    {
        throw new RouteNotFoundException($message, 404);
    }
}
