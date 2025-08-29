<?php
/**
 * auto_post_gemini.php
 *
 * Googleトレンド → トピック抽出 → Gemini 生成 → “過去に書いたプラグイン名”を除外 →
 * WordPress へ予約投稿（翌朝 7:00 JST）→ ログ出力
 *
 * 依存:
 *   - vlucas/phpdotenv
 *   - guzzlehttp/guzzle
 *
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
 * 2) Google トレンド → 関連検索ワード取得
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
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) '
                           .'AppleWebKit/537.36 (KHTML, like Gecko) '
                           .'Chrome/124.0 Safari/537.36',
        ],
    ]);

    // 1) explore で token
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
            'query' => [
                'hl' => $hl,
                'tz' => $tz,
                'req'=> json_encode($payload, JSON_UNESCAPED_UNICODE),
            ],
        ]);
    } catch (GuzzleException $e) {
        return [];
    }

    $json = preg_replace('/^\)\]\}\'/', '', (string)$explore->getBody());
    $data = json_decode($json, true);
    if (!$data || empty($data['widgets'])) return [];

    $widget = null;
    foreach ($data['widgets'] as $w) {
        if (($w['id'] ?? '') === 'RELATED_QUERIES'
            || ($w['title'] ?? '') === 'Related queries') {
            $widget = $w;
            break;
        }
    }
    if (!$widget) return [];

    // 2) widgetdata
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
        $type = $list['type'] ?? '';  // RISING / TOP
        foreach ($list['rankedKeyword'] ?? [] as $kw) {
            $q = $kw['query'] ?? ($kw['topic']['title'] ?? null);
            if (!$q) continue;
            $topics[] = [
                'query' => $q,
                'value' => $kw['value'] ?? 0,
                'type'  => $type,
            ];
        }
    }
    return $topics;
}

/*===============================================================
 * 3) 今日使うトピックを決定
 *===============================================================*/
function pickTodayTopic(array $topics,
                        string $fallback = 'WordPress プラグイン'): string
{
    if (!$topics) return $fallback;

    usort($topics, function ($a, $b) {
        if ($a['type'] !== $b['type']) {
            return $a['type'] === 'RISING' ? -1 : 1;
        }
        return (int)$b['value'] <=> (int)$a['value'];
    });
    return $topics[0]['query'] ?? $fallback;
}

/*===============================================================
 * 4) 過去 N 日分の “使ったプラグイン名” を収集
 *===============================================================*/
function fetchUsedPluginNames(int $days = 60): array
{
    $afterUTC = (new DateTime("-{$days} days", new DateTimeZone('UTC')))
                    ->format('Y-m-d\TH:i:s');
    $page = 1;
    $names = [];

    do {
        $res = wpClient()->get('wp-json/wp/v2/posts', [
            'query' => [
                'after'     => $afterUTC,
                'per_page'  => 100,
                'page'      => $page,
                'status'    => 'publish,future',
                '_fields'   => 'title.rendered',
            ],
        ]);
        $posts = json_decode((string) $res->getBody(), true);
        foreach ($posts as $p) {
            if (preg_match('/^(.+?)解説/u', $p['title']['rendered'], $m)) {
                $names[] = trim($m[1]);
            }
        }
        $page++;
    } while (count($posts) === 100);

    return array_unique($names);
}

/*===============================================================
 * 5) プロンプト生成
 *===============================================================*/
function buildPrompt(string $todayTopic, array $usedPlugins): string
{
    $avoid = $usedPlugins
        ? '▼ 過去に使用したプラグイン名（**選ばないでください**）' . PHP_EOL
          . implode('、', $usedPlugins) . PHP_EOL
        : '';

    return <<<PROMPT
あなたは WordPress ブロックエディタ専用ライターです。
必ず Gutenberg ブロック構文で記事全体を出力してください。

▼ 今日のトピック
「{$todayTopic}」

{$avoid}▼ 執筆ルール
- テーマ：WordPress + プラグイン（上のトピックに即したプラグインを 1 つ自由に選び、**ただし上記プラグインは除外**。選んだプラグイン名を最初の h2 見出しにしてください）
- 本文：1200 字程度
- 見出し：最低 3 つ
- 文体：初心者にもわかりやすい「ですます調」

▼ Gutenberg 出力例
<!-- wp:heading {"level":2} -->
<h2>見出し</h2>
<!-- /wp:heading -->
PROMPT;
}

/*===============================================================
 * 6) Gemini で本文を生成
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
 * 7) 生成結果からプラグイン名（最初の h2）を抽出
 *===============================================================*/
function extractPluginName(string $content): string
{
    if (preg_match('/<h2[^>]*>(.+?)<\/h2>/i', $content, $m)) {
        return trim(strip_tags($m[1]));
    }
    return 'WPプラグイン';
}

/*===============================================================
 * 8) WordPress へ予約投稿
 * 人間が手動で「新規投稿」→「予約日時設定」しているのと同じことを、REST API経由で自動化してます。
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
 * 9) メイン処理
 *===============================================================*/
try {
    // 9-1) トピック決定
    $todayTopic = pickTodayTopic(getTrendingTopics(), 'WordPress セキュリティ プラグイン');

    // 9-2) 使用済みプラグイン一覧
    $usedPlugins = fetchUsedPluginNames(60);

    // 9-3) 生成＆重複チェック（最大3回）
    $content = $pluginName = '';
    $attempt = 0;
    do {
        $attempt++;
        $prompt     = buildPrompt($todayTopic, $usedPlugins);
        $content    = generateContent($prompt);
        $pluginName = extractPluginName($content);
    } while (in_array($pluginName, $usedPlugins, true) && $attempt < 3);

    // 9-4) タイトル
    $title = "{$pluginName}解説（" . date('Y-m-d') . '）';

    // 9-5) 予約時刻（明朝 7:00 JST → UTC）
    $hourJst = sprintf('%02d', (int)($_ENV['PUBLISH_HOUR_JST'] ?? '07'));
    $publishJST = new DateTime("tomorrow {$hourJst}:00", new DateTimeZone('Asia/Tokyo'));
    $publishGMT = (clone $publishJST)->setTimezone(new DateTimeZone('UTC'));

    // 9-6) 投稿
    $post = postToWordPress($title, $content, $publishGMT);
    $postId = $post['id'] ?? '???';
    echo "予約投稿 ID {$postId} を作成しました\n";

    // 9-7) ログ
    file_put_contents(
        __DIR__ . '/log.txt',
        date('[Y-m-d H:i:s] ')
        . "予約投稿 ID {$postId} / topic={$todayTopic} / plugin={$pluginName}\n",
        FILE_APPEND
    );
} catch (Throwable $e) {
    file_put_contents(
        __DIR__ . '/log.txt',
        date('[Y-m-d H:i:s] ') . '投稿失敗: ' . $e->getMessage() . "\n",
        FILE_APPEND
    );
    throw $e;
}