<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use BVP\Scraper\Scraper;
use Carbon\CarbonImmutable as Carbon;
use BOA\Results\ResultSaver;

// 設定
$version = $argv[1] ?? 'v2';
$date = Carbon::today('Asia/Tokyo');
$year = $date->format('Y');
$ymd  = $date->format('Ymd');

// 1. 対象会場の設定 (1〜24番すべて)
$activeStadiums = range(1, 24);

// 2. 既存データの取得と整理
$tempMaster = [];
$remoteUrl = "https://taku-to.github.io/results/v2/{$year}/{$ymd}.json";
$currentRaw = @file_get_contents($remoteUrl);

if ($currentRaw) {
    $currentData = json_decode($currentRaw, true);
    // resultsキー配下、またはトップレベルが配列か確認
    $rawList = $currentData['results'] ?? (is_array($currentData) ? $currentData : []);
    
    foreach ($rawList as $entry) {
        // ネスト（階層）がある場合を考慮してデータを抽出
        $data = isset($entry['race_stadium_number']) ? $entry : (is_array($entry) ? reset($entry) : null);
        
        if ($data && isset($data['race_stadium_number'], $data['race_number'])) {
            $key = (int)$data['race_stadium_number'] . '_' . (int)$data['race_number'];
            $tempMaster[$key] = $data;
        }
    }
}

// 3. すでに全12Rの配当が埋まっている会場を除外（効率化）
$targetStadiums = [];
foreach ($activeStadiums as $sid) {
    $finishedCount = 0;
    for ($r = 1; $r <= 12; $r++) {
        $race = $tempMaster["{$sid}_{$r}"] ?? null;
        if (isset($race['payouts']['trifecta']) && !empty($race['payouts']['trifecta'])) {
            $finishedCount++;
        }
    }
    if ($finishedCount < 12) {
        $targetStadiums[] = $sid;
    }
}

if (empty($targetStadiums)) exit("All races completed.\n");

// 4. スクレイピング実行
$scraperInstance = Scraper::getInstance();
foreach ($targetStadiums as $id) {
    $stadiumId = sprintf('%02d', $id);
    try {
        $stadiumResults = $scraperInstance->scrapeResults($date, $stadiumId);
        if (empty($stadiumResults)) continue;

        foreach ($stadiumResults as $newEntry) {
            $newData = isset($newEntry['race_number']) ? $newEntry : (is_array($newEntry) ? reset($newEntry) : null);
            if ($newData && isset($newData['race_number'])) {
                $key = (int)$id . '_' . (int)$newData['race_number'];
                // 最新データで上書き
                $tempMaster[$key] = $newData;
            }
        }
    } catch (\Exception $e) {
        echo "Error at Stadium {$stadiumId}: " . $e->getMessage() . "\n";
        continue;
    }
}

// 5. 保存用にソート
$mergedResults = array_values($tempMaster);
usort($mergedResults, function($a, $b) {
    if ((int)$a['race_stadium_number'] === (int)$b['race_stadium_number']) {
        return (int)$a['race_number'] <=> (int)$b['race_number'];
    }
    return (int)$a['race_stadium_number'] <=> (int)$b['race_stadium_number'];
});

// 6. 保存実行
if (!empty($mergedResults)) {
    $saver = new ResultSaver();
    // フォルダ階層は自動作成される前提
    $saver->save($mergedResults, "docs/{$version}/{$year}/{$ymd}.json");
    $saver->save($mergedResults, "docs/{$version}/today.json");
}
