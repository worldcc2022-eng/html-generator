<?php
require dirname(__DIR__).'/app/Generator.php';
function check($ok,$message){if(!$ok)throw new Exception($message);echo "PASS $message\n";}
function rejects($fn,$label){try{$fn();}catch(InvalidArgumentException $e){check(true,$label);return;}throw new Exception($label);}
check(EventGenerator::date('31 October 2026')->format('Y-m-d')==='2026-10-31','text date');
check(EventGenerator::date('2026-10-31')->format('Y-m-d')==='2026-10-31','ISO date');
check(EventGenerator::date('10/31/2026')->format('Y-m-d')==='2026-10-31','unambiguous US date');
rejects(fn()=>EventGenerator::date('03/04/2026'),'ambiguous date rejected');
rejects(fn()=>EventGenerator::date('31 February 2026'),'impossible date rejected');
check(EventGenerator::date('03/04/2026','dmy')->format('Y-m-d')==='2026-04-03','explicit numeric order');
$base=EventGenerator::xmlBase64('spor14.github.io/mcq/cast.xml');
check($base==='PGtycGFubz48aW5jbHVkZSB1cmw9Ii9cL3Nwb3IxNC5naXRodWIuaW8vbWNxL2Nhc3QueG1sIi8+PC9rcnBhbm8+','exact XML encoding');
rejects(fn()=>EventGenerator::xmlBase64('example.com/"/><bad>'),'unsafe XML path rejected');
foreach(range(1,10) as $step){$n=$step*10;$rows=EventGenerator::rows('Washington Wizards vs Charlotte Hornets','Wizards vs Hornets',EventGenerator::date('2026-10-31'),$n,'basketball','video-washington-wizards-vs-charlotte-hornets-live-nba-today');
 check(count($rows)===$n&&count(array_unique(array_column($rows,'title')))===$n,"$n unique titles");
 check(count(array_filter($rows,fn($r)=>$r['full']))===intval(round($n*.3)),"$n full name proportion");
 check(count(array_filter($rows,fn($r)=>$r['dated']))===intval(round($n*.3)),"$n date proportion");
 check(str_starts_with($rows[0]['id'],'video-washington-wizards-vs-charlotte-hornets-live-nba-today-')&&count(array_unique(array_column($rows,'id')))===$n,"$n filename ID");}
$source='<!doctype html><head><title>Old</title><meta property="og:title" content="Old"><meta content=\'Old\' name=\'twitter:title\'><meta name="description" content="old desc"><meta content="old desc" og:description"/><script>let a="<title>KEEP</title><meta name=\'description\' content=\'KEEP\'>";</script></head><body><h1>KEEP</h1><!-- <title>KEEP</title> --></body>';
$expected=str_replace(['<title>Old</title>','content="Old"',"content='Old'",'content="old desc"'],['<title>A &amp; B</title>','content="A &amp; B"',"content='A &amp; B'",'content="New &quot;description&quot;"'],$source);
check(EventGenerator::metadata($source,'A & B','New "description"')===$expected,'only metadata bytes change; scripts comments body unchanged');
check(EventGenerator::shortName('Los Angeles Lakers vs. Golden State Warriors')==='Lakers vs Warriors','team aliases');
check(EventGenerator::shortName("Emanuel Navarrete vs. O’Shaquie Foster")==='Navarrete vs Foster','player surnames');
$dir=sys_get_temp_dir().'/eg-test-'.bin2hex(random_bytes(5));mkdir($dir);
EventGenerator::package($dir,$source,$rows,array_map(fn($r)=>'Description for '.$r['id'],$rows),$base);
$zip=new ZipArchive;$zip->open($dir.'/html.zip');$urls=file($dir.'/urls.txt',FILE_IGNORE_NEW_LINES);
check($zip->numFiles===100&&count($urls)===100,'ZIP and TXT exact counts');
foreach($rows as $i=>$row){check($zip->locateName($row['id'].'.html')!==false&&!str_contains($urls[$i],'.html')&&str_ends_with($urls[$i],'&id='.$row['id']),'paired file and URL '.$i);}
$zip->close();unlink($dir.'/html.zip');unlink($dir.'/urls.txt');rmdir($dir);
$original=file_get_contents(dirname(__DIR__).'/storage/template.html');
$updated=EventGenerator::metadata($original,'PSG vs Barcelona LIVE','Explore PSG vs Barcelona with event information and viewing details. Follow the matchup through this dedicated event guide.');
check(explode('<body',$original,2)[1]===explode('<body',$updated,2)[1],'bundled template body bytes unchanged');
preg_match_all('~<script\\b[^>]*>[\\s\\S]*?</script\\s*>~i',$original,$scriptsBefore);
preg_match_all('~<script\\b[^>]*>[\\s\\S]*?</script\\s*>~i',$updated,$scriptsAfter);
check($scriptsBefore[0]===$scriptsAfter[0],'all bundled template scripts and JSON-LD unchanged');
check(str_contains($updated,'content="PSG vs Barcelona LIVE" property="og:title"'),'bundled Open Graph title replaced');
check(str_contains($updated,'content="PSG vs Barcelona LIVE" name="x:title"'),'bundled X title replaced');
echo "ALL GENERATOR TESTS PASSED\n";
