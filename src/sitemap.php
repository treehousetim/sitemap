<?php

// Usage: php sitemap.php <sitemap_url_or_host> [--output=slugs|words]

class SitemapParser {
    private $processedUrls = [];
    private $structuralWords = ['product', 'page', 'post', 'blog', 'category', 'tag', 'archive'];

    public function processSitemap(string $sitemapUrl): array {
        if (in_array($sitemapUrl, $this->processedUrls)) {
            return [];
        }

        $this->processedUrls[] = $sitemapUrl;
        $content = $this->fetchContent($sitemapUrl);
        $keywords = [];

        if (empty($content)) {
            echo "Failed to fetch or empty content for $sitemapUrl\n";
            return [];
        }

        if (strpos($content, '<sitemapindex') !== false) {
            // Parse nested sitemaps
            $keywords = $this->processSitemapIndex($content);
        } elseif (strpos($content, '<urlset') !== false) {
            // Parse individual URLs
            $keywords = $this->processUrlSet($content);
        } else {
            echo "Invalid sitemap format: $sitemapUrl\n";
        }

        return $keywords;
    }

    private function fetchContent(string $url): string {
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'SitemapParser/1.0'
            ]
        ]);

        $content = @file_get_contents($url, false, $context) ?: '';

        // Strip XML declaration and stylesheet references if present
        $content = preg_replace('/<\?xml[^>]*\?>/', '', $content);
        $content = preg_replace('/<\?xml-stylesheet[^>]*\?>/', '', $content);

        return $content;
    }

    private function processSitemapIndex(string $content): array {
        $keywords = [];

        try {
            $xml = new SimpleXMLElement($content);
        } catch (Exception $e) {
            echo "Failed to parse sitemap index: " . $e->getMessage() . "\n";
            return [];
        }

        foreach ($xml->sitemap as $sitemap) {
            $loc = (string) $sitemap->loc;
            $keywords = array_merge($keywords, $this->processSitemap($loc));
        }

        return $keywords;
    }

    private function processUrlSet(string $content): array {
        $keywords = [];

        try {
            $xml = new SimpleXMLElement($content);
        } catch (Exception $e) {
            echo "Failed to parse URL set: " . $e->getMessage() . "\n";
            return [];
        }

        foreach ($xml->url as $url) {
            $loc = (string) $url->loc;
            $keywords[] = $this->urlToKeyword($loc);
        }

        return $keywords;
    }

    private function urlToKeyword(string $url): string {
        $parsedUrl = parse_url($url);
        $path = trim($parsedUrl['path'] ?? '', '/');

        $segments = array_filter(explode('/', $path), function ($segment) {
            $cleaned = strtolower(preg_replace('/[^a-z0-9]/', '', $segment));
            return $cleaned !== '' && !in_array($cleaned, $this->structuralWords);
        });

        return implode('-', $segments);
    }

    public function processRss(string $rssUrl): array {
        if (in_array($rssUrl, $this->processedUrls)) {
            return [];
        }

        $this->processedUrls[] = $rssUrl;
        $content = $this->fetchContent($rssUrl);
        $keywords = [];

        if (empty($content)) {
            echo "Failed to fetch or empty content for $rssUrl\n";
            return [];
        }

        try {
            $xml = new SimpleXMLElement($content);
        } catch (Exception $e) {
            echo "Failed to parse RSS feed: " . $e->getMessage() . "\n";
            return [];
        }

        // Check if <item> exists and is iterable
        if (isset($xml->channel->item) && is_iterable($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                $link = (string) $item->link;
                $keywords[] = $this->urlToKeyword($link);
            }
        }

        return $keywords;
    }
}

class RobotsTxtChecker {
    public static function isAllowed(string $url): bool {
        $parsed = parse_url($url);
        $base = $parsed['scheme'] . '://' . $parsed['host'];
        $robotsUrl = $base . '/robots.txt';

        $content = @file_get_contents($robotsUrl);

        if ($content === false) {
            return true; // Assume allowed if no robots.txt
        }

        $path = $parsed['path'] ?? '/';
        $userAgent = 'SitemapParser'; // Default user-agent
        $rules = self::parseRobotsTxt($content);

        return self::isPathAllowed($path, $rules, $userAgent);
    }

    private static function parseRobotsTxt(string $content): array {
        $lines = explode("\n", $content);
        $rules = [];
        $currentAgent = '*';

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) {
                continue; // Skip comments and empty lines
            }

            if (stripos($line, 'User-agent:') === 0) {
                $currentAgent = trim(substr($line, 11)) ?: '*';
                $rules[$currentAgent] = $rules[$currentAgent] ?? ['allow' => [], 'disallow' => []];
            } elseif (stripos($line, 'Disallow:') === 0) {
                $rule = trim(substr($line, 9));
                $rules[$currentAgent]['disallow'][] = $rule;
            } elseif (stripos($line, 'Allow:') === 0) {
                $rule = trim(substr($line, 6));
                $rules[$currentAgent]['allow'][] = $rule;
            }
        }

        return $rules;
    }

    private static function isPathAllowed(string $path, array $rules, string $userAgent): bool {
        $applicableRules = $rules[$userAgent] ?? $rules['*'] ?? null;

        if (!$applicableRules) {
            return true; // No rules for this user-agent
        }

        foreach ($applicableRules['allow'] as $allowedPath) {
            if (self::pathMatches($path, $allowedPath)) {
                return true;
            }
        }

        foreach ($applicableRules['disallow'] as $disallowedPath) {
            if (self::pathMatches($path, $disallowedPath)) {
                return false;
            }
        }

        return true;
    }

    private static function pathMatches(string $path, string $rule): bool {
        // Empty disallow means allow everything
        if ($rule === '') {
            return false;
        }

        return strpos($path, $rule) === 0;
    }
}


class SitemapKeywordGenerator {
    private $parser;

    public function __construct() {
        $this->parser = new SitemapParser();
    }

    public function generateKeywords(string $urlOrHost, string $outputType = 'slugs'): void {
        // Ensure the URL has a scheme, defaulting to https:// if none is provided
        if (!parse_url($urlOrHost, PHP_URL_SCHEME)) {
            $urlOrHost = 'https://' . ltrim($urlOrHost, '/');
        }

        $sitemapUrl = strpos($urlOrHost, '/sitemap') === false ? $this->discoverSitemap($urlOrHost) : $urlOrHost;
        if (!$sitemapUrl) {
            echo "No sitemap found for $urlOrHost\n";
            return;
        }

        if (!RobotsTxtChecker::isAllowed($sitemapUrl)) {
            echo "Sitemap $sitemapUrl is disallowed by robots.txt\n";
            return;
        }

        $keywords = array_filter($this->parser->processSitemap($sitemapUrl)); // Filter out empty keywords

        $rssUrl = $this->discoverRss($urlOrHost);
        if ($rssUrl) {
            $rssKeywords = array_filter($this->parser->processRss($rssUrl)); // Filter out empty RSS keywords
            $keywords = array_merge($keywords, $rssKeywords);
        }

        if ($outputType === 'words') {
            $this->outputWords($keywords);
        } else {
            $this->outputSlugs($keywords);
        }
    }

    private function outputSlugs(array $keywords): void {
        foreach (array_unique($keywords) as $keyword) {
            if (!empty($keyword)) { // Prevent empty output
                echo "$keyword\n";
            }
        }
    }

    private function outputWords(array $keywords): void {
        $stopWords = ['the', 'and', 'of', 'if', 'to', 'a', 'in', 'on', 'for', 'with', 'by', 'is', 'it', 'or'];
        $wordCounts = [];

        foreach ($keywords as $keyword) {
            // Split words on non-alphabetic characters
            $words = array_filter(preg_split('/[^a-zA-Z]+/', $keyword), function ($word) use ($stopWords) {
                $word = strtolower(trim($word));
                return !empty($word) && !in_array($word, $stopWords);
            });

            foreach ($words as $word) {
                $wordCounts[$word] = ($wordCounts[$word] ?? 0) + 1;
            }
        }

        arsort($wordCounts);

        foreach ($wordCounts as $word => $count) {
            echo "$count,$word\n";
        }
    }

    private function discoverSitemap(string $urlOrHost): ?string {
        $parsed = parse_url($urlOrHost);

        $base = isset($parsed['scheme']) ? $urlOrHost : 'https://' . rtrim($urlOrHost, '/');
        $potentialSitemaps = [
            $base . '/sitemap.xml',
            $base . '/sitemap_index.xml',
            $base . '/sitemaps/sitemap.xml',
        ];

        foreach ($potentialSitemaps as $sitemapUrl) {
            if (@file_get_contents($sitemapUrl)) {
                return $sitemapUrl;
            }
        }

        return null;
    }

    private function discoverRss(string $urlOrHost): ?string {
        $parsed = parse_url($urlOrHost);

        $base = isset($parsed['scheme']) ? $urlOrHost : 'https://' . rtrim($urlOrHost, '/');
        $potentialRssFeeds = [
            $base . '/feed',
            $base . '/rss',
            $base . '/rss.xml',
        ];

        foreach ($potentialRssFeeds as $rssUrl) {
            if (@file_get_contents($rssUrl)) {
                return $rssUrl;
            }
        }

        return null;
    }

}

// Entry point
$urlOrHost = $argv[1] ?? '';
$outputType = $argv[2] ?? '--output=slugs';
$outputType = str_replace('--output=', '', $outputType);

if (!$urlOrHost) {
    die("Usage: php sitemap.php <sitemap_url_or_host> [--output=slugs|words]\n");
}

$generator = new SitemapKeywordGenerator();
$generator->generateKeywords($urlOrHost, $outputType);
