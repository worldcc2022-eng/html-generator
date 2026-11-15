<?php
require __DIR__.'/../app/Generator.php';
function manualCheck($ok,$label){if(!$ok)throw new Exception($label);echo "PASS $label\n";}
foreach([1,7,13,35,125,250,1000] as $count){
 $rows=EventGenerator::rows('Hornets vs Wizards','Hornets vs Wizards',EventGenerator::date('2026-10-31'),$count);
 manualCheck(count($rows)===$count&&count(array_unique(array_column($rows,'id')))===$count&&count(array_unique(array_column($rows,'title')))===$count,'exact manual count and unique titles/IDs '.$count);
 $ids=EventGenerator::ids(['one','two','three','four','five'],$count,'Event');$sizes=[];foreach(['one','two','three','four','five'] as $stem)$sizes[]=count(array_filter($ids,fn($id)=>str_starts_with($id,$stem.'_')));
 manualCheck(max($sizes)-min($sizes)<=1,'balanced arbitrary seed allocation '.$count);
}
echo "ALL MANUAL COUNT TESTS PASSED\n";
