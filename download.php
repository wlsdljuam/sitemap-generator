<?php
/**
 * sitemap.xml + rss.xml을 zip으로 묶어 다운로드
 * 다운로드 시 마지막 다운로드 시간을 domains.json에 기록
 */

$domain = trim($_GET['domain'] ?? '');

if (empty($domain)) {
    http_response_code(400);
    die('잘못된 요청입니다.');
}

// 경로 탐색 공격 방지
$domain = basename($domain);
$domainDir = __DIR__ . '/data/output/' . $domain;
$sitemapPath = $domainDir . '/sitemap.xml';
$rssPath     = $domainDir . '/rss.xml';

if (!file_exists($sitemapPath) && !file_exists($rssPath)) {
    http_response_code(404);
    die('파일을 찾을 수 없습니다. 먼저 크롤링을 실행해주세요.');
}

// zip 생성
$zipFilename = $domain . '_sitemap_rss.zip';
$zipPath = $domainDir . '/' . $zipFilename;

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    die('ZIP 파일 생성에 실패했습니다.');
}

if (file_exists($sitemapPath)) {
    $zip->addFile($sitemapPath, 'sitemap.xml');
}
if (file_exists($rssPath)) {
    $zip->addFile($rssPath, 'rss.xml');
}
$zip->close();

// 마지막 다운로드 시간 기록
$domainsFile = __DIR__ . '/data/domains.json';
if (file_exists($domainsFile)) {
    $domains = json_decode(file_get_contents($domainsFile), true) ?: [];
    $updated = false;
    foreach ($domains as &$d) {
        if ($d['domain'] === $domain) {
            $d['last_downloaded'] = date('Y-m-d\TH:i:s') . '+09:00';
            $updated = true;
            break;
        }
    }
    if ($updated) {
        file_put_contents($domainsFile, json_encode($domains, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

// 다운로드 헤더
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
header('Content-Length: ' . filesize($zipPath));
header('Cache-Control: no-cache');

readfile($zipPath);

// 임시 zip 삭제
unlink($zipPath);
exit;
