<?php
/**
 * auto_post_gemini_v2.php
 *
 * 改善点：
 * - WordPress.org 公式APIから実在プラグインのみ候補化
 * - active_installs / last_updated / tested で足切り
 * - [PLUGIN_NAME][PLUGIN_SLUG][OFFICIAL_URL] を冒頭に固定出力させ、機械検証
 * - 既出プラグインの “名前/slug” を双方で除外
 * - 候補ゼロ時はフォールバック（テーマ記事 or スキップのどちらか）
 *
 * 依存:
 *   - vlucas/phpdotenv
 *   - guzzlehttp/guzzle
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/*===============================================================
 * 0) .env 読み込み
 *===============================================================*/
Dotenv::createImmutable(__DIR__)->load();

/*===============================================================
 * 1) 汎用クライアント
 *===============================================================*/
function wpClient(): Client
{
    static $client = null;
    if ($client) return $client;

    $auth = base64_encode($_ENV['WP_API_USER'] . ':' . $_ENV['WP_API_PASS']);
    $client = new Client([
        'base_uri' => rtrim($_ENV['WP_BASE_URL'], '/') . '/',
        'timeout'  => 30,
        'headers'  => ['Authorization' => "Basic {$auth}"],
    ]);
    return $client;
}

/*===============================================================
 * 2) Google トレンド → 関連検索ワード取得（任意）
 *    失敗時は空配列
 *===============================================================*/
function getTrendingTopics(string $keyword = 'wordpress プラグイン'): array
{
    $base = rtrim($_ENV['TRENDS_BASE'] ?? 'https://trends.google.com', '/');
    $hl   = $_ENV['TRENDS_HL']   ?? 'ja';
    $tz   = (int)($_ENV['TRENDS_TZ'] ?? 540);
    $geo  = $_ENV['TRENDS_GEO']  ?? 'JP';
    $time = $_ENV['TRENDS_TIME'] ?? 'now 7-d';

    $client = new Client([
        'base_uri' => $base,
        'timeout'  => 20,
        'headers'  => [
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        ],
    ]);

    $payload = [
        'comparisonItem' => [[
            'keyword' => $keyword,
            'geo'     => $geo,
            'time'    => $time,
        ]],
        'category' => 0,
        'property' => '',
    ];

    try {
        $explore = $client->get('/trends/api/explore', [
            'query' => ['hl' => $hl, 'tz' => $tz, 'req'=> json_encode($payload, JSON_UNESCAPED_UNICODE)],
        ]);
    } catch (GuzzleException $e) {
        return [];
    }

    $json = preg_replace('/^\)\]\}\'/', '', (string)$explore->getBody());
    $data = json_decode($json, true);
    if (!$data || empty($data['widgets'])) return [];

    $widget = null;
    foreach ($data['widgets'] as $w) {
        if (($w['id'] ?? '') === 'RELATED_QUERIES' || ($w['title'] ?? '') === 'Related queries') {
            $widget = $w;
            break;
        }
    }
    if (!$widget) return [];

    try {
        $rq = $client->get('/trends/api/widgetdata/relatedsearches', [
            'query' => [
                'hl'    => $hl,
                'tz'    => $tz,
                'token' => $widget['token'],
                'req'   => json_encode($widget['request'], JSON_UNESCAPED_UNICODE),
            ],
        ]);
    } catch (GuzzleException $e) {
        return [];
    }

    $json2 = preg_replace('/^\)\]\}\'/', '', (string)$rq->getBody());
    $rqData = json_decode($json2, true);

    $topics = [];
    foreach (($rqData['default']['rankedList'] ?? []) as $list) {
        $type = $list['type'] ?? '';
        foreach ($list['rankedKeyword'] ?? [] as $kw) {
            $q = $kw['query'] ?? ($kw['topic']['title'] ?? null);
            if (!$q) continue;
            $topics[] = [
                'query' => $q,
                'value' => (int)($kw['value'] ?? 0),
                'type'  => (string)$type, // RISING/TOP
            ];
        }
    }
    return $topics;
}

function pickTodayTopic(array $topics, string $fallback = 'WordPress セキュリティ'): string
{
    if (!$topics) return $fallback;
    usort($topics, function ($a, $b) {
        if ($a['type'] !== $b['type']) return $a['type'] === 'RISING' ? -1 : 1;
        return (int)$b['value'] <=> (int)$a['value'];
    });
    return $topics[0]['query'] ?? $fallback;
}

/*===============================================================
 * 3) 既出プラグイン（名前/slug）収集
 *    - 本文冒頭の [PLUGIN_SLUG] 行を優先して拾う
 *    - 旧フォーマットはタイトル先頭「◯◯解説」から名前を抽出
 *===============================================================*/
function fetchUsedPluginIdentifiers(int $days = 60): array
{
    $afterUTC = (new DateTime("-{$days} days", new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s');
    $page = 1;
    $names = [];
    $slugs = [];

    do {
        $res = wpClient()->get('wp-json/wp/v2/posts', [
            'query' => [
                'after'     => $afterUTC,
                'per_page'  => 100,
                'page'      => $page,
                'status'    => 'publish,future',
                '_fields'   => 'title.rendered,content.rendered',
            ],
        ]);
        $posts = json_decode((string) $res->getBody(), true);

        foreach ($posts as $p) {
            $title = (string)($p['title']['rendered'] ?? '');
            $content = (string)($p['content']['rendered'] ?? '');

            // 1) 新フォーマット（先頭のメタ行）
            if (preg_match('/^\[PLUGIN_SLUG\]:\s*([a-z0-9\-]+)\s*$/mi', $content, $m)) {
                $slugs[] = mb_strtolower(trim($m[1]));
            }

            // 2) 旧フォーマット（タイトル「◯◯解説」）
            if (preg_match('/^(.+?)解説/u', $title, $m2)) {
                $names[] = mb_strtolower(trim($m2[1]));
            }
        }
        $page++;
    } while (is_array($posts) && count($posts) === 100);

    return [
        'names' => array_values(array_unique($names)),
        'slugs' => array_values(array_unique($slugs)),
    ];
}

/*===============================================================
 * 4) WordPress.org 公式API検索 & 足切り（GET + unserialize 版）
 *===============================================================*/
function searchPluginsOnDotOrg(string $query, int $page = 1, int $perPage = 20): array
{
    $client = new Client([
        'base_uri' => 'https://api.wordpress.org',
        'timeout'  => 20,
        'headers'  => [
            'User-Agent' => 'NA-Bot/1.0 (+https://next-action.co.jp/)',
        ],
    ]);

    // このAPIは GET かつ、返り値は PHP の serialize 文字列
    $queryParams = [
        'action'  => 'query_plugins',
        'request' => [
            'search'   => $query,
            'page'     => $page,
            'per_page' => $perPage,
            'fields'   => [
                'short_description' => true,   // 閉鎖文言チェック用に true に
                'sections'          => false,
                'icons'             => false,
                'banners'           => false,
                'compatibility'     => true,
            ],
        ],
    ];

    $res = $client->get('/plugins/info/1.2/', ['query' => $queryParams]);
    $raw = (string) $res->getBody();

    // 信頼ドメインからの応答なので unserialize（クラスは禁止）
    $decoded = @unserialize($raw, ['allowed_classes' => false]);
    if (!is_array($decoded)) {
        file_put_contents(__DIR__ . '/log.txt', date('[Y-m-d H:i:s] ') . "WPORG unserialize failed\n", FILE_APPEND);
        return [];
    }

    $plugins = $decoded['plugins'] ?? [];
    // stdClass で来ることがあるので配列化
    return array_map(fn($p) => is_object($p) ? (array)$p : $p, $plugins);
}

function isPluginViable(
    array $p,
    string $currentWpMajor = '6.6',
    ?int $minInstalls = null,
    ?int $maxDays = null,
    ?bool $requireTested = null
): bool {
    // .env から上書き可能（なければデフォルト値）
    $minInstalls = $minInstalls ?? (int)($_ENV['MIN_INSTALLS'] ?? 500);   // ← 1000 → 500 に緩和
    $maxDays     = $maxDays     ?? (int)($_ENV['MAX_DAYS']     ?? 730);   // ← 365 → 730 に緩和（2年）
    $requireTested = $requireTested ?? (bool)($_ENV['REQUIRE_TESTED'] ?? false); // ← 既定は厳格にしない

    if ((int)($p['active_installs'] ?? 0) < $minInstalls) return false;

    $lastUpdated = $p['last_updated'] ?? '';
    if ($lastUpdated) {
        try {
            $last = new DateTime($lastUpdated);
            if ($last < (new DateTime("-{$maxDays} days"))) return false;
        } catch (\Throwable $e) {
            return false;
        }
    } else {
        return false;
    }

    // “tested” は任意チェック（必要なら .env で REQUIRE_TESTED=1）
    if ($requireTested) {
        $tested = (string)($p['tested'] ?? '');
        if ($tested !== '') {
            $testedMajor = implode('.', array_slice(explode('.', $tested), 0, 2));
            if ($testedMajor !== '' && version_compare($testedMajor, $currentWpMajor, '<')) {
                return false;
            }
        } else {
            return false;
        }
    }

    // 閉鎖判定の保険
    $sd = (string)($p['short_description'] ?? '');
    if (stripos($sd, 'This plugin has been closed') !== false) return false;

    return true;
}


function findCandidatePlugin(string $topic, array $usedNames, array $usedSlugs, string $currentWpMajor = '6.6'): ?array
{
    // 日本語トピックから余計な語を削ぎ落とし
    $topicBase = trim(preg_replace('/プラグイン/u', '', $topic));

    // 日本語/英語の汎用クエリも追加（ヒット率UP）
    $queries = array_values(array_unique(array_filter([
        $topic,
        $topicBase,
        'WordPress セキュリティ',
        'WordPress 画像 最適化',
        'WordPress バックアップ',
        'WordPress キャッシュ',
        'フォーム',
        '画像圧縮',
        'SEO',
        // 英語も併用
        'security',
        'backup',
        'cache',
        'caching',
        'image optimization',
        'compression',
        'forms',
        'antispam',
        'firewall',
        'performance',
        'seo',
        'redirect',
        'gallery',
        'analytics',
        'migration',
        'multilingual',
        'woocommerce',
    ])));

    // まずはクエリ検索（各クエリ 1〜3ページ）
    foreach ($queries as $q) {
        for ($page = 1; $page <= 3; $page++) {
            $plugins = searchPluginsOnDotOrg($q, $page);
            if (!$plugins) continue;

            // 除外（名前・slug）
            $plugins = array_filter($plugins, function($p) use ($usedNames, $usedSlugs) {
                $n = mb_strtolower($p['name'] ?? '');
                $s = mb_strtolower($p['slug'] ?? '');
                return !in_array($n, $usedNames, true) && !in_array($s, $usedSlugs, true);
            });

            // 足切り
            $plugins = array_filter($plugins, function($p) use ($currentWpMajor) {
                return isPluginViable($p, $currentWpMajor);
            });
            if (!$plugins) continue;

            // スコア：active_installs DESC → last_updated DESC
            usort($plugins, function($a, $b) {
                $ai = ((int)($b['active_installs'] ?? 0)) <=> ((int)($a['active_installs'] ?? 0));
                if ($ai !== 0) return $ai;
                $ldA = !empty($a['last_updated']) ? strtotime($a['last_updated']) : 0;
                $ldB = !empty($b['last_updated']) ? strtotime($b['last_updated']) : 0;
                return $ldB <=> $ldA;
            });

            $p = array_values($plugins)[0];
            return [
                'name' => $p['name'],
                'slug' => $p['slug'],
                'url'  => 'https://wordpress.org/plugins/' . $p['slug'] . '/',
            ];
        }
    }

    // ===== ここからフォールバック：popular を拾って1件出す =====
    // popular の1〜2ページを見て、未使用＋しきい値OKのものを採用
    for ($page = 1; $page <= 2; $page++) {
        $popular = searchPluginsOnDotOrgWithBrowse('popular', $page, 30); // 下の補助関数を追加
        if (!$popular) continue;

        // 未使用除外
        $popular = array_filter($popular, function($p) use ($usedNames, $usedSlugs) {
            $n = mb_strtolower($p['name'] ?? '');
            $s = mb_strtolower($p['slug'] ?? '');
            return !in_array($n, $usedNames, true) && !in_array($s, $usedSlugs, true);
        });

        // 足切り
        $popular = array_filter($popular, function($p) use ($currentWpMajor) {
            return isPluginViable($p, $currentWpMajor);
        });
        if (!$popular) continue;

        // install 多い順
        usort($popular, function($a, $b) {
            return ((int)($b['active_installs'] ?? 0)) <=> ((int)($a['active_installs'] ?? 0));
        });

        $p = array_values($popular)[0];
        return [
            'name' => $p['name'],
            'slug' => $p['slug'],
            'url'  => 'https://wordpress.org/plugins/' . $p['slug'] . '/',
        ];
    }

    return null;
}

/**
 * browse=popular / featured / recommended / beta を叩く補助
 * （searchPluginsOnDotOrg と同じ GET + unserialize 仕様）
 */
function searchPluginsOnDotOrgWithBrowse(string $browse, int $page = 1, int $perPage = 20): array
{
    $client = new Client([
        'base_uri' => 'https://api.wordpress.org',
        'timeout'  => 20,
        'headers'  => [
            'User-Agent' => 'NA-Bot/1.0 (+https://next-action.co.jp/)',
        ],
    ]);

    $queryParams = [
        'action'  => 'query_plugins',
        'request' => [
            'browse'   => $browse, // popular / featured / recommended / beta
            'page'     => $page,
            'per_page' => $perPage,
            'fields'   => [
                'short_description' => true,
                'sections'          => false,
                'icons'             => false,
                'banners'           => false,
                'compatibility'     => true,
            ],
        ],
    ];

    $res = $client->get('/plugins/info/1.2/', ['query' => $queryParams]);
    $raw = (string) $res->getBody();
    $decoded = @unserialize($raw, ['allowed_classes' => false]);
    if (!is_array($decoded)) return [];
    $plugins = $decoded['plugins'] ?? [];
    return array_map(fn($p) => is_object($p) ? (array)$p : $p, $plugins);
}

/*===============================================================
 * 5) プロンプト（固定題材版）
 *===============================================================*/
function buildPromptForFixedPlugin(string $pluginName, string $pluginSlug, string $officialUrl): string
{
    return <<<PROMPT
あなたは WordPress ブロックエディタ専用ライターです。
必ず Gutenberg ブロック構文で記事全体を出力してください。
**題材プラグインは固定です。下記以外は絶対に使わないでください。**

[PLUGIN_NAME]: {$pluginName}
[PLUGIN_SLUG]: {$pluginSlug}
[OFFICIAL_URL]: {$officialUrl}

▼ 執筆ルール
- 記事冒頭に上記3行を**そのまま**再掲すること
- 最初の h2 見出しは「{$pluginName}」とする
- 本文は 1200 字程度、見出し3つ以上、ですます調
- 本文中に公式URL（{$officialUrl}）を必ず1回以上記載
- 互換性や更新日の注意点があれば触れる

▼ Gutenberg 出力例
<!-- wp:heading {"level":2} -->
<h2>{$pluginName}</h2>
<!-- /wp:heading -->
PROMPT;
}

/*===============================================================
 * 6) Gemini 生成
 *===============================================================*/
function generateContent(string $prompt): string
{
    $client = new Client([
        'base_uri' => 'https://generativelanguage.googleapis.com',
        'timeout'  => 30,
    ]);

    $res = $client->post('/v1beta/models/gemini-1.5-flash:generateContent', [
        'query' => ['key' => $_ENV['GEMINI_API_KEY']],
        'json'  => [
            'contents' => [[
                'role'  => 'user',
                'parts' => [['text' => $prompt]],
            ]],
        ],
    ]);

    $body = json_decode((string) $res->getBody(), true);
    return $body['candidates'][0]['content']['parts'][0]['text'] ?? '(生成失敗)';
}

/*===============================================================
 * 7) 生成結果の検証
 *===============================================================*/
function validateGeneratedContent(string $content, string $pluginName, string $pluginSlug, string $officialUrl): void
{
    // 先頭メタ行の存在
    if (!preg_match('/^\[PLUGIN_NAME\]:\s*'.preg_quote($pluginName, '/').'\s*$/mi', $content)) {
        throw new RuntimeException('生成結果に [PLUGIN_NAME] の確認行がありません/不一致です');
    }
    if (!preg_match('/^\[PLUGIN_SLUG\]:\s*'.preg_quote($pluginSlug, '/').'\s*$/mi', $content)) {
        throw new RuntimeException('生成結果に [PLUGIN_SLUG] の確認行がありません/不一致です');
    }
    if (!preg_match('/^\[OFFICIAL_URL\]:\s*'.preg_quote($officialUrl, '/').'\s*$/mi', $content)) {
        throw new RuntimeException('生成結果に [OFFICIAL_URL] の確認行がありません/不一致です');
    }

    // 最初の h2 がプラグイン名
    if (!preg_match('/<h2[^>]*>\s*'.preg_quote($pluginName, '/').'\s*<\/h2>/i', $content)) {
        throw new RuntimeException('最初の h2 にプラグイン名が設定されていません');
    }

    // 公式URLの出現
    if (stripos($content, $officialUrl) === false) {
        throw new RuntimeException('本文中に公式URLが含まれていません');
    }
}

/*===============================================================
 * 8) WordPress へ予約投稿
 *===============================================================*/
function postToWordPress(string $title, string $content, DateTime $publishGMT): array
{
    $res = wpClient()->post('wp-json/wp/v2/posts', [
        'headers' => ['Content-Type' => 'application/json'],
        'json'    => [
            'title'    => $title,
            'content'  => $content,
            'status'   => 'future',
            'date_gmt' => $publishGMT->format('Y-m-d\TH:i:s'),
        ],
    ]);
    return json_decode((string) $res->getBody(), true) ?? [];
}

/*===============================================================
 * 9) ログユーティリティ
 *===============================================================*/
function logInfo(string $msg): void
{
    file_put_contents(__DIR__ . '/log.txt', date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

/*===============================================================
 * 10) メイン
 *===============================================================*/
try {
    // 10-1) トピック（任意：取れなければフォールバック）
    $todayTopic = pickTodayTopic(getTrendingTopics(), 'WordPress セキュリティ');

    // 10-2) 既出（名前/slug）収集
    $used = fetchUsedPluginIdentifiers(60);
    $usedNames = $used['names'] ?? [];
    $usedSlugs = $used['slugs'] ?? [];

    // 10-3) 公式APIで候補確定
    $candidate = findCandidatePlugin($todayTopic, $usedNames, $usedSlugs, '6.6');
    if (!$candidate) {
        // フォールバック：今日はスキップ（または「ノンプラグイン基礎記事」を別プロンプトで生成）
        logInfo("候補見つからず: topic={$todayTopic} → 投稿スキップ");
        echo "候補が見つからなかったためスキップしました\n";
        exit(0);
    }

    $pluginName = $candidate['name'];
    $pluginSlug = $candidate['slug'];
    $officialUrl = $candidate['url'];

    // 10-4) 生成（最大3回までやり直し）
    $attempt = 0;
    $content = '';
    while ($attempt < 3) {
        $attempt++;
        $prompt  = buildPromptForFixedPlugin($pluginName, $pluginSlug, $officialUrl);
        $content = generateContent($prompt);

        try {
            validateGeneratedContent($content, $pluginName, $pluginSlug, $officialUrl);
            break; // OK
        } catch (\Throwable $e) {
            logInfo("生成検証NG({$attempt}): " . $e->getMessage());
            $content = '';
        }
    }
    if ($content === '') {
        throw new RuntimeException('生成が要件を満たしませんでした（3回失敗）');
    }

    // 10-5) タイトル
    $title = "{$pluginName}解説（" . date('Y-m-d') . '）';

    // 10-6) 予約時刻（明朝 7:00 JST → UTC）
    $hourJst = sprintf('%02d', (int)($_ENV['PUBLISH_HOUR_JST'] ?? '07'));
    $publishJST = new DateTime("tomorrow {$hourJst}:00", new DateTimeZone('Asia/Tokyo'));
    $publishGMT = (clone $publishJST)->setTimezone(new DateTimeZone('UTC'));

    // 10-7) 投稿
    $post = postToWordPress($title, $content, $publishGMT);
    $postId = $post['id'] ?? '???';
    echo "予約投稿 ID {$postId} を作成しました\n";

    // 10-8) ログ
    logInfo("予約投稿 ID {$postId} / topic={$todayTopic} / plugin={$pluginName} ({$pluginSlug})");

} catch (Throwable $e) {
    logInfo('投稿失敗: ' . $e->getMessage());
    throw $e;
}
