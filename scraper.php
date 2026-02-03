<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use BOA\Results\ResultScraper;
use BOA\Results\ResultSaver;
use BOA\Results\ScraperAdapter;
use BVP\Scraper\Scraper;
use Carbon\CarbonImmutable as Carbon;

// コマンドライン引数からバージョンを取得（デフォルトは v2）
$version = $argv[1] ?? 'v2';

// 本日の日付を東京時間で取得
$date = Carbon::today('Asia/Tokyo');

$results = [];

// v2 の場合のみ実行
if ($version === 'v2') {
    $scraperInstance = Scraper::getInstance();
    
    // 全24会場をループして個別に取得を試みる
    // こうすることで、特定の会場だけが失敗しても他を確実に拾えます
    for ($i = 1; $i <= 24; $i++) {
        // 重要：場コードを 03, 07 のように2桁に固定してリクエスト
        $stadiumId = sprintf('%02d', $i);
        
        try {
            // 個別会場の結果を取得（ライブラリの仕様に合わせた呼び出し）
            $stadiumResults = $scraperInstance->scrapeResults($date, $stadiumId);
            
            if (!empty($stadiumResults)) {
                // 配列を結合
                $results = array_merge($results, $stadiumResults);
            }
        } catch (\Exception $e) {
            // 特定の会場でエラーが出てもスキップして次へ
            continue;
        }
    }
}

// 結果データが取得できなかった場合は処理終了
if (empty($results)) {
    exit;
}

// 結果データを JSON ファイルとして保存
$saver = new ResultSaver();
$saver->save($results, "docs/{$version}/" . $date->format('Y') . '/' . $date->format('Ymd') . '.json');
$saver->save($results, "docs/{$version}/today.json");
