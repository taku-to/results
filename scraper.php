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
    
    // 1. 今日開催されている会場IDを取得
    $year = $date->format('Y');
    $ymd  = $date->format('Ymd');
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

    // 2. すでに12レース分取得済みの会場を特定
    $completedStadiums = [];
    $currentFile = "docs/{$version}/" . $year . '/' . $ymd . '.json';
    
    // 保存済みの既存データを一旦読み込んでおく
    $existingResults = [];
    if (file_exists($currentFile)) {
        $currentData = json_decode(file_get_contents($currentFile), true);
        $existingResults = $currentData['results'] ?? $currentData;

        $stadiumCount = [];
        foreach ($existingResults as $item) {
            $r = isset($item['race_stadium_number']) ? $item : (is_array($item) ? reset($item) : null);
            if ($r && !empty($r['payouts']['trifecta'])) {
                $sid = (int)$r['race_stadium_number'];
                $stadiumCount[$sid] = ($stadiumCount[$sid] ?? 0) + 1;
            }
        }
        foreach ($stadiumCount as $sid => $count) {
            if ($count >= 12) $completedStadiums[] = $sid;
        }
    }

    // 3. 完了済みを除外した会場をターゲットにする
    $targetStadiums = array_diff($activeStadiums, $completedStadiums);

    if (empty($targetStadiums)) {
        exit;
    }

    // ★重要：$results にはまず「既存のデータ」を入れておく
    $results = $existingResults;

    // 4. 未完了の会場だけをループ
    foreach ($targetStadiums as $id) { // ここを $targetStadiums に修正
        $stadiumId = sprintf('%02d', $id);
        try {
            $stadiumResults = $scraperInstance->scrapeResults($date, $stadiumId);
            if (!empty($stadiumResults)) {
                // 今回取得した会場の「古いデータ」を念のため取り除いてから合体
                $results = array_filter($results, function($v) use ($id) {
                    $r = isset($v['race_stadium_number']) ? $v : (is_array($v) ? reset($v) : null);
                    return ((int)($r['race_stadium_number'] ?? 0) !== $id);
                });
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
