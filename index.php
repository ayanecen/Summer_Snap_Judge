<?php /* index.php */ ?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>夏らしさ・自己採点</title>
  <link rel="stylesheet" href="assets/style.css" />
  <script src="assets/script.js" defer></script>
</head>
<body>
  <main class="container">
    <h1>夏らしさをAIに採点してもらう</h1>
    <p class="lead">あなたの“夏っぽい写真”をAIが採点します！</p>
    <form method="post" action="process.php" enctype="multipart/form-data">
      <label>夏っぽい写真（JPEG/PNG/WEBP, 2MBまで）
        <input type="file" name="photo" id="photoInput" accept="image/jpeg,image/png,image/webp" required />
      </label>
      <div id="preview"></div>
      <!-- 自己評価：テキスト入力からスライダーに変更 -->
      <label>自己評価（0〜100）
        <div class="slider-wrap">
          <input type="range" id="selfRange" name="self_score" min="0" max="100" value="50" />
          <output id="selfRangeValue">50</output>
        </div>
      </label>
      <button type="submit">送信</button>
    </form>
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
</body>
</html>
