<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use BOA\Results\ResultScraper;
use BOA\Results\ResultSaver;
use BOA\Results\ScraperAdapter;
use BVP\Scraper\Scraper;
use Carbon\CarbonImmutable as Carbon;

// バージョン設定
$version = $argv[1] ?? 'v2';
$date = Carbon::today('Asia/Tokyo');

// 1. オリジナルの高速スクレイピング・ロジックを使用
$scraperInstance = Scraper::getInstance();
$scraperAdapter = new ScraperAdapter($scraperInstance);
$scraper = new ResultScraper($scraperAdapter);

// 開催中の会場だけを自動判別して一括取得（これが最速の理由です）
$results = $scraper->scrape($date);

if (empty($results)) {
    exit("No results found.\n");
}

// 2. race_api.php 用の軽量データ構造に変換
$stadiumNames = [
    1=>'桐生',2=>'戸田',3=>'江戸川',4=>'平和島',5=>'多摩川',6=>'浜名湖',
    7=>'蒲郡',8=>'常滑',9=>'津',10=>'三国',11=>'びわこ',12=>'住之江',
    13=>'尼崎',14=>'鳴門',15=>'丸亀',16=>'児島',17=>'宮島',18=>'徳山',
    19=>'下関',20=>'若松',21=>'芦屋',22=>'福岡',23=>'唐津',24=>'大村'
];

$formattedResults = [];
foreach ($results as $r) {
    $sId = (int)($r['race_stadium_number'] ?? 0);
    $rNum = (int)($r['race_number'] ?? 0);
    $trifecta = $r['payouts']['trifecta'][0] ?? null;

    // 三連単の結果があるレースのみを抽出
    if ($sId > 0 && $rNum > 0 && $trifecta) {
        $formattedResults[] = [
            "venue"  => $stadiumNames[$sId] ?? (string)$sId,
            "race"   => $rNum,
            "result" => str_replace(' ', '-', $trifecta['combination']), // "3 1 5" -> "3-1-5"
            "payout" => (int)$trifecta['payout']
