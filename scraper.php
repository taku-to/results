<?php
declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
use BOA\Results\ResultScraper;
use BOA\Results\ResultSaver;
use BOA\Results\ScraperAdapter;
use BVP\Scraper\Scraper;
use Carbon\CarbonImmutable as Carbon;

$version = $argv[1] ?? 'v2';
$date = Carbon::today('Asia/Tokyo');

$scraperInstance = Scraper::getInstance();
$scraperAdapter = new ScraperAdapter($scraperInstance);
$scraper = new ResultScraper($scraperAdapter);
$results = $scraper->scrape($date);

// データが空でも、既存データを守るためにここでは exit せず、マージ処理へ進む
// if (empty($results)) exit("No results found.\n"); 

$stNames = [1=>'桐生',2=>'戸田',3=>'江戸川',4=>'平和島',5=>'多摩川',6=>'浜名湖',7=>'蒲郡',8=>'常滑',9=>'津',10=>'三国',11=>'びわこ',12=>'住之江',13=>'尼崎',14=>'鳴門',15=>'丸亀',16=>'児島',17=>'宮島',18=>'徳山',19=>'下関',20=>'若松',21=>'芦屋',22=>'福岡',23=>'唐津',24=>'大村'];

// --- 1. 既存の保存済みデータを読み込む ---
$savePath = "docs/{$version}/" . $date->format('Y/Ymd') . ".json";
$existingRaces = [];

if (file_exists($savePath)) {
    $currentJson = json_decode(file_get_contents($savePath), true);
    $existingRaces = $currentJson['results'] ?? [];
}

// 既存データを「会場_レース番号」をキーとした連想配列に整理
$mergedMap = [];
foreach ($existingRaces as $r) {
    $key = $r['venue'] . '_' . $r['race'];
    $mergedMap[$key] = $r;
}

// --- 2. 今回スクレイピングしたデータを合体（マージ）させる ---
foreach ($results as $r) {
    $sId = (int)($r['race_stadium_number'] ?? 0);
    $rNum = (int)($r['race_number'] ?? 0);
    $tri = $r['payouts']['trifecta'][0] ?? null;

    if ($sId > 0 && $rNum > 0 && $tri) {
        $venueName = $stNames[$sId] ?? (string)$sId;
        $key = $venueName . '_' . $rNum;

        // 新しく取れたデータで上書き（または新規追加）
        $mergedMap[$key] = [
            "venue"  => $venueName,
            "race"   => $rNum,
            "result" => str_replace(' ', '-', $tri['combination']),
            "payout" => (int)$tri['payout']
        ];
    }
}

// 最終的な保存用配列を作成
$finalFormatted = array_values($mergedMap);

if (empty($finalFormatted)) {
    exit("No data to save.\n");
}

// --- 3. 保存処理 ---
// ResultSaverを使わず直接 file_put_contents するか、
// $saver->save に渡してディレクトリ作成機能などを利用する
$saver = new ResultSaver();

// フォルダがなければ作成
$dir = dirname($savePath);
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

// 合体したデータで保存
file_put_contents($savePath, json_encode(['results' => $finalFormatted], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
file_put_contents("docs/{$version}/today.json", json_encode(['results' => $finalFormatted], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "Successfully merged and saved " . count($finalFormatted) . " races.\n";
