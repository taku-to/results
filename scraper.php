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
}
// 江戸川(03)などがAPIにない場合でも、24会場すべてをチェック対象にする（念のため）
$activeStadiums = array_unique(array_merge($activeStadiums, range(1, 24)));

// 2. 既存データをGitHubから取得し、重複を排除して展開
$tempMaster = [];
$remoteUrl = "https://taku-to.github.io/results/v2/{$year}/{$ymd}.json";
$currentRaw = @file_get_contents($remoteUrl);

if ($currentRaw) {
    $currentData = json_decode($currentRaw, true);
    $rawList = $currentData['results'] ?? $currentData;
    
    foreach ($rawList as $entry) {
        // {"1": {...}} のような入れ子を剥いて、中身を取り出す
        $data = null;
        if (isset($entry['race_stadium_number'])) {
            $data = $entry;
        } elseif (is_array($entry)) {
            $inner = reset($entry);
            if (isset($inner['race_stadium_number'])) $data = $inner;
        }
        
        if ($data) {
            // 会場_レース をキーにして格納。これで重複が物理的に消える
            $key = (int)$data['race_stadium_number'] . '_' . (int)$data['race_number'];
            $tempMaster[$key] = $data;
        }
    }
}

// 3. 完了済み判定（三連単の配当が「本当に入っているか」を厳密にチェック）
$completedStadiums = [];
foreach ($activeStadiums as $sid) {
    $finishedCount = 0;
    for ($r = 1; $r <= 12; $r++) {
        $race = $tempMaster["{$sid}_{$r}"] ?? null;
        if (isset($race['payouts']['trifecta']) && count($race['payouts']['trifecta']) > 0) {
            $finishedCount++;
        }
    }
    if ($finishedCount >= 12) $completedStadiums[] = $sid;
}

$targetStadiums = array_diff($activeStadiums, $completedStadiums);
if (empty($targetStadiums)) exit;

// 4. スクレイピング実行
$scraperInstance = Scraper::getInstance();
foreach ($targetStadiums as $id) {
    $stadiumId = sprintf('%02d', $id);
    try {
        $stadiumResults = $scraperInstance->scrapeResults($date, $stadiumId);
        if (empty($stadiumResults)) continue;

        foreach ($stadiumResults as $newEntry) {
            $newData = isset($newEntry['race_number']) ? $newEntry : reset($newEntry);
            if ($newData && isset($newData['race_number'])) {
                $key = (int)$id . '_' . (int)$newData['race_number'];
                // 既存の空データを「確定結果」で上書き
                $tempMaster[$key] = $newData;
            }
        }
    } catch (\Exception $e) { continue; }
}

// 5. 保存用に配列を整理・ソート
$mergedResults = array_values($tempMaster);
usort($mergedResults, function($a, $b) {
    $cmp = (int)$a['race_stadium_number'] <=> (int)$b['race_stadium_number'];
    return ($cmp !== 0) ? $cmp : ((int)$a['race_number'] <=> (int)$b['race_number']);
});

// 6. 保存
if (!empty($mergedResults)) {
    $saver = new ResultSaver();
    // 構造をフラットにするため直接 $mergedResults を渡す
    $saver->save($mergedResults, "docs/{$version}/" . $year . '/' . $ymd . '.json');
    $saver->save($mergedResults, "docs/{$version}/today.json");
}
