<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/image_utils.php';

function bad_request($msg) { http_response_code(400); echo '<p>'.htmlspecialchars($msg, ENT_QUOTES, 'UTF-8').'</p>'; exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { bad_request('不正なアクセスです'); }

// 入力チェック
if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) { bad_request('画像のアップロードに失敗しました'); }
if (!isset($_POST['self_score'])) { bad_request('自己評価が未入力です'); }
$self = (int) $_POST['self_score'];
if ($self < 0 || $self > 100) { bad_request('自己評価は0〜100で入力してください'); }

// サイズ制限（サーバ設定による）
if ($_FILES['photo']['size'] > 2 * 1024 * 1024) { bad_request('画像サイズは2MB以内にしてください'); }

try {
    $image_url_b64 = resize_to_base64_jpeg($_FILES['photo']['tmp_name'], 600, 85);
} catch (Exception $e) {
    bad_request('画像処理でエラー：' . $e->getMessage());
}

// GPT APIに送るプロンプト（JSONで返すよう厳しめ指示）
$system_prompt = 'あなたは写真の「夏らしさ」を採点する厳格で簡潔な審査員。出力は必ずJSONのみ（キーは score と comment）。';

$user_prompt = <<<EOT
写真の「夏らしさ」を100点満点で評価してください。出力はJSONのみ。
採点ルール（重要）：
- 加点：青空／入道雲／海／ひまわり／麦わら帽子／かき氷／夏祭り／夕立 などの夏要素
- 減点：桜（さくら／cherry blossoms）＝春、紅葉＝秋、雪景色＝冬 など季節外れ要素
- 減点対象が明確に写る場合は大幅減点：原則 30〜50点台に収める
- コメントは日本語のですます調、かつ200字以内で、加減点理由を1つだけわかりやすく述べる

出力形式（例）：
{"score": 40, "comment": "青空と入道雲は夏らしいですが、満開の桜が季節感を損ねています。惜しいですね。"}

参考：投稿者の自己評価は {$self} 点。写真は次に添付します。
EOT;

$payload = [
  'model' => $OPENAI_MODEL,
  'messages' => [
    ['role' => 'system', 'content' => $system_prompt],
    ['role' => 'user', 'content' => [
      ['type' => 'text', 'text' => $user_prompt],
      ['type' => 'image_url', 'image_url' => ['url' => $image_url_b64]],
    ]],
  ],
  // 出力を短めに（コスト＆安定）
  'max_completion_tokens' => 150,
  // JSONのみを強制（chat.completions 対応）
  'response_format' => ['type' => 'json_object'],
  'seed' => 1234,
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $OPENAI_API_KEY,
  ],
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
  CURLOPT_TIMEOUT => $HTTP_TIMEOUT,
]);
$response = curl_exec($ch);
if ($response === false) { bad_request('API呼び出しに失敗：'.curl_error($ch)); }
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// ログ出力関数（生のレスポンスは画面に出さずファイルに残す）
function ai_log($message) {
    $logdir = __DIR__ . '/logs';
    if (!is_dir($logdir)) { @mkdir($logdir, 0755, true); }
    $file = $logdir . '/ai_debug.log';
    $entry = sprintf("[%s] %s\n", date('c'), $message);
    @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
}

// HTTP エラー時はログに残してユーザーには一般的なエラーを返す
if ($code < 200 || $code >= 300) {
    $snippet = mb_substr($response ?? '', 0, 4000);
    ai_log("HTTP {$code} from API. response_snippet=" . $snippet);
    bad_request('AIサービスでエラーが発生しました（後でもう一度試してください）。');
}

// レスポンスをまずデコードして構造を確認
$data = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    ai_log('json_decode(response) error: ' . json_last_error_msg() . ' | response_snippet=' . mb_substr($response, 0, 4000));
    bad_request('AI応答の解析に失敗しました（内部エラー）。');
}

$raw = $data['choices'][0]['message']['content'] ?? '';
$finish_reason = $data['choices'][0]['finish_reason'] ?? null;

// finish_reason が length で content が空の場合、一度だけトークン上限を増やして再試行
if (( !is_string($raw) || trim($raw) === '' ) && $finish_reason === 'length') {
    ai_log('Empty content with finish_reason=length detected. Retrying with larger max_completion_tokens.');
    // 再試行用に max_completion_tokens を増やす（例: 300）
    $payload['max_completion_tokens'] = 300;

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $OPENAI_API_KEY,
      ],
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
      CURLOPT_TIMEOUT => $HTTP_TIMEOUT,
    ]);
    $response2 = curl_exec($ch);
    if ($response2 === false) { ai_log('Retry API call failed: ' . curl_error($ch)); bad_request('AIサービスでエラーが発生しました（後でもう一度試してください）。'); }
    $code2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code2 < 200 || $code2 >= 300) {
        ai_log("Retry returned HTTP {$code2}. response_snippet=" . mb_substr($response2 ?? '', 0, 4000));
        bad_request('AIサービスでエラーが発生しました（後でもう一度試してください）。');
    }
    $data = json_decode($response2, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        ai_log('json_decode(retry_response) error: ' . json_last_error_msg() . ' | response_snippet=' . mb_substr($response2, 0, 4000));
        bad_request('AI応答の解析に失敗しました（内部エラー）。');
    }
    $raw = $data['choices'][0]['message']['content'] ?? '';
}

if (!is_string($raw) || trim($raw) === '') {
    ai_log('Missing or empty choices[0].message.content in API response. full_response_snippet=' . mb_substr($response, 0, 4000));
    bad_request('AIの応答が不正です。');
}

// まずそのまま JSON として解析を試みる
$parsed = json_decode(trim($raw), true);
if (!is_array($parsed)) {
    // 失敗した場合、本文から最初のJSONオブジェクトを抜き出して再試行
    if (preg_match('/(\{(?:[^{}]|(?R))*\})/s', $raw, $m)) {
        $candidate = $m[1];
        $parsed = json_decode($candidate, true);
        if (!is_array($parsed)) {
            ai_log('Inner JSON parse failed after extracting object: ' . json_last_error_msg() . " | raw_snippet=" . mb_substr($raw, 0, 2000));
            ai_log('Full API response snippet: ' . mb_substr($response, 0, 4000));
            bad_request('AI応答の解析に失敗しました（内部エラー）。');
        }
    } else {
        ai_log('Could not locate JSON object in raw content. raw_snippet=' . mb_substr($raw, 0, 2000));
        ai_log('Full API response snippet: ' . mb_substr($response, 0, 4000));
        bad_request('AI応答の解析に失敗しました（内部エラー）。');
    }
}

// 必須フィールドのチェック
if (!isset($parsed['score']) || !isset($parsed['comment'])) {
    ai_log('Parsed JSON missing expected keys. parsed=' . var_export($parsed, true) . ' | raw_snippet=' . mb_substr($raw, 0, 2000));
    bad_request('AI応答の形式が予期せぬものでした。');
}

$score = (int) $parsed['score'];
$comment = (string) $parsed['comment'];

// 最後に受け取ったAPIレスポンス（再試行があれば$response2を優先）を保持
$last_api_response = '';
if (isset($response2) && $response2 !== null) {
    $last_api_response = $response2;
} elseif (isset($response) && $response !== null) {
    $last_api_response = $response;
}

// 開発用に表示するが、機密や長いバイナリ/URL等は伏せる
function sanitize_api_response(string $raw): string {
    // まずバイナリっぽい長い base64 を簡易除去
    $raw = preg_replace('/data:[^;\s]+;base64,[A-Za-z0-9+\/=\s]+/i', '[REDACTED_BINARY]', $raw);
    // URL を伏せる
    $raw = preg_replace_callback('/https?:\/\/[\w\-\.\/%\?=&+#,:;@\[\]]{10,}/i', function($m){ return '[REDACTED_URL]'; }, $raw);
    // JSON としてパースできれば不要キーを削除して再フォーマット
    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $keysToRedact = ['id', 'system_fingerprint', 'object', 'url', 'image_url', 'authorization', 'api_key'];
        $filterRecursive = function(&$arr) use (&$filterRecursive, $keysToRedact) {
            if (!is_array($arr)) return;
            foreach ($arr as $k => &$v) {
                if (in_array($k, $keysToRedact, true)) {
                    $v = '[REDACTED]';
                    continue;
                }
                if (is_string($v)) {
                    // 長い文字列（多くはバイナリや個人情報になり得る）を短縮
                    if (strlen($v) > 400) { $v = '[REDACTED_LONG]'; continue; }
                    // email-like を伏せる
                    if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $v)) { $v = '[REDACTED_EMAIL]'; continue; }
                    // 画像 data URI 再チェック
                    if (preg_match('/^data:[^;]+;base64,/i', $v)) { $v = '[REDACTED_BINARY]'; continue; }
                }
                if (is_array($v)) { $filterRecursive($v); }
            }
            unset($v);
        };
        $filterRecursive($decoded);
        $raw = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($raw === false) { // フォールバック
            $raw = '[REDACTED]';
        }
        return $raw;
    }
    // JSONでなければ簡易的に id 等をマスクして返す
    $raw = preg_replace('/"id"\s*:\s*"[^"]+"/i', '"id":"[REDACTED]"', $raw);
    $raw = preg_replace('/"system_fingerprint"\s*:\s*"[^"]+"/i', '"system_fingerprint":"[REDACTED]"', $raw);
    $raw = preg_replace('/"object"\s*:\s*"[^"]+"/i', '"object":"[REDACTED]"', $raw);
    // 最後に長い連続文字列を短縮
    $raw = preg_replace('/[A-Za-z0-9+\/]{200,}/', '[REDACTED_LONG]', $raw);
    return $raw;
}

// 表示用HTML（素朴でOK）
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>結果｜夏らしさ採点</title>
  <link rel="stylesheet" href="assets/style.css" />
</head>
<body>
  <main class="container">
    <h1>結果</h1>
    <div class="grid">
      <figure>
        <img src="<?= htmlspecialchars($image_url_b64, ENT_QUOTES, 'UTF-8') ?>" alt="uploaded" />
        <figcaption>送信画像</figcaption>
      </figure>
      <section>
        <p><strong>自己評価：</strong><?= (int)$self ?> 点</p>
        <p><strong>AI評価：</strong><?= (int)$score ?> 点</p>
        <p><strong>AI感想：</strong><?= htmlspecialchars($comment, ENT_QUOTES, 'UTF-8') ?></p>
        <p><a href="index.php">別の画像で試す</a></p>
      </section>
    </div>
  </main>
  <main class="container">
    <label>スポンサーリンク</label>
    <script>
      // 非同期で広告を読み込む
      fetch('inc/adsense.php')
        .then(response => response.text())
        .then(html => {
          document.getElementById('adsense-placeholder').innerHTML = html;
        })
        .catch(error => {
          console.error('広告の読み込みに失敗しました:', error);
        });
    </script>
    <?php if (file_exists('inc/adsense.php')) {
      include 'inc/adsense.php';
    } else {
      // ファイルが存在しない場合は何もしないか、メッセージを表示
      // echo '<!-- adsense.php not found -->';
    }
    ?>
  </main>
  <main class="container">
    <details>
      <summary>JSON（エンジニア向け情報）</summary>
      <?php if ($last_api_response !== ''): ?>
        <pre style="white-space:pre-wrap;max-height:360px;overflow:auto;padding:.8rem;background:#0f1720;color:#e6f6f4;border-radius:8px;border:1px solid rgba(255,255,255,0.04);">
<?= htmlspecialchars(sanitize_api_response($last_api_response), ENT_QUOTES, 'UTF-8') ?>
        </pre>
      <?php else: ?>
        <p>表示できるAPIレスポンスがありません。</p>
      <?php endif; ?>
    </details>
  </main>
</body>
</html>
