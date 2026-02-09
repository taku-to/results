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
            foreach ($item as $r) { $existingResults[] = $r; }
        }
    }
}

// 3. 12レース分取得済みの会場を特定して除外
$completedStadiums = [];
$stadiumCount = [];
foreach ($existingResults as $item) {
    // 3連単(trifecta)があるレースをカウント
    if (!empty($item['payouts']['trifecta'])) {
        $sid = (int)$item['race_stadium_number'];
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
$mergedResults = $existingResults; // 既存データをベースにする

foreach ($targetStadiums as $id) {
    $stadiumId = sprintf('%02d', $id);
    try {
        $stadiumResults = $scraperInstance->scrapeResults($date, $stadiumId);
        if (empty($stadiumResults)) continue;

        foreach ($stadiumResults as $newRaceData) {
            // $newRaceData の中身（連想配列なら最初の要素、直接ならそのまま）
            $newRace = isset($newRaceData['race_number']) ? $newRaceData : reset($newRaceData);
            if (!$newRace) continue;

            $newRn = (int)$newRace['race_number'];
            $foundIdx = -1;

            // 既存データ内に同じ「会場ID・レース番号」があるか探す
            foreach ($mergedResults as $idx => $oldRaceData) {
                $oldRace = isset($oldRaceData['race_number']) ? $oldRaceData : reset($oldRaceData);
                if ((int)$oldRace['race_stadium_number'] === $id && (int)$oldRace['race_number'] === $newRn) {
                    $foundIdx = $idx;
                    break;
                }
            }

            if ($foundIdx !== -1) {
                // すでにあれば「最新」で上書き
                $mergedResults[$foundIdx] = $newRaceData;
            } else {
                // なければ新規追加
                $mergedResults[] = $newRaceData;
            }
        }
    } catch (\Exception $e) {
        continue;
    }
}

// 5. 保存処理
if (!empty($mergedResults)) {
    $saver = new ResultSaver();
    // 日付別ディレクトリと today.json の両方を更新
    $saver->save($mergedResults, "docs/{$version}/" . $year . '/' . $ymd . '.json');
    $saver->save($mergedResults, "docs/{$version}/today.json");
}
