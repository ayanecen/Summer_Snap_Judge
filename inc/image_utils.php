<?php
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) { http_response_code(403); exit; }

function resize_to_base64_jpeg(string $tmpPath, int $max = 600, int $quality = 85): string {
    [$w, $h, $type] = getimagesize($tmpPath);
    if (!$w || !$h) { throw new Exception('画像の読み込みに失敗しました'); }

    // 入力形式に応じてGDで読み込み
    switch ($type) {
        case IMAGETYPE_JPEG: $src = imagecreatefromjpeg($tmpPath); break;
        case IMAGETYPE_PNG:  $src = imagecreatefrompng($tmpPath);  break;
        case IMAGETYPE_WEBP: $src = imagecreatefromwebp($tmpPath); break;
        default: throw new Exception('対応形式は JPEG / PNG / WEBP です');
    }

    // 縮小不要ならそのまま再エンコード
    $ratio = min($max / $w, $max / $h, 1.0);
    $nw = (int) floor($w * $ratio); $nh = (int) floor($h * $ratio);

    $dst = imagecreatetruecolor($nw, $nh);
    // PNGの透過対策
    imagealphablending($dst, true); imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    ob_start();
    imagejpeg($dst, null, $quality);
    $jpeg = ob_get_clean();

    imagedestroy($src); imagedestroy($dst);

    // 保存先ディレクトリを ./resized/YYYY/MM/DD に作成してファイルを保存
    try {
        $baseDir = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
        $resizedDir = $baseDir . '/resized/' . date('Y') . '/' . date('m') . '/' . date('d');
        if (!is_dir($resizedDir)) {
            @mkdir($resizedDir, 0755, true);
        }
        $timestamp = date('His');
        try {
            $rand = bin2hex(random_bytes(4));
        } catch (Exception $e) {
            $rand = substr(md5(uniqid('', true)), 0, 8);
        }
        $filename = $timestamp . '_' . $rand . '.jpg';
        $filepath = $resizedDir . '/' . $filename;
        // ファイル書き込み（失敗しても処理は続行）
        @file_put_contents($filepath, $jpeg);
        @chmod($filepath, 0644);
    } catch (Throwable $e) {
        // 保存に失敗しても例外を投げず、処理を継続して base64 を返す
    }

    return 'data:image/jpeg;base64,' . base64_encode($jpeg);
}
