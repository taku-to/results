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
    
    // --- 【追加】今日開催されている会場IDを取得する ---
    $year = $date->format('Y');
    $ymd  = $date->format('Ymd');
    $programUrl = "https://boatraceopenapi.github.io/programs/v2/{$year}/{$ymd}.json";
    $programData = json_decode(@file_get_contents($programUrl), true);
    
    // 開催会場IDを重複なく抽出
    $activeStadiums = [];
    if (!empty($programData['programs'])) {
        foreach ($programData['programs'] as $p) {
            $activeStadiums[] = (int)$p['race_stadium_number'];
        }
        $activeStadiums = array_unique($activeStadiums); // 重複削除
    }

    // 会場が見つからない場合は予備で1〜24回すか、あるいは終了
    if (empty($activeStadiums)) {
        // 万が一スケジュールが取れない時のための保険（全会場回す）
        $activeStadiums = range(1, 24);
    }

    // 全24会場ではなく、開催されている会場だけをループ
    foreach ($activeStadiums as $id) {
        $stadiumId = sprintf('%02d', $id);
        
        try {
            $stadiumResults = $scraperInstance->scrapeResults($date, $stadiumId);
            if (!empty($stadiumResults)) {
                $results = array_merge($results, $stadiumResults);
            }
        } catch (\Exception $e) {
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
