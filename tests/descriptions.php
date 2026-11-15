<?php
require __DIR__.'/../app/Generator.php';
function expectDescription($ok,$label){if(!$ok)throw new Exception($label);echo "PASS $label\n";}
$source=file_get_contents(__DIR__.'/../storage/template.html');
$out=EventGenerator::metadata($source,'NEW EVENT TITLE',null);
expectDescription(str_contains($out,'<title>\r\n')||str_contains($out,'NEW EVENT TITLE'),'template mode changes titles');
preg_match_all('~\bcontent="([^"]*BLOCKBUSTER TENNIS[^"]*)"~s',$source,$before);
expectDescription(count($before[0])>0,'description preservation fixture has source descriptions');
foreach($before[0] as $i=>$tag)expectDescription(str_contains($out,$tag),'original template description preserved '.$i);
$words=implode(' ',array_fill(0,100,'event'));
expectDescription(EventGenerator::descriptionWords($words)===100,'100-word boundary accepted');
try{EventGenerator::validateDescription($words.' extra');throw new Exception('101 words accepted');}catch(InvalidArgumentException $e){expectDescription(true,'101 words rejected');}
expectDescription(EventGenerator::validateDescription('Follow the Vienna Open on October 31, 2026 for tennis event information.')==='Follow the Vienna Open on October 31, 2026 for tennis event information.','plain event description validated');
echo "ALL DESCRIPTION TESTS PASSED\n";
