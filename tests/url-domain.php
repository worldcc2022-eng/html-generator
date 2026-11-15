<?php
require __DIR__.'/../app/Generator.php';
function domainCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
domainCheck(EventGenerator::urlDomain('example.com/')==='https://example.com/','bare domain gets HTTPS and keeps trailing slash');
domainCheck(EventGenerator::urlDomain(' https://example.com/player/ ')==='https://example.com/player/','explicit URL path and trailing slash preserved');
domainCheck(EventGenerator::urlDomain('')==='','empty domain supports legacy URLs');
foreach(['javascript://example.com','ftp://example.com','https://user:pass@example.com','https://example.com?x=1','https://example.com#fragment',"https://exam ple.com"] as $bad){$rejected=false;try{EventGenerator::urlDomain($bad);}catch(InvalidArgumentException){$rejected=true;}domainCheck($rejected,'reject invalid domain '.$bad);}
$dir=sys_get_temp_dir().'/eg-domain-'.bin2hex(random_bytes(8));mkdir($dir);
try{EventGenerator::package($dir,'<title>Old</title>',['0'=>['id'=>'video-event-live','title'=>'New']],[null],'BASE64','example.com');domainCheck(file_get_contents($dir.'/urls.txt')==="https://example.com?&xml=data:video/mp4;base64,BASE64&id=video-event-live\n",'exact domain and query in packaged TXT');EventGenerator::package($dir,'<title>Old</title>',[['id'=>'video-event-live','title'=>'New']],[null],'BASE64','https://example.com/uploaded/virtual-tour/');domainCheck(file_get_contents($dir.'/urls.txt')==="https://example.com/uploaded/virtual-tour/?&xml=data:video/mp4;base64,BASE64&id=video-event-live\n",'full supplied path and trailing slash preserved in TXT');}finally{foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
echo "ALL URL DOMAIN TESTS PASSED\n";
