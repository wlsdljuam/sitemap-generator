<?php
/**
 * 그누보드5 사이트맵/RSS 생성기 설정
 */

// 그누보드5 common.php 절대 경로 (필수 변경)
$g5_common_path = "/var/www/common.php";

// 사이트 기본 정보 (필수 변경)
$site_url   = "https://www.example.com";
$site_title = "사이트 이름";
$site_desc  = "사이트 설명";

// 생성 파일 저장 경로 - 서버 절대 경로 (필수 변경)
$output_dir = "/var/www/sitemap/";

// 사이트맵 옵션
$sitemap_changefreq_board = "daily";
$sitemap_changefreq_post  = "weekly";
$sitemap_priority_board   = "0.9";
$sitemap_priority_post    = "0.5";

// RSS 옵션
$rss_item_limit = 50;   // RSS에 포함할 최신 게시물 수
$rss_language   = "ko";
