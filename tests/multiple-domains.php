<?php
require __DIR__.'/../app/Generator.php';
function domainCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$list=EventGenerator::urlDomains("https://one.example/path/\r\n\r\nhttps://two.example/\r\nhttps://one.example/path/\n");domainCheck($list===['https://one.example/path/','https://two.example/'],'blank/duplicate lines skipped; paths and trailing slashes preserved');
$bad=false;try{EventGenerator::urlDomains("https://one.example/\njavascript://bad");}catch(InvalidArgumentException){$bad=true;}domainCheck($bad,'invalid domain rejected');
foreach([[2,10],[3,15],[2,17],[3,33],[1,7],[0,4]] as [$n,$total]){
 $domains=array_map(fn($i)=>'https://domain'.$i.'.example/path/',range(1,max(1,$n)));if(!$n)$domains=[];
 $dir=sys_get_temp_dir().'/eg-multi-'.bin2hex(random_bytes(8));mkdir($dir);
 try{
  $rows=[];for($i=0;$i<$total;$i++)$rows[]=['id'=>'video-event-test-'.$i,'title'=>'Title '.$i];
  EventGenerator::package($dir,'<title>Old</title>',$rows,array_fill(0,$total,null),'BASE64',$domains);
  $urls=file($dir.'/urls.txt',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);domainCheck(count($urls)===$total,"exact requested total $total for $n domains");
  foreach($urls as $i=>$url){$prefix=$n?$domains[intdiv($i,5)%$n]:'';if($url!==$prefix.'?&xml=data:video/mp4;base64,BASE64&id='.$rows[$i]['id'])throw new RuntimeException('Domain rotation mismatch at '.$i);}
  domainCheck(true,"five-per-domain rotation correct for $n domains and $total outputs");
  $raw=file_get_contents($dir.'/urls.txt');
  if($n>1){$groups=explode("\n\n\n\n",rtrim($raw,"\n"));domainCheck(count($groups)===(int)ceil($total/5),'three blank lines between domain groups');foreach($groups as $group)domainCheck(!str_contains($group,"\n\n"),'no blank lines within a domain group');}
  else domainCheck(!str_contains($raw,"\n\n"),'single or empty domain has no group separators');
  $zip=new ZipArchive;$zip->open($dir.'/html.zip');domainCheck($zip->numFiles===$total,'HTML count equals URL count');$zip->close();
 }finally{foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
}
echo "ALL MULTIPLE DOMAIN TESTS PASSED\n";
