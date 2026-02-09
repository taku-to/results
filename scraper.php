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
$results = $scraper->scrape($date); // 開催中の会場を最速で取得

if (empty($results)) exit("No results.\n");

$stadiumNames = [1=>'桐生',2=>'戸田',3=>'江戸川',4=>'平和島',5=>'多摩川',6=>'浜名湖',7=>'蒲郡',8=>'常滑',9=>'津',10=>'三国',11=>'びわこ',12=>'住之江',13=>'尼崎',14=>'鳴門',15=>'丸亀',16=>'児島',17=>'宮島',18=>'徳山',19=>'下関',20=>'若松',21=>'芦屋',22=>'福岡',23=>'唐津',24=>'大村'];

$formatted = [];
foreach ($results as $r) {
    $sId = (int)($r['race_stadium_number'] ?? 0);
    $rNum = (int)($r['race_number'] ?? 0);
    $tri = $r['payouts']['trifecta'][0] ?? null;
    if ($sId > 0 && $rNum > 0 && $tri) {
        $formatted[] = [
            "venue"  => $stadiumNames[$sId] ?? (string)$sId,
            "race"   => $rNum,
            "result" => str_replace(' ', '-', $tri['combination']),
            "payout" => (int)$tri['payout']
        ];
    }
}

$saver = new ResultSaver();
$saver->save($formatted, "docs/{$version}/" . $date->format('Y/Ymd') . ".json");
$saver->save($formatted, "docs/{$version}/today.json");
