<?php
/**
 * 그누보드5 사이트맵/RSS 생성기
 * - sitemap.xml (Sitemaps.org 프로토콜)
 * - rss.xml (RSS 2.0)
 *
 * 사용법: 브라우저 접속 또는 크론탭 등록
 *   크론 예시: 0 3 * * * php /path/to/generate.php
 */

// 설정 파일 로드
include_once __DIR__ . '/config.php';

// 그누보드5 연결
if (!file_exists($g5_common_path)) {
    die("[오류] common.php 파일을 찾을 수 없습니다: {$g5_common_path}");
}
include_once $g5_common_path;

// 출력 디렉토리 확인
if (!is_dir($output_dir)) {
    if (!mkdir($output_dir, 0755, true)) {
        die("[오류] 출력 디렉토리를 생성할 수 없습니다: {$output_dir}");
    }
}

// ============================================================
// 공개 게시판 목록 조회 (bo_read_level = 1)
// ============================================================
$boards = [];
$sql = "SELECT * FROM {$g5['board_table']} WHERE bo_read_level = 1";
$result = sql_query($sql);
while ($row = sql_fetch_array($result)) {
    $boards[] = $row;
}

if (empty($boards)) {
    die("[알림] 공개 게시판이 없습니다.");
}

// ============================================================
// 1. sitemap.xml 생성
// ============================================================
$sitemap_path = rtrim($output_dir, '/') . '/sitemap.xml';
$fp = fopen($sitemap_path, 'w');
if (!$fp) {
    die("[오류] sitemap.xml 파일을 열 수 없습니다: {$sitemap_path}");
}

fwrite($fp, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
fwrite($fp, '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n");

foreach ($boards as $board) {
    $bo_table = $board['bo_table'];

    // 게시판 최신 게시물 시간 조회
    $sql = "SELECT wr_datetime FROM {$g5['write_prefix']}{$bo_table}
            WHERE wr_id = wr_parent AND wr_is_comment = 0
            ORDER BY wr_datetime DESC LIMIT 1";
    $latest = sql_fetch($sql);

    $lastmod = !empty($latest['wr_datetime'])
        ? format_w3c_datetime($latest['wr_datetime'])
        : date('Y-m-d\TH:i:s') . '+09:00';

    // 게시판 URL
    $board_url = htmlspecialchars(G5_BBS_URL . "/board.php?bo_table={$bo_table}", ENT_XML1, 'UTF-8');
    fwrite($fp, "  <url>\n");
    fwrite($fp, "    <loc>{$board_url}</loc>\n");
    fwrite($fp, "    <lastmod>{$lastmod}</lastmod>\n");
    fwrite($fp, "    <changefreq>{$sitemap_changefreq_board}</changefreq>\n");
    fwrite($fp, "    <priority>{$sitemap_priority_board}</priority>\n");
    fwrite($fp, "  </url>\n");

    // 게시판 내 게시물 URL
    $sql = "SELECT wr_id, wr_datetime FROM {$g5['write_prefix']}{$bo_table}
            WHERE wr_id = wr_parent AND wr_is_comment = 0
            ORDER BY wr_datetime DESC";
    $posts = sql_query($sql);

    while ($post = sql_fetch_array($posts)) {
        $post_lastmod = format_w3c_datetime($post['wr_datetime']);
        $post_url = htmlspecialchars(
            G5_BBS_URL . "/board.php?bo_table={$bo_table}&wr_id={$post['wr_id']}",
            ENT_XML1,
            'UTF-8'
        );

        fwrite($fp, "  <url>\n");
        fwrite($fp, "    <loc>{$post_url}</loc>\n");
        fwrite($fp, "    <lastmod>{$post_lastmod}</lastmod>\n");
        fwrite($fp, "    <changefreq>{$sitemap_changefreq_post}</changefreq>\n");
        fwrite($fp, "    <priority>{$sitemap_priority_post}</priority>\n");
        fwrite($fp, "  </url>\n");
    }
}

fwrite($fp, '</urlset>' . "\n");
fclose($fp);

// ============================================================
// 2. rss.xml 생성 (RSS 2.0)
// ============================================================
$rss_path = rtrim($output_dir, '/') . '/rss.xml';
$fp = fopen($rss_path, 'w');
if (!$fp) {
    die("[오류] rss.xml 파일을 열 수 없습니다: {$rss_path}");
}

// 전체 게시판에서 최신 게시물 수집
$all_posts = [];
foreach ($boards as $board) {
    $bo_table = $board['bo_table'];
    $sql = "SELECT wr_id, wr_subject, wr_content, wr_datetime,
                   '{$bo_table}' AS bo_table
            FROM {$g5['write_prefix']}{$bo_table}
            WHERE wr_id = wr_parent AND wr_is_comment = 0
            ORDER BY wr_datetime DESC
            LIMIT {$rss_item_limit}";
    $result = sql_query($sql);
    while ($row = sql_fetch_array($result)) {
        $all_posts[] = $row;
    }
}

// 날짜순 정렬 후 상위 N개만 추출
usort($all_posts, function ($a, $b) {
    return strcmp($b['wr_datetime'], $a['wr_datetime']);
});
$all_posts = array_slice($all_posts, 0, $rss_item_limit);

// 최신 빌드 시간
$last_build = !empty($all_posts)
    ? format_rfc822_datetime($all_posts[0]['wr_datetime'])
    : date('r');

$site_title_escaped = htmlspecialchars($site_title, ENT_XML1, 'UTF-8');
$site_url_escaped   = htmlspecialchars($site_url, ENT_XML1, 'UTF-8');
$site_desc_escaped  = htmlspecialchars($site_desc, ENT_XML1, 'UTF-8');

fwrite($fp, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
fwrite($fp, '<rss version="2.0">' . "\n");
fwrite($fp, "  <channel>\n");
fwrite($fp, "    <title>{$site_title_escaped}</title>\n");
fwrite($fp, "    <link>{$site_url_escaped}</link>\n");
fwrite($fp, "    <description>{$site_desc_escaped}</description>\n");
fwrite($fp, "    <language>{$rss_language}</language>\n");
fwrite($fp, "    <lastBuildDate>{$last_build}</lastBuildDate>\n");
fwrite($fp, "    <generator>GnuBoard5 Sitemap/RSS Generator</generator>\n");

foreach ($all_posts as $post) {
    $item_url = htmlspecialchars(
        G5_BBS_URL . "/board.php?bo_table={$post['bo_table']}&wr_id={$post['wr_id']}",
        ENT_XML1,
        'UTF-8'
    );
    $item_title = htmlspecialchars($post['wr_subject'], ENT_XML1, 'UTF-8');

    // 본문에서 HTML 태그 제거 후 200자로 제한
    $item_desc = strip_tags($post['wr_content']);
    $item_desc = mb_substr($item_desc, 0, 200, 'UTF-8');
    $item_desc = htmlspecialchars($item_desc, ENT_XML1, 'UTF-8');

    $item_date = format_rfc822_datetime($post['wr_datetime']);

    fwrite($fp, "    <item>\n");
    fwrite($fp, "      <title>{$item_title}</title>\n");
    fwrite($fp, "      <link>{$item_url}</link>\n");
    fwrite($fp, "      <description>{$item_desc}</description>\n");
    fwrite($fp, "      <pubDate>{$item_date}</pubDate>\n");
    fwrite($fp, "      <guid>{$item_url}</guid>\n");
    fwrite($fp, "    </item>\n");
}

fwrite($fp, "  </channel>\n");
fwrite($fp, '</rss>' . "\n");
fclose($fp);

// ============================================================
// 결과 출력
// ============================================================
echo "[완료] sitemap.xml 생성: {$sitemap_path}\n";
echo "[완료] rss.xml 생성: {$rss_path}\n";

// ============================================================
// 헬퍼 함수
// ============================================================

/**
 * 그누보드5 datetime을 W3C Datetime 형식으로 변환
 * 입력: "2024-01-15 10:30:00"
 * 출력: "2024-01-15T10:30:00+09:00"
 */
function format_w3c_datetime($datetime)
{
    if (empty($datetime)) {
        return date('Y-m-d\TH:i:s') . '+09:00';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return date('Y-m-d\TH:i:s') . '+09:00';
    }
    return date('Y-m-d\TH:i:s', $ts) . '+09:00';
}

/**
 * 그누보드5 datetime을 RFC 822 형식으로 변환
 * 입력: "2024-01-15 10:30:00"
 * 출력: "Mon, 15 Jan 2024 10:30:00 +0900"
 */
function format_rfc822_datetime($datetime)
{
    if (empty($datetime)) {
        return date('r');
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return date('r');
    }
    return date('D, d M Y H:i:s +0900', $ts);
}
