<?php
require __DIR__.'/../app/Generator.php';require __DIR__.'/../app/TitleBank.php';
$title='[Watch!!] {event_name} 𝐋𝐈𝐕𝐄 Ｓｔｒｅａｍｓ ＯＮ ＴＶ {date}';
$expected='[Watch!!] NYC Marathon 2026 𝐋𝐈𝐕𝐄 Ｓｔｒｅａｍｓ ＯＮ ＴＶ 31 October';
$rendered=TitleBank::render($title,'NYC Marathon 2026',new DateTimeImmutable('2026-10-31'));
if($rendered!==$expected)throw new RuntimeException('Title bytes changed.');
$html=EventGenerator::metadata('<title>Old</title><meta property="og:title" content="Old">',$rendered,null);
if(!str_contains($html,$expected)||str_contains($html,'&#'))throw new RuntimeException('Raw UTF-8 title was converted.');
$literal='[LIVE] {event_name} 怘恒';if(TitleBank::render($literal,'Event',new DateTimeImmutable('2026-10-31'))!=='[LIVE] Event 怘恒')throw new RuntimeException('Unrequested repair occurred.');
echo "PASS raw UTF-8 titles remain literal in HTML source\nPASS no automatic text repair\nALL TEXT ENCODING TESTS PASSED\n";
