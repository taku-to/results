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

// 1. 今日の会場情報を取得
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

// 2. 既存データをGitHubから取得し、マージ用のベースを作る
$tempMaster = [];
$remoteUrl = "https://taku-to.github.io/results/v2/{$year}/{$ymd}.json";
$currentRaw = @file_get_contents($remoteUrl);

if ($currentRaw) {
    $currentData = json_decode($currentRaw, true);
    $rawList = $currentData['results'] ?? $currentData;
    
    foreach ($rawList as $entry) {
        // どんな形式でも中身を取り出す
        $data = isset($entry['race_stadium_number']) ? $entry : (is_array($entry) ? reset($entry) : null);
        if ($data && isset($data['race_stadium_number'], $data['race_number'])) {
            $key = (int)$data['race_stadium_number'] . '_' . (int)$data['race_number'];
            $tempMaster[$key] = $data;
        }
    }
}

// 3. 完了済み判定（三連単の結果が12レース分揃っている会場を除外）
$completedStadiums = [];
foreach ($activeStadiums as $sid) {
    $count = 0;
    for ($r = 1; $r <= 12; $r++) {
        if (isset($tempMaster["{$sid}_{$r}"]["payouts"]["trifecta"])) {
            $count++;
        }
    }
    if ($count >= 12) {
        $completedStadiums[] = $sid;
    }
}

$targetStadiums = array_diff($activeStadiums, $completedStadiums);

// 全会場が12レース完了していれば終了
if (empty($targetStadiums)) exit;

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
                // 会場IDとレース番号をキーにして保存（既存があれば上書き、なければ新規）
                $key = (int)$id . '_' . (int)$newData['race_number'];
                $tempMaster[$key] = $newData;
            }
        }
    } catch (\Exception $e) { continue; }
}

// 5. 最後に会場番号・レース番号順にソート（見た目を整える）
$mergedResults = array_values($tempMaster);
usort($mergedResults, function($a, $b) {
    $ra = isset($a['race_stadium_number']) ? $a : reset($a);
    $rb = isset($b['race_stadium_number']) ? $b : reset($b);
    $cmp = (int)$ra['race_stadium_number'] <=> (int)$rb['race_stadium_number'];
    return ($cmp !== 0) ? $cmp : ((int)$ra['race_number'] <=> (int)$rb['race_number']);
});

// 6. 保存
if (!empty($mergedResults)) {
    $saver = new ResultSaver();
    $saver->save($mergedResults, "docs/{$version}/" . $year . '/' . $ymd . '.json');
    $saver->save($mergedResults, "docs/{$version}/today.json");
}
