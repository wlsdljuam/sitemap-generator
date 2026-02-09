<?php
/**
 * 사이트맵/RSS 생성기 — 웹 UI
 * 모든 웹사이트 대상 (URL 입력 → 크롤링 → 다운로드)
 */

// 등록 도메인 목록 로드
$domainsFile = __DIR__ . '/data/domains.json';
$domains = [];
if (file_exists($domainsFile)) {
    $domains = json_decode(file_get_contents($domainsFile), true) ?: [];
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>사이트맵/RSS 생성기</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, 'Malgun Gothic', sans-serif;
            background: #f5f5f5;
            color: #333;
            line-height: 1.6;
        }
        .container {
            max-width: 900px;
            margin: 40px auto;
            padding: 0 20px;
        }
        h1 {
            text-align: center;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .subtitle {
            text-align: center;
            color: #777;
            margin-bottom: 30px;
        }

        /* 탭 */
        .tabs {
            display: flex;
            border-bottom: 2px solid #ddd;
            margin-bottom: 30px;
        }
        .tab-btn {
            padding: 12px 24px;
            border: none;
            background: none;
            cursor: pointer;
            font-size: 15px;
            color: #888;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            transition: all 0.2s;
        }
        .tab-btn.active {
            color: #2c3e50;
            border-bottom-color: #3498db;
            font-weight: bold;
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* 카드 */
        .card {
            background: #fff;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        .card h2 {
            font-size: 18px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        /* 폼 */
        .form-group {
            margin-bottom: 16px;
        }
        label {
            display: block;
            font-weight: bold;
            margin-bottom: 6px;
            font-size: 14px;
        }
        input[type="url"], input[type="text"], select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        input[type="url"]:focus, input[type="text"]:focus, select:focus {
            outline: none;
            border-color: #3498db;
        }
        .form-row {
            display: flex;
            gap: 16px;
        }
        .form-row .form-group { flex: 1; }

        /* 버튼 */
        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            transition: background 0.2s;
        }
        .btn-primary {
            background: #3498db;
            color: #fff;
        }
        .btn-primary:hover { background: #2980b9; }
        .btn-primary:disabled {
            background: #bdc3c7;
            cursor: not-allowed;
        }
        .btn-success {
            background: #27ae60;
            color: #fff;
        }
        .btn-success:hover { background: #219a52; }
        .btn-danger {
            background: #e74c3c;
            color: #fff;
            padding: 6px 14px;
            font-size: 12px;
        }
        .btn-danger:hover { background: #c0392b; }
        .btn-download {
            background: #8e44ad;
            color: #fff;
            padding: 6px 14px;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
            border-radius: 4px;
            margin-right: 4px;
        }
        .btn-download:hover { background: #7d3c98; }

        /* 진행 상태 */
        #progress {
            display: none;
            margin-top: 20px;
        }
        .progress-bar {
            background: #ecf0f1;
            border-radius: 20px;
            overflow: hidden;
            height: 24px;
        }
        .progress-fill {
            background: linear-gradient(90deg, #3498db, #2ecc71);
            height: 100%;
            width: 0%;
            transition: width 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 12px;
            font-weight: bold;
        }
        #status-text {
            margin-top: 10px;
            font-size: 13px;
            color: #666;
        }

        /* 결과 */
        #result {
            display: none;
            margin-top: 20px;
            padding: 20px;
            background: #eafaf1;
            border-radius: 6px;
            border: 1px solid #27ae60;
        }
        #result h3 {
            color: #27ae60;
            margin-bottom: 12px;
        }
        #result .download-links a {
            margin-right: 10px;
        }

        /* 테이블 */
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            text-align: left;
            padding: 10px 12px;
            border-bottom: 1px solid #eee;
            font-size: 13px;
        }
        th {
            background: #f8f9fa;
            font-weight: bold;
        }
        .empty-msg {
            text-align: center;
            color: #999;
            padding: 40px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Sitemap / RSS Generator</h1>
        <p class="subtitle">URL을 입력하면 사이트를 크롤링하여 sitemap.xml과 rss.xml을 생성합니다</p>

        <div class="tabs">
            <button class="tab-btn active" data-tab="generate">즉시 생성</button>
            <button class="tab-btn" data-tab="domains">도메인 관리 (자동 갱신)</button>
        </div>

        <!-- 탭 1: 즉시 생성 -->
        <div id="tab-generate" class="tab-content active">
            <div class="card">
                <h2>사이트맵/RSS 생성</h2>
                <form id="crawl-form">
                    <div class="form-group">
                        <label for="url">사이트 URL</label>
                        <input type="url" id="url" name="url"
                               placeholder="https://www.example.com" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="max_pages">최대 페이지 수</label>
                            <select id="max_pages" name="max_pages">
                                <option value="100">100</option>
                                <option value="300">300</option>
                                <option value="500" selected>500</option>
                                <option value="1000">1000</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" id="btn-generate">생성 시작</button>
                </form>

                <div id="progress">
                    <div class="progress-bar">
                        <div class="progress-fill" id="progress-fill">0%</div>
                    </div>
                    <div id="status-text">크롤링 준비 중...</div>
                </div>

                <div id="result">
                    <h3>생성 완료</h3>
                    <p id="result-info"></p>
                    <div class="download-links" id="download-links"></div>
                </div>
            </div>
        </div>

        <!-- 탭 2: 도메인 관리 -->
        <div id="tab-domains" class="tab-content">
            <div class="card">
                <h2>도메인 등록 (자동 갱신)</h2>
                <p style="color:#666; font-size:13px; margin-bottom:16px;">
                    도메인을 등록하면 크론탭으로 자동 갱신됩니다.
                    크론 설정: <code>0 3 * * * php <?= __DIR__ ?>/cron.php</code>
                </p>
                <form id="domain-form">
                    <div class="form-row">
                        <div class="form-group" style="flex:3">
                            <label for="domain-url">사이트 URL</label>
                            <input type="url" id="domain-url" name="url"
                                   placeholder="https://www.example.com" required>
                        </div>
                        <div class="form-group" style="flex:1">
                            <label for="domain-interval">갱신 주기</label>
                            <select id="domain-interval" name="interval">
                                <option value="daily">매일</option>
                                <option value="weekly">매주</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex:1">
                            <label for="domain-max">최대 페이지</label>
                            <select id="domain-max" name="max_pages">
                                <option value="300">300</option>
                                <option value="500" selected>500</option>
                                <option value="1000">1000</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-success">도메인 등록</button>
                </form>
            </div>

            <div class="card">
                <h2>등록된 도메인</h2>
                <?php if (empty($domains)): ?>
                    <div class="empty-msg">등록된 도메인이 없습니다.</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>도메인</th>
                                <th>주기</th>
                                <th>페이지 수</th>
                                <th>마지막 갱신</th>
                                <th>마지막 다운로드</th>
                                <th>다운로드</th>
                                <th>관리</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($domains as $d): ?>
                            <tr>
                                <td><?= htmlspecialchars($d['domain']) ?></td>
                                <td><?= $d['interval'] === 'daily' ? '매일' : '매주' ?></td>
                                <td><?= $d['page_count'] ?? '-' ?></td>
                                <td><?= !empty($d['last_crawled']) ? date('Y-m-d H:i', strtotime($d['last_crawled'])) : '미실행' ?></td>
                                <td><?= !empty($d['last_downloaded']) ? date('Y-m-d H:i', strtotime($d['last_downloaded'])) : '-' ?></td>
                                <td>
                                    <?php
                                    $outDir = __DIR__ . '/data/output/' . $d['domain'];
                                    if (file_exists($outDir . '/sitemap.xml')): ?>
                                        <a href="download.php?domain=<?= urlencode($d['domain']) ?>" class="btn-download">ZIP 다운로드</a>
                                    <?php else: ?>
                                        <span style="color:#999">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-danger btn-delete"
                                            data-domain="<?= htmlspecialchars($d['domain']) ?>">삭제</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    // 탭 전환
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
        });
    });

    // 즉시 생성 폼
    document.getElementById('crawl-form').addEventListener('submit', function(e) {
        e.preventDefault();

        const url = document.getElementById('url').value;
        const maxPages = document.getElementById('max_pages').value;
        const btn = document.getElementById('btn-generate');
        const progress = document.getElementById('progress');
        const result = document.getElementById('result');

        btn.disabled = true;
        btn.textContent = '크롤링 중...';
        progress.style.display = 'block';
        result.style.display = 'none';
        document.getElementById('progress-fill').style.width = '10%';
        document.getElementById('progress-fill').textContent = '크롤링 중...';
        document.getElementById('status-text').textContent = url + ' 크롤링을 시작합니다...';

        // 진행 애니메이션
        let pct = 10;
        const progressTimer = setInterval(() => {
            if (pct < 90) {
                pct += Math.random() * 5;
                document.getElementById('progress-fill').style.width = pct + '%';
            }
        }, 500);

        const formData = new FormData();
        formData.append('url', url);
        formData.append('max_pages', maxPages);
        formData.append('action', 'crawl');

        fetch('crawl.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                clearInterval(progressTimer);
                document.getElementById('progress-fill').style.width = '100%';
                document.getElementById('progress-fill').textContent = '완료!';

                if (data.success) {
                    document.getElementById('status-text').textContent =
                        '총 ' + data.page_count + '개 페이지 크롤링 완료';
                    result.style.display = 'block';
                    document.getElementById('result-info').textContent =
                        data.page_count + '개 페이지에서 sitemap.xml과 rss.xml을 생성했습니다.';
                    document.getElementById('download-links').innerHTML =
                        '<a href="download.php?domain=' + encodeURIComponent(data.domain) + '" class="btn btn-primary">Sitemap + RSS 다운로드 (ZIP)</a>';
                } else {
                    document.getElementById('status-text').textContent =
                        '오류: ' + (data.error || '알 수 없는 오류');
                }

                btn.disabled = false;
                btn.textContent = '생성 시작';
            })
            .catch(err => {
                clearInterval(progressTimer);
                document.getElementById('status-text').textContent = '네트워크 오류: ' + err.message;
                btn.disabled = false;
                btn.textContent = '생성 시작';
            });
    });

    // 도메인 등록 폼
    document.getElementById('domain-form').addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData();
        formData.append('url', document.getElementById('domain-url').value);
        formData.append('interval', document.getElementById('domain-interval').value);
        formData.append('max_pages', document.getElementById('domain-max').value);
        formData.append('action', 'register');

        fetch('crawl.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('도메인이 등록되었습니다: ' + data.domain);
                    location.reload();
                } else {
                    alert('오류: ' + (data.error || '알 수 없는 오류'));
                }
            })
            .catch(err => alert('네트워크 오류: ' + err.message));
    });

    // 도메인 삭제
    document.querySelectorAll('.btn-delete').forEach(btn => {
        btn.addEventListener('click', function() {
            const domain = this.dataset.domain;
            if (!confirm(domain + '을(를) 삭제하시겠습니까?')) return;

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('domain', domain);

            fetch('crawl.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('오류: ' + (data.error || '삭제 실패'));
                    }
                });
        });
    });
    </script>
</body>
</html>
