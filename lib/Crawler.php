<?php
/**
 * 사이트 크롤러 클래스
 * BFS 방식으로 같은 도메인 내 모든 페이지를 탐색하고
 * sitemap.xml / rss.xml 생성에 필요한 메타 정보를 수집한다.
 */
class SiteCrawler
{
    private $startUrl;
    private $maxPages;
    private $timeout;
    private $baseHost;
    private $baseScheme;
    private $visited = [];
    private $pages = [];

    // 크롤링에서 제외할 확장자
    private $skipExtensions = [
        'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'ico', 'bmp',
        'css', 'js', 'json', 'xml',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'zip', 'rar', 'gz', 'tar', '7z',
        'mp3', 'mp4', 'avi', 'mov', 'wmv', 'flv',
        'woff', 'woff2', 'ttf', 'eot',
    ];

    /**
     * @param string $startUrl  크롤링 시작 URL
     * @param int    $maxPages  최대 페이지 수 (기본 500)
     * @param int    $timeout   페이지당 타임아웃 초 (기본 10)
     */
    public function __construct($startUrl, $maxPages = 500, $timeout = 10)
    {
        $this->startUrl = rtrim($startUrl, '/');
        $this->maxPages = $maxPages;
        $this->timeout  = $timeout;

        $parsed = parse_url($this->startUrl);
        $this->baseHost   = $parsed['host'] ?? '';
        $this->baseScheme = $parsed['scheme'] ?? 'https';
    }

    /**
     * BFS 크롤링 실행
     * @return array 수집된 페이지 배열
     */
    public function crawl()
    {
        $queue = [$this->startUrl];
        $this->visited = [];
        $this->pages = [];

        while (!empty($queue) && count($this->pages) < $this->maxPages) {
            $url = array_shift($queue);
            $normalized = $this->normalizeUrl($url);

            if ($normalized === null || isset($this->visited[$normalized])) {
                continue;
            }
            if ($this->shouldSkip($normalized)) {
                continue;
            }
            if (!$this->isSameDomain($normalized)) {
                continue;
            }

            $this->visited[$normalized] = true;

            $result = $this->fetchPage($normalized);
            if ($result === null) {
                continue;
            }

            // 페이지 메타 정보 수집
            $meta = $this->extractMeta($result['html'], $normalized);
            $meta['url']     = $normalized;
            $meta['lastmod'] = $result['lastmod'];

            $this->pages[] = $meta;

            // 링크 추출 후 큐에 추가
            $links = $this->extractLinks($result['html'], $normalized);
            foreach ($links as $link) {
                $linkNorm = $this->normalizeUrl($link);
                if ($linkNorm !== null && !isset($this->visited[$linkNorm])) {
                    $queue[] = $linkNorm;
                }
            }
        }

        return $this->pages;
    }

    /**
     * 수집된 페이지 배열 반환
     */
    public function getPages()
    {
        return $this->pages;
    }

    /**
     * 수집 결과로 sitemap.xml 생성
     */
    public function generateSitemap($outputPath)
    {
        $fp = fopen($outputPath, 'w');
        if (!$fp) return false;

        fwrite($fp, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        fwrite($fp, '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n");

        foreach ($this->pages as $i => $page) {
            $loc     = htmlspecialchars($page['url'], ENT_XML1, 'UTF-8');
            $lastmod = $page['lastmod'];
            // 첫 페이지(메인)는 priority 높게
            $priority = ($i === 0) ? '1.0' : '0.5';
            $changefreq = ($i === 0) ? 'daily' : 'weekly';

            fwrite($fp, "  <url>\n");
            fwrite($fp, "    <loc>{$loc}</loc>\n");
            fwrite($fp, "    <lastmod>{$lastmod}</lastmod>\n");
            fwrite($fp, "    <changefreq>{$changefreq}</changefreq>\n");
            fwrite($fp, "    <priority>{$priority}</priority>\n");
            fwrite($fp, "  </url>\n");
        }

        fwrite($fp, "</urlset>\n");
        fclose($fp);
        return true;
    }

    /**
     * 수집 결과로 rss.xml 생성
     */
    public function generateRss($outputPath, $siteTitle = '', $siteDesc = '')
    {
        $fp = fopen($outputPath, 'w');
        if (!$fp) return false;

        // 사이트 제목이 없으면 첫 페이지 타이틀 사용
        if (empty($siteTitle) && !empty($this->pages)) {
            $siteTitle = $this->pages[0]['title'] ?? $this->baseHost;
        }
        if (empty($siteDesc)) {
            $siteDesc = (!empty($this->pages[0]['description']))
                ? $this->pages[0]['description']
                : $siteTitle;
        }

        $titleEsc = htmlspecialchars($siteTitle, ENT_XML1, 'UTF-8');
        $descEsc  = htmlspecialchars($siteDesc, ENT_XML1, 'UTF-8');
        $linkEsc  = htmlspecialchars($this->startUrl, ENT_XML1, 'UTF-8');
        $buildDate = date('D, d M Y H:i:s +0900');

        fwrite($fp, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        fwrite($fp, '<rss version="2.0">' . "\n");
        fwrite($fp, "  <channel>\n");
        fwrite($fp, "    <title>{$titleEsc}</title>\n");
        fwrite($fp, "    <link>{$linkEsc}</link>\n");
        fwrite($fp, "    <description>{$descEsc}</description>\n");
        fwrite($fp, "    <language>ko</language>\n");
        fwrite($fp, "    <lastBuildDate>{$buildDate}</lastBuildDate>\n");
        fwrite($fp, "    <generator>Sitemap/RSS Generator</generator>\n");

        // 최신 50개 페이지만 RSS 아이템으로
        $items = array_slice($this->pages, 0, 50);
        foreach ($items as $page) {
            $itemUrl   = htmlspecialchars($page['url'], ENT_XML1, 'UTF-8');
            $itemTitle = htmlspecialchars($page['title'] ?: $page['url'], ENT_XML1, 'UTF-8');
            $itemDesc  = htmlspecialchars(
                mb_substr($page['description'], 0, 200, 'UTF-8'),
                ENT_XML1,
                'UTF-8'
            );

            fwrite($fp, "    <item>\n");
            fwrite($fp, "      <title>{$itemTitle}</title>\n");
            fwrite($fp, "      <link>{$itemUrl}</link>\n");
            fwrite($fp, "      <description>{$itemDesc}</description>\n");
            fwrite($fp, "      <guid>{$itemUrl}</guid>\n");
            fwrite($fp, "    </item>\n");
        }

        fwrite($fp, "  </channel>\n");
        fwrite($fp, "</rss>\n");
        fclose($fp);
        return true;
    }

    // ========================================================
    // Private 메서드
    // ========================================================

    /**
     * cURL로 페이지 가져오기
     */
    private function fetchPage($url)
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'SitemapGenerator/1.0',
            CURLOPT_HEADER         => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return null;
        }

        $headers = substr($response, 0, $headerSize);
        $html    = substr($response, $headerSize);

        // Content-Type이 HTML인지 확인
        if (stripos($headers, 'text/html') === false) {
            return null;
        }

        // Last-Modified 헤더 추출
        $lastmod = date('Y-m-d\TH:i:s') . '+09:00';
        if (preg_match('/Last-Modified:\s*(.+)/i', $headers, $m)) {
            $ts = strtotime(trim($m[1]));
            if ($ts !== false) {
                $lastmod = date('Y-m-d\TH:i:s', $ts) . '+09:00';
            }
        }

        return [
            'html'    => $html,
            'lastmod' => $lastmod,
        ];
    }

    /**
     * HTML에서 <a href> 링크 추출
     */
    private function extractLinks($html, $currentUrl)
    {
        $links = [];

        // DOMDocument 에러 억제
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        $anchors = $dom->getElementsByTagName('a');
        foreach ($anchors as $anchor) {
            $href = $anchor->getAttribute('href');
            if (empty($href)) continue;

            // javascript:, mailto:, tel: 제외
            if (preg_match('/^(javascript|mailto|tel):/i', $href)) continue;

            // 상대 경로 → 절대 경로 변환
            $resolved = $this->resolveUrl($href, $currentUrl);
            if ($resolved !== null) {
                $links[] = $resolved;
            }
        }

        return $links;
    }

    /**
     * HTML에서 메타 정보 추출 (title, description)
     */
    private function extractMeta($html, $url)
    {
        $title = '';
        $description = '';

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        // <title> 태그
        $titleTags = $dom->getElementsByTagName('title');
        if ($titleTags->length > 0) {
            $title = trim($titleTags->item(0)->textContent);
        }

        // <meta name="description"> 또는 <meta property="og:description">
        $metas = $dom->getElementsByTagName('meta');
        foreach ($metas as $meta) {
            $name     = strtolower($meta->getAttribute('name'));
            $property = strtolower($meta->getAttribute('property'));
            $content  = $meta->getAttribute('content');

            if ($name === 'description' && !empty($content)) {
                $description = trim($content);
            }
            if ($property === 'og:description' && !empty($content) && empty($description)) {
                $description = trim($content);
            }
            // og:title 폴백
            if ($property === 'og:title' && !empty($content) && empty($title)) {
                $title = trim($content);
            }
        }

        return [
            'title'       => $title,
            'description' => $description,
        ];
    }

    /**
     * 같은 도메인인지 확인
     */
    private function isSameDomain($url)
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        // www 유무 통일
        $host = preg_replace('/^www\./i', '', $host);
        $base = preg_replace('/^www\./i', '', $this->baseHost);

        return ($host === $base);
    }

    /**
     * URL 정규화 (중복 제거용)
     */
    private function normalizeUrl($url)
    {
        if (empty($url)) return null;

        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['host'])) return null;

        $scheme = $parsed['scheme'] ?? 'https';
        $host   = strtolower($parsed['host']);
        $path   = $parsed['path'] ?? '/';
        $query  = isset($parsed['query']) ? '?' . $parsed['query'] : '';

        // 끝 슬래시 통일
        if ($path === '') $path = '/';

        // 앵커(#) 제거
        return "{$scheme}://{$host}{$path}{$query}";
    }

    /**
     * 상대 URL을 절대 URL로 변환
     */
    private function resolveUrl($href, $baseUrl)
    {
        // 이미 절대 URL
        if (preg_match('/^https?:\/\//i', $href)) {
            return $href;
        }

        // 프로토콜 상대
        if (strpos($href, '//') === 0) {
            return $this->baseScheme . ':' . $href;
        }

        $baseParsed = parse_url($baseUrl);
        $baseScheme = $baseParsed['scheme'] ?? 'https';
        $baseHost   = $baseParsed['host'] ?? '';
        $basePath   = $baseParsed['path'] ?? '/';

        // 루트 상대
        if (strpos($href, '/') === 0) {
            return "{$baseScheme}://{$baseHost}{$href}";
        }

        // 디렉토리 상대
        $dir = rtrim(dirname($basePath), '/');
        return "{$baseScheme}://{$baseHost}{$dir}/{$href}";
    }

    /**
     * 건너뛸 URL인지 확인 (이미지, CSS 등)
     */
    private function shouldSkip($url)
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, $this->skipExtensions);
    }
}
