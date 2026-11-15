<?php
require __DIR__.'/../app/Generator.php';
function randomizerCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$varied=false;
foreach([[9,18],[9,100],[9,7],[3,7],[2,33],[1,7],[0,4]] as [$n,$total]){
 $domains=$n?array_map(fn($i)=>'https://domain'.$i.'.example/player/',range(1,$n)):[];
 $dir=sys_get_temp_dir().'/eg-random-domains-'.bin2hex(random_bytes(8));mkdir($dir);
 try{
  $rows=[];for($i=0;$i<$total;$i++)$rows[]=['id'=>'video-test-'.$i,'title'=>'Test title '.$i];
  $descriptions=array_fill(0,$total,null);
  EventGenerator::package($dir,'<title>Original</title>',$rows,$descriptions,'BASE64+==',$domains);
  $original=file_get_contents($dir.'/urls.txt');$expected=[];foreach($rows as $i=>$row)$expected[]=($n?$domains[$i%$n]:'').'?&xml=data:video/mp4;base64,BASE64+==&id='.$row['id'];$orderedExpected=$expected;sort($expected);
  $zip=new ZipArchive;$zip->open($dir.'/html.zip');$html=[];foreach($rows as $row)$html[$row['id'].'.html']=$zip->getFromName($row['id'].'.html');$zip->close();
  $baselineGroups=$n>1?array_map(fn($round)=>implode("\n",$round),array_chunk($orderedExpected,$n)):[$original];
  for($attempt=0;$attempt<12;$attempt++){
   EventGenerator::package($dir,'<title>Original</title>',$rows,$descriptions,'BASE64+==',$domains,[],true);
   $raw=file_get_contents($dir.'/urls.txt');$urls=file($dir.'/urls.txt',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);sort($urls);
   if($expected!==$urls)throw new RuntimeException('Randomized batches must use one URL per domain, without losing or changing IDs');
   if($n>1){
    $groups=explode("\n\n\n\n",rtrim($raw,"\n"));
    if(count($groups)!==count($baselineGroups))throw new RuntimeException('Group separators changed');
    foreach($groups as $round=>$group){$actual=explode("\n",$group);$roundExpected=explode("\n",$baselineGroups[$round]);sort($actual);sort($roundExpected);if($actual!==$roundExpected)throw new RuntimeException('Each round must contain one complete URL for each domain');$hosts=array_map(fn($url)=>parse_url($url,PHP_URL_HOST),$actual);if(count(array_unique($hosts))!==count($hosts))throw new RuntimeException('A domain repeats within a round');}
    if($raw!==$original)$varied=true;
   }elseif($raw!==$original)throw new RuntimeException('Single/empty domain output changed');
   $zip=new ZipArchive;$zip->open($dir.'/html.zip');foreach($html as $name=>$content)if($zip->getFromName($name)!==$content)throw new RuntimeException('HTML changed during URL randomization');$zip->close();
  }
  randomizerCheck(true,"$n domains / $total outputs: intact URLs, HTML and one URL per domain per round, including partial rounds");
  EventGenerator::package($dir,'<title>Original</title>',$rows,$descriptions,'BASE64+==',$domains,[],false);
  randomizerCheck(file_get_contents($dir.'/urls.txt')===$original,'disabled randomizer retains serial ordering');
 }finally{foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
}
randomizerCheck($varied,'repeated randomization produces different URL orders within rounds');
echo "ALL DOMAIN RANDOMIZER TESTS PASSED\n";
