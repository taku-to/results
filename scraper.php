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

// 1. 今日の会場情報を取得（APIに頼らず全24会場をチェック対象にする）
$activeStadiums = range(1, 24); 

// 2. 既存データをGitHubから取得し、重複を排除して展開
$tempMaster = [];
$remoteUrl = "https://taku-to.github.io/results/v2/{$year}/{$ymd}.json";
$currentRaw = @file_get_contents($remoteUrl);

if ($currentRaw) {
    $currentData = json_decode($currentRaw, true);
    $rawList = $currentData['results'] ?? $currentData;
    
    foreach ($rawList as $entry) {
        $data = isset($entry['race_stadium_number']) ? $entry : (is_array($entry) ? reset($entry) : null);
        if ($data) {
            // 文字列・数値のゆらぎを排除するため、(int)で統一してキーを作成
            $key = (int)$data['race_stadium_number'] . '_' . (int)$data['race_number'];
            $tempMaster[$key] = $data;
        }
    }
}

// 3. 完了済み判定
$completedStadiums = [];
foreach ($activeStadiums as $sid) {
    $finishedCount = 0;
    for ($r = 1; $r <= 12; $r++) {
        $race = $tempMaster["{$sid}_{$r}"] ?? null;
        // 配当が入っているものだけを「完了」とみなす
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
                // ここで上書き保存することで重複を防止
                $tempMaster[$key] = $newData;
            }
        }
    } catch (\Exception $e) { continue; }
}

// 5. 保存用に配列を整理・ソート
$mergedResults = array_values($tempMaster);
usort($mergedResults, function($a, $b) {
    if ((int)$a['race_stadium_number'] === (int)$b['race_stadium_number']) {
        return (int)$a['race_number'] <=> (int)$b['race_number'];
    }
    return (int)$a['race_stadium_number'] <=> (int)$b['race_stadium_number'];
});

// 6. 保存
if (!empty($mergedResults)) {
    $saver = new ResultSaver();
    // 常にフラットな配列構造で保存する
    $saver->save($mergedResults, "docs/{$version}/" . $year . '/' . $ymd . '.json');
    $saver->save($mergedResults, "docs/{$version}/today.json");
}
