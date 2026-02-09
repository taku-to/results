<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use BVP\Scraper\Scraper;
use Carbon\CarbonImmutable as Carbon;
use BOA\Results\ResultSaver;

$version = $argv[1] ?? 'v2';
$date = Carbon::today('Asia/Tokyo');
$year = $date->format('Y');
$ymd  = $date->format('Ymd');

// 1. 今日の会場取得
$programUrl = "https://boatraceopenapi.github.io/programs/v2/{$year}/{$ymd}.json";
$programData = json_decode(@file_get_contents($programUrl), true);
$activeStadiums = [];
if (!empty($programData['programs'])) {
    foreach ($programData['programs'] as $p) {
        $activeStadiums[] = (int)$p['race_stadium_number'];
    }
    $activeStadiums = array_unique($activeStadiums);
}
if (empty($activeStadiums)) $activeStadiums = range(1, 24);

// 2. 既存データを取得し、強制的にフラットな連想配列として読み込む
$tempMaster = [];
$remoteUrl = "https://taku-to.github.io/results/v2/{$year}/{$ymd}.json";
$currentRaw = @file_get_contents($remoteUrl);

if ($currentRaw) {
    $currentData = json_decode($currentRaw, true);
    $rawList = $currentData['results'] ?? $currentData;
    
    foreach ($rawList as $entry) {
        // 重要：{"1":{...}} のような階層を排除し、中身のデータだけを取り出す
        $data = isset($entry['race_stadium_number']) ? $entry : (is_array($entry) ? reset($entry) : null);
        
        if ($data && isset($data['race_stadium_number'], $data['race_number'])) {
            $key = (int)$data['race_stadium_number'] . '_' . (int)$data['race_number'];
            $tempMaster[$key] = $data; // 常にフラットな形式で保存
        }
    }
}

// 3. 完了済み会場判定
$completedStadiums = [];
$stadiumCount = [];
foreach ($tempMaster as $data) {
    if (!empty($data['payouts']['trifecta'])) {
        $sid = (int)$data['race_stadium_number'];
        $stadiumCount[$sid] = ($stadiumCount[$sid] ?? 0) + 1;
    }
}
foreach ($stadiumCount as $sid => $count) {
    if ($count >= 12) $completedStadiums[] = $sid;
}

$targetStadiums = array_diff($activeStadiums, $completedStadiums);
if (empty($targetStadiums)) exit;

// 4. スクレイピングと上書きマージ
$scraperInstance = Scraper::getInstance();
foreach ($targetStadiums as $id) {
    $stadiumId = sprintf('%02d', $id);
    try {
        $stadiumResults = $scraperInstance->scrapeResults($date, $stadiumId);
        if (empty($stadiumResults)) continue;

        foreach ($stadiumResults as $newEntry) {
            // ここでも階層を排除
            $newData = isset($newEntry['race_number']) ? $newEntry : (is_array($newEntry) ? reset($newEntry) : null);
            
            if ($newData && isset($newData['race_number'])) {
                $key = (int)$id . '_' . (int)$newData['race_number'];
                // 強制的にフラットな形式で上書き（これで重複は物理的に不可能）
                $tempMaster[$key] = $newData;
            }
        }
    } catch (\Exception $e) { continue; }
}

// 5. 保存（キーを破棄して配列に戻す）
$mergedResults = array_values($tempMaster);

if (!empty($mergedResults)) {
    $saver = new ResultSaver();
    $saver->save($mergedResults, "docs/{$version}/" . $year . '/' . $ymd . '.json');
    $saver->save($mergedResults, "docs/{$version}/today.json");
}
