<?php
/**
 * 크론탭 자동 갱신 스크립트
 * 등록된 도메인을 순회하며 갱신 주기가 지난 도메인을 재크롤링한다.
 *
 * 크론 등록 예시:
 *   매일 새벽 3시: 0 3 * * * php /path/to/cron.php
 */

set_time_limit(0); // 크론에서는 시간 제한 없음

require_once __DIR__ . '/lib/Crawler.php';

$dataDir     = __DIR__ . '/data';
$outputDir   = $dataDir . '/output';
$domainsFile = $dataDir . '/domains.json';

if (!file_exists($domainsFile)) {
    echo "[알림] 등록된 도메인이 없습니다.\n";
    exit;
}

$domains = json_decode(file_get_contents($domainsFile), true);
if (empty($domains)) {
    echo "[알림] 등록된 도메인이 없습니다.\n";
    exit;
}

$now = time();
$updated = false;

foreach ($domains as &$domain) {
    // 갱신 주기 확인
    if (!empty($domain['last_crawled'])) {
        $lastCrawled = strtotime($domain['last_crawled']);
        $intervalSeconds = ($domain['interval'] === 'daily') ? 86400 : 604800;

        if (($now - $lastCrawled) < $intervalSeconds) {
            echo "[건너뜀] {$domain['domain']} — 갱신 주기 미도달\n";
            continue;
        }
    }

    echo "[시작] {$domain['domain']} 크롤링 중...\n";

    // 출력 디렉토리 생성
    $domainDir = $outputDir . '/' . $domain['domain'];
    if (!is_dir($domainDir)) {
        mkdir($domainDir, 0755, true);
    }

    // 크롤링 실행
    $maxPages = $domain['max_pages'] ?? 500;
    $crawler = new SiteCrawler($domain['url'], $maxPages);
    $pages = $crawler->crawl();

    if (empty($pages)) {
        echo "[실패] {$domain['domain']} — 페이지를 찾을 수 없음\n";
        continue;
    }

    // XML 생성
    $crawler->generateSitemap($domainDir . '/sitemap.xml');
    $crawler->generateRss($domainDir . '/rss.xml');

    // 메타 업데이트
    $domain['last_crawled'] = date('Y-m-d\TH:i:s') . '+09:00';
    $domain['page_count'] = count($pages);
    $updated = true;

    echo "[완료] {$domain['domain']} — {$domain['page_count']}개 페이지\n";
}

// 변경사항 저장
if ($updated) {
    file_put_contents($domainsFile, json_encode($domains, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

echo "[끝] 전체 처리 완료\n";
