<?php
require __DIR__.'/../app/Generator.php';
function expectRandom($ok,$label){if(!$ok)throw new Exception($label);echo "PASS $label\n";}
$rows=EventGenerator::rows('Hornets vs Wizards','Hornets vs Wizards',EventGenerator::date('2026-10-31'),100);
$ids=array_column($rows,'id');
expectRandom(count(array_unique($ids))===100,'100 unique random filename IDs');
expectRandom(count(array_filter($ids,fn($id)=>preg_match('/-ustv\d+$/',$id)))<20,'default output no longer uses one serial suffix family');
$suffixes=array_map(fn($id)=>substr($id,strrpos($id,'-')+1),$ids);
expectRandom(count(array_filter($suffixes,fn($id)=>preg_match('/^[a-z]{3,8}$/',$id)))>=50,'random letter suffixes included');
expectRandom(count(array_filter($suffixes,fn($id)=>preg_match('/^(?:liv|onlinn|us|ustv|online|tv|watch|stream|live|hd|onair|cast)\d*$/',$id)))>=30,'mixed streaming suffixes included');
$again=EventGenerator::ids([],100,'Hornets vs Wizards');expectRandom($again!==$ids,'new batches use fresh random suffixes');
foreach([10,20,30,40,50,60,70,80,90,100] as $n){$set=EventGenerator::ids(['seed-a_ustv1','seed-b_ustv1','seed-c_ustv1','seed-d_ustv1','seed-e_ustv1'],$n,'Event');expectRandom(count($set)===$n&&count(array_unique($set))===$n,'five seed count and uniqueness '.$n);foreach(['a','b','c','d','e'] as $v)expectRandom(count(array_filter($set,fn($id)=>str_starts_with($id,'video-event-seed-'.$v.'-')))===$n/5,'balanced seed '.$v.' count '.$n);}
$manual=EventGenerator::ids(['watch-livv-on-tv-uk2','livve-onlin-tv-free3','watch-free-tv324','online-watch-liv-uktv2'],100,'PSG vs Barcelona');
foreach($manual as $id)expectRandom(str_starts_with($id,'video-psg-vs-barcelona-')&&!str_contains($id,'_'),'manual seed contains event and dash separators');
$existing=EventGenerator::ids(['video-psg-vs-barcelona-live'],1,'PSG vs Barcelona')[0];expectRandom(substr_count($existing,'psg-vs-barcelona')===1,'existing event slug not duplicated');
$ordered=EventGenerator::ids(['watch-livv-on-tv-uk2'],1,'PSG vs Barcelona')[0];expectRandom(str_starts_with($ordered,'video-psg-vs-barcelona-watch-livv-on-tv-uk2-'),'video and event name precede the supplied seed');
echo "ALL RANDOM ID TESTS PASSED\n";
