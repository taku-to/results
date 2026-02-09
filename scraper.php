<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use BVP\Scraper\Scraper;
use Carbon\CarbonImmutable as Carbon;
use BOA\Results\ResultSaver;

// コマンドライン引数
$version = $argv[1] ?? 'v2';
$date = Carbon::today('Asia/Tokyo');
$year = $date->format('Y');
$ymd  = $date->format('Ymd');

// 会場名マップ（ID 1〜24）
$stadiumMap = [
    1=>"桐生", 2=>"戸田", 3=>"江戸川", 4=>"平和島", 5=>"多摩川", 6=>"浜名湖", 
    7=>"蒲郡", 8=>"常滑", 9=>"津", 10=>"三国", 11=>"びわこ", 12=>"住之江", 
    13=>"尼崎", 14=>"鳴門", 15=>"丸亀", 16=>"児島", 17=>"宮島", 18=>"徳山", 
    19=>"下関", 20=>"若松", 21=>"芦屋", 22=>"福岡", 23=>"唐津", 24=>"大村"
];

// 1. 既存のJSONデータを読み込む（重複防止のため）
$resultsMaster = [];
$remoteUrl = "https://taku-to.github.io/results/v2/{$year}/{$ymd}.json";
$currentRaw = @file_get_contents($remoteUrl);
if ($currentRaw) {
    $currentData = json_decode($currentRaw, true);
    $list = $currentData['results'] ?? (is_array($currentData) ? $currentData : []);
    foreach ($list as $item) {
        if (isset($item['venue'], $item['race'])) {
            $key = $item['venue'] . '_' . $item['race'];
            $resultsMaster[$key] = $item;
        }
    }
}

// 2. スクレイピング実行
$scraperInstance = Scraper::getInstance();

foreach ($stadiumMap as $id => $name) {
    // すでにその会場の12Rの結果が入っていれば、その会場は飛ばす（時短）
    if (isset($resultsMaster["{$name}_12"])) continue;

    $stadiumId = sprintf('%02d', $id);
    try {
        // 会場単位で取得
        $scrapedData = $scraperInstance->scrapeResults($date, $stadiumId);
        if (empty($scrapedData)) continue;

        foreach ($scrapedData as $r) {
            $raceNum = (int)($r['race_number'] ?? 0);
            if ($raceNum === 0) continue;

            // 三連単（trifecta）の情報を抽出
            $trifecta = $r['payouts']['trifecta'][0] ?? null;
            if (!$trifecta) continue; // 結果がまだないレースは保存しない

            $key = $name . '_' . $raceNum;
            // race_api.php で求めている最小構成に変換
            $resultsMaster[$key] = [
                "venue"  => $name,
                "race"   => $raceNum,
                "result" => str_replace(' ', '-', $trifecta['combination']), // "3 1 5" -> "3-1-5"
                "payout" => (int)$trifecta['payout']
            ];
        }
    } catch (\Exception $e) {
        // エラーが起きても止まらず次の会場へ
        continue;
    }
}

// 3. データを会場順・レース順に並べ替え
$finalResults = array_values($resultsMaster);
usort($finalResults, function($a, $b) use ($stadiumMap) {
    $idA = array_search($a['venue'], $stadiumMap);
    $idB = array_search($b['venue'], $stadiumMap);
    if ($idA === $idB) return $a['race'] <=> $b['race'];
    return $idA <=> $idB;
});

// 4. 保存
if (!empty($finalResults)) {
    $saver = new ResultSaver();
    // 日付別ファイルと today.json の両方を更新
    $saver->save($finalResults, "docs/{$version}/" . $year . '/' . $ymd . '.json');
    $saver->save($finalResults, "docs/{$version}/today.json");
}
