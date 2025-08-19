<?php
// 直アクセス禁止（include経由以外を拒否）
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) { http_response_code(403); exit; }

// ★必ず自分のAPIキーを設定
// OpenAIのダッシュボードで取得したキーをセット
$OPENAI_API_KEY = 'sk-REPLACE_ME';

// 画像入力に対応した軽量モデル名（例）
// 利用可能な最新のVision対応モデルに置き換えてください。
// $OPENAI_MODEL = 'gpt-5-mini'; // 例：低コスト＆十分な精度
$OPENAI_MODEL = 'gpt-4o-mini'; // 例：低コスト＆十分な精度＆推論を使わない

// タイムアウト（秒）
$HTTP_TIMEOUT = 30;
