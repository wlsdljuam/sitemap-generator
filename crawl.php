<?php
/**
 * AJAX 엔드포인트
 * - crawl: URL 크롤링 → sitemap/rss 생성
 * - register: 도메인 등록 (자동 갱신용)
 * - delete: 도메인 삭제
 */

header('Content-Type: application/json; charset=utf-8');

// 크롤링은 시간이 걸리므로 타임아웃 확장
set_time_limit(300);

require_once __DIR__ . '/lib/Crawler.php';

$dataDir     = __DIR__ . '/data';
$outputDir   = $dataDir . '/output';
$domainsFile = $dataDir . '/domains.json';

// 데이터 디렉토리 확인
if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);
if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'crawl':
        handleCrawl();
        break;
    case 'register':
        handleRegister();
        break;
    case 'delete':
        handleDelete();
        break;
    default:
        echo json_encode(['success' => false, 'error' => '잘못된 요청']);
}

// ============================================================
// 크롤링 실행
// ============================================================
function handleCrawl()
{
    global $outputDir;

    $url = trim($_POST['url'] ?? '');
    $maxPages = intval($_POST['max_pages'] ?? 500);

    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        echo json_encode(['success' => false, 'error' => '유효한 URL을 입력해주세요.']);
        return;
    }

    // 도메인 추출
    $parsed = parse_url($url);
    $domain = $parsed['host'] ?? '';
    $domain = preg_replace('/^www\./i', '', $domain);

    if (empty($domain)) {
        echo json_encode(['success' => false, 'error' => '도메인을 추출할 수 없습니다.']);
        return;
    }

    // 출력 디렉토리 생성
    $domainDir = $outputDir . '/' . $domain;
    if (!is_dir($domainDir)) {
        mkdir($domainDir, 0755, true);
    }

    // 크롤링 실행
    $crawler = new SiteCrawler($url, $maxPages);
    $pages = $crawler->crawl();

    if (empty($pages)) {
        echo json_encode(['success' => false, 'error' => '페이지를 찾을 수 없습니다. URL을 확인해주세요.']);
        return;
    }

    // sitemap.xml 생성
    $crawler->generateSitemap($domainDir . '/sitemap.xml');

    // rss.xml 생성
    $crawler->generateRss($domainDir . '/rss.xml');

    // 등록된 도메인이면 메타 업데이트
    updateDomainMeta($domain, count($pages));

    echo json_encode([
        'success'    => true,
        'domain'     => $domain,
        'page_count' => count($pages),
    ]);
}

// ============================================================
// 도메인 등록
// ============================================================
function handleRegister()
{
    global $domainsFile;

    $url = trim($_POST['url'] ?? '');
    $interval = $_POST['interval'] ?? 'daily';
    $maxPages = intval($_POST['max_pages'] ?? 500);

    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        echo json_encode(['success' => false, 'error' => '유효한 URL을 입력해주세요.']);
        return;
    }

    $parsed = parse_url($url);
    $domain = $parsed['host'] ?? '';
    $domain = preg_replace('/^www\./i', '', $domain);

    if (empty($domain)) {
        echo json_encode(['success' => false, 'error' => '도메인을 추출할 수 없습니다.']);
        return;
    }

    // 기존 목록 로드
    $domains = [];
    if (file_exists($domainsFile)) {
        $domains = json_decode(file_get_contents($domainsFile), true) ?: [];
    }

    // 중복 확인
    foreach ($domains as $d) {
        if ($d['domain'] === $domain) {
            echo json_encode(['success' => false, 'error' => '이미 등록된 도메인입니다.']);
            return;
        }
    }

    // 신규 등록
    $domains[] = [
        'url'           => $url,
        'domain'        => $domain,
        'max_pages'     => $maxPages,
        'interval'      => $interval,
        'last_crawled'  => null,
        'page_count'    => 0,
        'registered_at' => date('Y-m-d\TH:i:s') . '+09:00',
    ];

    file_put_contents($domainsFile, json_encode($domains, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    echo json_encode(['success' => true, 'domain' => $domain]);
}

// ============================================================
// 도메인 삭제
// ============================================================
function handleDelete()
{
    global $domainsFile, $outputDir;

    $domain = trim($_POST['domain'] ?? '');
    if (empty($domain)) {
        echo json_encode(['success' => false, 'error' => '도메인이 지정되지 않았습니다.']);
        return;
    }

    // 목록에서 제거
    $domains = [];
    if (file_exists($domainsFile)) {
        $domains = json_decode(file_get_contents($domainsFile), true) ?: [];
    }

    $domains = array_values(array_filter($domains, function ($d) use ($domain) {
        return $d['domain'] !== $domain;
    }));

    file_put_contents($domainsFile, json_encode($domains, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // 출력 파일 삭제
    $domainDir = $outputDir . '/' . $domain;
    if (is_dir($domainDir)) {
        array_map('unlink', glob($domainDir . '/*'));
        rmdir($domainDir);
    }

    echo json_encode(['success' => true]);
}

// ============================================================
// 등록 도메인 메타 업데이트
// ============================================================
function updateDomainMeta($domain, $pageCount)
{
    global $domainsFile;

    if (!file_exists($domainsFile)) return;

    $domains = json_decode(file_get_contents($domainsFile), true) ?: [];
    $updated = false;

    foreach ($domains as &$d) {
        if ($d['domain'] === $domain) {
            $d['last_crawled'] = date('Y-m-d\TH:i:s') . '+09:00';
            $d['page_count'] = $pageCount;
            $updated = true;
            break;
        }
    }

    if ($updated) {
        file_put_contents($domainsFile, json_encode($domains, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
