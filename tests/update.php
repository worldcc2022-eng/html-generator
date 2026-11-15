<?php
require __DIR__.'/../app/Generator.php';
function expect($ok,$label){if(!$ok)throw new Exception($label);echo "PASS $label\n";}
expect(EventGenerator::date('31 October','auto','Vienna Open 2026')->format('Y-m-d')==='2026-10-31','yearless textual date uses event year');
expect(EventGenerator::date('31/10','auto','Vienna Open 2028')->format('Y-m-d')==='2028-10-31','yearless numeric date uses event year');
expect(EventGenerator::shortName('Vienna Open 2026')==='Vienna Open','tournament short name');
$ids=EventGenerator::ids(['video-tennis_ustv1','video-tennis_btv1','video-tennis_ctv1','video-tennis_dtv1','video-tennis_etv1'],50,'Vienna Open 2026');
expect(count($ids)===50&&count(array_unique($ids))===50,'five ID fields generate fifty unique IDs total');
expect(count(array_filter($ids,fn($id)=>str_starts_with($id,'video-vienna-open-2026-tennis-')))===50,'five ID variations use random suffixes');
expect(str_starts_with(EventGenerator::ids(['hello_2026'],10,'Event')[0],'video-event-hello-2026-'),'year suffix not incremented as counter');
$ids=EventGenerator::ids(['same1','same1','','',''],10,'Event');expect(count(array_unique($ids))===10,'overlapping ID seeds remain unique');
try{EventGenerator::ids(['../bad'],10,'Event');throw new Exception('invalid ID accepted');}catch(InvalidArgumentException $e){expect(true,'unsafe ID rejected');}
$source=file_get_contents(__DIR__.'/../storage/template.html');
$url='https://surprisestream.com/event?name=Vienna&year=2026';
$updated=EventGenerator::eventUrl($source,$url);
$original='"https://surprisestream.com/go/singrafast/nba/nba-p71e16483ac09"';
$replacement=json_encode($url,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
expect(str_replace($original,$replacement,$source)===$updated,'only bypass redirect URL literal changes in source');
expect(str_contains($updated,"navigator.userAgent.includes('Googlebot')"),'existing Googlebot condition preserved');
try{EventGenerator::eventUrl($source,'javascript:alert(1)');throw new Exception('unsafe URL accepted');}catch(InvalidArgumentException $e){expect(true,'unsafe Event URL rejected');}
try{EventGenerator::eventUrl('<html><title>Test</title></html>',$url);throw new Exception('missing redirect silently accepted');}catch(InvalidArgumentException $e){expect(true,'missing redirect explains template mismatch');}
echo "ALL UPDATE TESTS PASSED\n";
