<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use BVP\Scraper\Scraper;
use Carbon\CarbonImmutable as Carbon;
use BOA\Results\ResultSaver;

// コマンドライン引数からバージョンを取得
$version = $argv[1] ?? 'v2';
$date = Carbon::today('Asia/Tokyo');
$year = $date->format('Y');
$ymd  = $date->format('Ymd');

// 1. 今日開催されている会場IDを取得 (Boatrace Open API)
$programUrl = "https://boatraceopenapi.github.io/programs/v2/{$year}/{$ymd}.json";
$programData = json_decode(@file_get_contents($programUrl), true);

$activeStadiums = [];
if (!empty($programData['programs'])) {
    foreach ($programData['programs'] as $p) {
        $activeStadiums[] = (int)$p['race_stadium_number'];
    }
    $activeStadiums = array_unique($activeStadiums);
}
if (empty($activeStadiums)) {
    $activeStadiums = range(1, 24);
}

// 2. 既存のデータをGitHub Pages（公開URL）から確実に取得する
$existingResults = [];
$remoteUrl = "https://taku-to.github.io/results/v2/{$year}/{$ymd}.json";
$currentRaw = @file_get_contents($remoteUrl);

if ($currentRaw) {
    $currentData = json_decode($currentRaw, true);
    $tempData = $currentData['results'] ?? $currentData;
    
    // 構造をフラット化して取り込む
    foreach ($tempData as $item) {
        if (isset($item['race_stadium_number'])) {
            $existingResults[] = $item;
        } elseif (is_array($item)) {
            // "1":{...} のような形式もバラして追加
            foreach ($item as $r) { $existingResults[] = $r; }
        }
    }
}

// 3. 12レース分取得済みの会場を特定して除外
$completedStadiums = [];
$stadiumCount = [];
foreach ($existingResults as $item) {
    // 構造の深い部分までチェックしてデータを特定
    $r = isset($item['race_stadium_number']) ? $item : (is_array($item) ? reset($item) : null);
    if ($r && !empty($r['payouts']['trifecta'])) {
        $sid = (int)$r['race_stadium_number'];
        $stadiumCount[$sid] = ($stadiumCount[$sid] ?? 0) + 1;
    }
}
foreach ($stadiumCount as $sid => $count) {
    if ($count >= 12) $completedStadiums[] = $sid;
}

$targetStadiums = array_diff($activeStadiums, $completedStadiums);

// 全会場完了なら終了
if (empty($targetStadiums)) {
    exit;
}

// 4. スクレイピングとマージ（合体）処理
$scraperInstance = Scraper::getInstance();

// 既存データを「会場ID_レース番号」をキーにした連想配列に組み替える
$tempMaster = [];
foreach ($existingResults as $item) {
    // ネストされた構造も確実に捉える
    $target = isset($item['race_stadium_number']) ? $item : (is_array($item) ? reset($item) : null);
    
    if ($target && isset($target['race_stadium_number'], $target['race_number'])) {
        // 数値にキャストして一意のキーを作成。これで物理的に重複を防ぐ
        $sId = (int)$target['race_stadium_number'];
        $rNo = (int)$target['race_number'];
        $key = "{$sId}_{$rNo}";
        $tempMaster[$key] = $item; 
    }
}

foreach ($targetStadiums as $id) {
    $stadiumId = sprintf('%02d', $id);
    try {
        $stadiumResults = $scraperInstance->scrapeResults($date, $stadiumId);
        if (empty($stadiumResults)) continue;

        foreach ($stadiumResults as $newRaceData) {
            // 新規データからも中身を取得
            $newTarget = isset($newRaceData['race_number']) ? $newRaceData : (is_array($newRaceData) ? reset($newRaceData) : null);
            
            if ($newTarget && isset($newTarget['race_number'])) {
                $sId = (int)$id;
                $rNo = (int)$newTarget['race_number'];
                $key = "{$sId}_{$rNo}";
                
                // 同じキーがあれば「強制上書き」、なければ追加
                $tempMaster[$key] = $newRaceData;
            }
        }
    } catch (\Exception $e) {
        continue;
    }
}

// 連想配列のキーを破棄して、保存用のリスト形式に戻す
$mergedResults = array_values($tempMaster);

// 5. 保存処理
if (!empty($mergedResults)) {
    $saver = new ResultSaver();
    $saver->save($mergedResults, "docs/{$version}/" . $year . '/' . $ymd . '.json');
    $saver->save($mergedResults, "docs/{$version}/today.json");
}
