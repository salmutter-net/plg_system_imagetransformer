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
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use League\Glide\Signatures\SignatureFactory;
use League\Glide\Signatures\SignatureException;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

require_once __DIR__ . '/../../vendor/autoload.php';

final class Imagetransformer extends CMSPlugin implements SubscriberInterface
{

    /**
     * Returns an array of events this subscriber will listen to.
     * Required for Joomla 5/6 compatibility.
     *
     * @return  array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterInitialise' => 'onAfterInitialise',
            'onContentPrepare'  => 'onContentPrepare',
        ];
    }

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

    /**
     * Rewrite <img> tags in article texts through the transformer.
     *
     * @param   Event  $event
     *
     * @return  void
     */
    public function onContentPrepare(Event $event): void
    {
        $app = Factory::getApplication();

        if (!$app->isClient('site') || !(bool) $this->params->get('content-images', 0)) {
            return;
        }

        $context = (string) $event->getArgument('context', '');

        if (!$this->isAllowedContext($context)) {
            return;
        }

        $article = $event->getArgument('subject', null);

        if (!\is_object($article) || $this->isExcluded($article)) {
            return;
        }

        foreach (['text', 'introtext', 'fulltext', 'description'] as $property) {
            if (!empty($article->{$property}) && \is_string($article->{$property})) {
                $article->{$property} = $this->transformImagesInHtml($article->{$property});
            }
        }

        $this->transformStructuredImages($article);
    }

    /**
     * Transform image_intro/image_fulltext in the article's images JSON
     * (like the old imgresizecache plugin did). Originals are kept as
     * _image_intro/_image_fulltext for use in custom layouts.
     */
    private function transformStructuredImages(object $article): void
    {
        if (empty($article->images) || !\is_string($article->images)) {
            return;
        }

        $introParams    = $this->parseGlideParams((string) $this->params->get('content-intro-params', ''));
        $fulltextParams = $this->parseGlideParams((string) $this->params->get('content-fulltext-params', ''));

        if ($introParams === [] && $fulltextParams === []) {
            return;
        }

        $images = json_decode($article->images);

        if (!\is_object($images)) {
            return;
        }

        $changed = false;

        if ($introParams !== [] && !empty($images->image_intro) && \is_string($images->image_intro)) {
            $images->_image_intro = $images->image_intro;
            $images->image_intro  = self::generateUrl($images->image_intro, $introParams);
            $changed = true;
        }

        if ($fulltextParams !== [] && !empty($images->image_fulltext) && \is_string($images->image_fulltext)) {
            $images->_image_fulltext = $images->image_fulltext;
            $images->image_fulltext  = self::generateUrl($images->image_fulltext, $fulltextParams);
            $changed = true;
        }

        if ($changed) {
            $article->images = json_encode($images);
        }
    }

    /**
     * Check the context against the configured allowlist.
     * Entries are matched literally, a trailing '*' allows prefix
     * wildcards like 'com_content.*'.
     */
    private function isAllowedContext(string $context): bool
    {
        $patterns = (string) $this->params->get(
            'content-contexts',
            "com_content.*\ncom_contact.*\ncom_tags.*\nmod_custom.content"
        );

        foreach (preg_split('/[\s;]+/', $patterns) as $pattern) {
            $pattern = trim($pattern);

            if ($pattern === '') {
                continue;
            }

            if (str_ends_with($pattern, '*')
                ? str_starts_with($context, substr($pattern, 0, -1))
                : $context === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check article/category exclusion lists.
     */
    private function isExcluded(object $article): bool
    {
        $articleIds = $this->parseIdList((string) $this->params->get('content-exclude-articles', ''));
        $categoryIds = $this->parseIdList((string) $this->params->get('content-exclude-categories', ''));

        if (isset($article->id) && \in_array((int) $article->id, $articleIds, true)) {
            return true;
        }

        if (isset($article->catid) && \in_array((int) $article->catid, $categoryIds, true)) {
            return true;
        }

        return false;
    }

    private function parseIdList(string $list): array
    {
        return array_values(array_filter(array_map('intval', array_map('trim', explode(',', $list)))));
    }

    /**
     * Find <img> tags in a HTML fragment and rewrite them to transformed URLs
     * with srcset/sizes/loading attributes.
     */
    private function transformImagesInHtml(string $html): string
    {
        if (!str_contains($html, '<img')) {
            return $html;
        }

        $widths = $this->parseIdList((string) $this->params->get('content-widths', '480,768,1200,1600'));

        if (empty($widths)) {
            return $html;
        }

        sort($widths);

        $sizes        = trim((string) $this->params->get('content-sizes', 'auto'));
        $lazyload     = (bool) $this->params->get('content-lazyload', 1);
        $setDimensions = (bool) $this->params->get('content-set-dimensions', 1);
        $definitions  = $this->parseSizeDefinitions((string) $this->params->get('content-size-definitions', ''));
        $defaultParams = array_filter([
            'fit' => (string) $this->params->get('content-fit', 'max'),
            'q'   => (int) $this->params->get('content-quality', 75),
            'fm'  => trim((string) $this->params->get('content-format', '')) ?: null,
        ]);

        return preg_replace_callback(
            '/<img\b[^>]*>/i',
            function (array $matches) use ($widths, $sizes, $lazyload, $setDimensions, $definitions, $defaultParams) {
                $img = $matches[0];

                if (!preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/i', $img, $srcMatch) || trim($srcMatch[2]) === '') {
                    return $img;
                }

                $src = $srcMatch[2];

                // Skip data URIs, external images and already transformed URLs
                if (str_starts_with($src, 'data:')
                    || str_contains($src, '://')
                    || str_starts_with($src, '//')
                    || str_contains($src, '/img/')) {
                    return $img;
                }

                // Skip images explicitly marked
                if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/i', $img, $classMatch)
                    && preg_match('/\bno-transform\b/', $classMatch[2])) {
                    return $img;
                }

                // Class-based size definition? -> single URL, no srcset
                $definition = $this->matchSizeDefinition($img, $definitions);

                if ($definition !== null) {
                    $newImg = $this->replaceImgAttribute($img, 'src', self::generateUrl($src, $definition));
                } else {
                    $srcset = [];
                    foreach ($widths as $width) {
                        $srcset[] = self::generateUrl($src, ['w' => $width] + $defaultParams) . ' ' . $width . 'w';
                    }

                    $newImg = $this->replaceImgAttribute($img, 'src', self::generateUrl($src, ['w' => $widths[0]] + $defaultParams));
                    $newImg = $this->replaceImgAttribute($newImg, 'srcset', implode(', ', $srcset));

                    if ($sizes !== '') {
                        $newImg = $this->replaceImgAttribute($newImg, 'sizes', $sizes);
                    }
                }

                // Fill in missing width/height attributes (CLS), keep existing ones
                if ($setDimensions) {
                    $newImg = $this->fillDimensions($newImg, $src);
                }

                if ($lazyload && !preg_match('/\bloading\s*=/i', $newImg)) {
                    $newImg = preg_replace('/<img\b/i', '<img loading="lazy"', $newImg, 1);
                }

                return $newImg;
            },
            $html
        );
    }

    /**
     * Parse "classname: w=700,h=400,fit=crop" lines into [classname => params].
     */
    private function parseSizeDefinitions(string $definitions): array
    {
        $result = [];

        foreach (preg_split('/[\r\n;]+/', $definitions) as $line) {
            $line = trim($line);

            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$class, $optString] = explode(':', $line, 2);
            $class = trim($class);
            $opts  = $this->parseGlideParams($optString);

            if ($class !== '' && $opts !== []) {
                $result[$class] = $opts;
            }
        }

        return $result;
    }

    /**
     * Parse a Glide param string like "w=700,h=400,fit=crop,q=80" into an array.
     */
    private function parseGlideParams(string $params): array
    {
        $opts = [];

        foreach (explode(',', $params) as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $pair, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key !== '' && $value !== '') {
                $opts[$key] = is_numeric($value) ? $value + 0 : $value;
            }
        }

        return $opts;
    }

    /**
     * Return the size-definition params for the first matching class, or null.
     */
    private function matchSizeDefinition(string $img, array $definitions): ?array
    {
        if ($definitions === []
            || !preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/i', $img, $classMatch)) {
            return null;
        }

        foreach (preg_split('/\s+/', trim($classMatch[2])) as $class) {
            if (isset($definitions[$class])) {
                return $definitions[$class];
            }
        }

        return null;
    }

    /**
     * Replace an existing attribute value or add the attribute to the tag.
     */
    private function replaceImgAttribute(string $img, string $attr, string $value): string
    {
        $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        if (preg_match('/\b' . preg_quote($attr, '/') . '\s*=/i', $img)) {
            return preg_replace(
                '/\b' . preg_quote($attr, '/') . '\s*=\s*(["\']).*?\1/i',
                $attr . '="' . $value . '"',
                $img,
                1
            );
        }

        return preg_replace('/<img\b/i', '<img ' . $attr . '="' . $value . '"', $img, 1);
    }

    /**
     * Keep existing width/height attributes untouched (they define the
     * aspect ratio for the browser). If one or both are missing, fill them
     * from the intrinsic image size so the layout does not shift.
     */
    private function fillDimensions(string $img, string $src): string
    {
        $hasWidth  = (bool) preg_match('/\bwidth\s*=\s*(["\'])(\d+)\1/i', $img, $wMatch);
        $hasHeight = (bool) preg_match('/\bheight\s*=\s*(["\'])(\d+)\1/i', $img, $hMatch);

        if ($hasWidth && $hasHeight) {
            return $img;
        }

        $size = $this->intrinsicImageSize($src);

        if ($size === null || $size[0] <= 0 || $size[1] <= 0) {
            return $img;
        }

        if (!$hasWidth && !$hasHeight) {
            $img = $this->replaceImgAttribute($img, 'width', (string) $size[0]);
            return $this->replaceImgAttribute($img, 'height', (string) $size[1]);
        }

        if (!$hasWidth) {
            $width = (int) round(((int) $hMatch[2]) * $size[0] / $size[1]);
            return $this->replaceImgAttribute($img, 'width', (string) $width);
        }

        $height = (int) round(((int) $wMatch[2]) * $size[1] / $size[0]);
        return $this->replaceImgAttribute($img, 'height', (string) $height);
    }

    /**
     * Resolve the intrinsic size of a local image (cached per request).
     *
     * @return  array{0:int,1:int}|null
     */
    private function intrinsicImageSize(string $src): ?array
    {
        static $cache = [];

        if (array_key_exists($src, $cache)) {
            return $cache[$src];
        }

        $cleanSrc = HTMLHelper::cleanImageURL($src);
        $path = $cleanSrc->url !== '' ? $cleanSrc->url : $src;
        $path = urldecode(parse_url($path, PHP_URL_PATH) ?: '');

        // Strip a configured media-folder prefix is unnecessary here:
        // the path must resolve relative to the site root.
        $localPath = JPATH_ROOT . '/' . ltrim($path, '/');

        $size = is_file($localPath) ? @getimagesize($localPath) : false;

        return $cache[$src] = ($size !== false) ? [$size[0], $size[1]] : null;
    }

    private function respondNotFound(string $message = 'Invalid image transformation request'): void
    {
        throw new RouteNotFoundException($message, 404);
    }
}
