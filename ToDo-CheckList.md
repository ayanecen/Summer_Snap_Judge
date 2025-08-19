- [x] inc/config.php に APIキーを設定
- [x] パーミッションを確認（uploads, resized, tmp, logs） — 書き込み確認済み
- [x] .htaccess をアップロードして保護 — inc 以下の直接アクセスをブロック済み
- [x] PHP バージョンを 8 系に設定 — 動作環境を合わせて確認済み
- [x] 実動作確認（画像アップ→結果表示） — 成功
- [x] `logs` フォルダの直接アクセスを制限 — `.htaccess` によりブロック（注記あり）

備考:
- AIデバッグログは `logs/ai_debug.log` に出力されます（エラーや応答のスニペット）。
- 開発時のみ、結果ページ下部に生のAPIレスポンス（HTMLエスケープ済み）を表示する `details` ブロックを追加できます（`inc/config.php` の DEBUG フラグで制御）。
- 本番化済み: `details` は無効化推奨。`logs` フォルダへの直接アクセスは `.htaccess` で制御済み（Apache向けのルールを追加）。
  - 注意: 一部ホスティングでは `.htaccess` が無効または挙動が異なるため、可能であれば `logs/` をウェブルート外に移動し、最小権限で書き込み許可を与えてください。
- 簡易検証コマンド（公開ホストで確認）:
  - curl -I "https://your.example/path/to/logs/ai_debug.log"  → 403/アクセス拒否 を期待
