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

// 1. 今日の会場番号を取得（APIからはIDだけを利用）
$programUrl = "https://boatraceopenapi.github.io/programs/v2/{$year}/{$ymd}.json";
$programData = json_decode(@file_get_contents($programUrl), true);
$activeStadiums = [];
if (!empty($programData['programs'])) {
    foreach ($programData['programs'] as $p) {
        $activeStadiums[] = (int)$p['race_stadium_number'];
    }
}
if (empty($activeStadiums)) $activeStadiums = range(1, 24);

// 2. 既存の「確定済み」データをURLから取得
$tempMaster = [];
$remoteUrl = "https://taku-to.github.io/results/v2/{$year}/{$ymd}.json";
$currentRaw = @file_get_contents($remoteUrl);

if ($currentRaw) {
    $currentData = json_decode($currentRaw, true);
    $rawList = $currentData['results'] ?? $currentData;
    
    foreach ($rawList as $entry) {
        // どんな階層構造で保存されていても、中身だけを抽出する
        $data = isset($entry['race_number']) ? $entry : (is_array($entry) ? reset($entry) : null);
        
        if ($data && isset($data['race_stadium_number'], $data['race_number'])) {
            $key = (int)$data['race_stadium_number'] . '_' . (int)$data['race_number'];
            $tempMaster[$key] = $data; // フラットな形式で保存
        }
    }
}

// 3. 完了済み判定（三連単の配当[payout]が本当に入っているかチェック）
$completedStadiums = [];
foreach ($activeStadiums as $sid) {
    $foundRaces = 0;
    for ($r = 1; $r <= 12; $r++) {
        $raceData = $tempMaster["{$sid}_{$r}"] ?? null;
        // trifectaの中身が空（[]）でないことを厳密に確認
        if (!empty($raceData['payouts']['trifecta'])) {
            $foundRaces++;
        }
    }
    if ($foundRaces >= 12) {
        $completedStadiums[] = $sid;
    }
}

$targetStadiums = array_diff($activeStadiums, $completedStadiums);
if (empty($targetStadiums)) exit;

// 4. スクレイピング（確定結果を取得して上書き）
$scraperInstance = Scraper::getInstance();
foreach ($targetStadiums as $id) {
    $stadiumId = sprintf('%02d', $id);
    try {
        // ここで公式サイトから最新の結果（確定済み）を取得
        $stadiumResults = $scraperInstance->scrapeResults($date, $stadiumId);
        if (empty($stadiumResults)) continue;

        foreach ($stadiumResults as $newEntry) {
            $newData = isset($newEntry['race_number']) ? $newEntry : (is_array($newEntry) ? reset($newEntry) : null);
            if ($newData && isset($newData['race_number'])) {
                $key = (int)$id . '_' . (int)$newData['race_number'];
                // 既存の「空データ」を「確定結果データ」で強制上書き
                $tempMaster[$key] = $newData;
            }
        }
    } catch (\Exception $e) { continue; }
}

// 5. 並べ替え
$mergedResults = array_values($tempMaster);
usort($mergedResults, function($a, $b) {
    $cmp = (int)$a['race_stadium_number'] <=> (int)$b['race_stadium_number'];
    return ($cmp !== 0) ? $cmp : ((int)$a['race_number'] <=> (int)$b['race_number']);
});

// 6. 保存
if (!empty($mergedResults)) {
    $saver = new ResultSaver();
    $saver->save($mergedResults, "docs/{$version}/" . $year . '/' . $ymd . '.json');
    $saver->save($mergedResults, "docs/{$version}/today.json");
}
