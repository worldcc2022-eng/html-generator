<?php
require __DIR__.'/../app/GitHubConnect.php';
function ghCheck($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function ghReject($fn,$label){try{$fn();}catch(InvalidArgumentException $e){ghCheck(true,$label);return;}throw new RuntimeException($label);}
ghCheck(GitHubConnect::repository(' https://github.com/worldcc2022-eng/todays-event-Nov1.git/ ')==='worldcc2022-eng/todays-event-Nov1','HTTPS URL parsed and canonicalized');
foreach(['https://evil.test/a/b','https://github.com/a/b/issues','https://github.com/a/b?x=1','https://user@github.com/a/b','https://github.com/a/b;touch-file','git@github.com:a/b','https://github.com/a/..'] as $bad)ghReject(fn()=>GitHubConnect::repository($bad),'reject unsafe repository '.$bad);
ghCheck(GitHubConnect::branch('release/events-2026')==='release/events-2026','branch supports nested names');
foreach(['-main','../main','main.lock','main//x','main@{1}','main;bad','main/'] as $bad)ghReject(fn()=>GitHubConnect::branch($bad),'reject unsafe branch '.$bad);
$keys=GitHubConnect::generateKey();
ghCheck(str_starts_with($keys['public_key'],'ssh-ed25519 '),'real Ed25519 public key generated');
ghCheck(str_contains($keys['private_key'],'BEGIN OPENSSH PRIVATE KEY'),'private key generated separately');
ghCheck(preg_match('/^SHA256:[A-Za-z0-9+\/]+$/',$keys['fingerprint'])===1,'fingerprint generated');
$p=GitHubConnect::run([PHP_BINARY,'-r','fwrite(STDOUT,"ok");fwrite(STDERR,"err");exit(7);']);
ghCheck($p['code']===7&&$p['out']==='ok'&&$p['err']==='err','subprocess preserves exit and separate output');
$start=microtime(true);$p=GitHubConnect::run([PHP_BINARY,'-r','sleep(10);'],[],1);
ghCheck($p['code']===124&&microtime(true)-$start<4,'subprocess timeout enforced');
$host=GitHubConnect::knownHosts([$keys['public_key']]);
ghCheck(str_starts_with($host,'github.com ssh-ed25519 '),'known hosts content restricted to GitHub');
ghReject(fn()=>GitHubConnect::knownHosts(["ssh-ed25519 AAAA\nevil.test ssh-ed25519 AAAA"]),'host key newline injection rejected');
$dir='';
try{GitHubConnect::withIdentity($keys['private_key'],$host,function($path,$env)use(&$dir){$dir=$path;ghCheck((fileperms($path)&0777)===0700,'temporary identity directory private');ghCheck((fileperms($path.'/identity')&0777)===0600,'private key mode 0600');ghCheck(str_contains($env['GIT_SSH_COMMAND'],'StrictHostKeyChecking=yes'),'strict host checking enabled');throw new RuntimeException('cleanup-test');});}catch(RuntimeException $e){if($e->getMessage()!=='cleanup-test')throw $e;}
ghCheck($dir!==''&&!file_exists($dir),'identity removed after failed connection');
echo "ALL GITHUB CONNECT TESTS PASSED\n";
$encrypt=fn($s)=>'encrypted:'.base64_encode($s);$decrypt=fn($s)=>base64_decode(substr($s,10));
$state=GitHubConnect::configure([],'generate','https://github.com/owner/repository','main',$encrypt,$decrypt);
ghCheck(str_starts_with($state['private_key'],'encrypted:')&&!str_contains($state['private_key'],'BEGIN OPENSSH'),'only encrypted private key retained in settings state');
$again=GitHubConnect::configure($state,'generate','https://github.com/owner/repository','main',$encrypt,$decrypt);
ghCheck($state['public_key']===$again['public_key'],'repeated generate keeps the existing deploy key');
$public=GitHubConnect::publicState($state);
ghCheck(!isset($public['private_key'])&&!str_contains(json_encode($public),'BEGIN OPENSSH'),'public response never exposes private key');
$state['status']='connected';$state['checked_at']='2026-11-02T00:00:00Z';
$changed=GitHubConnect::configure($state,'generate','https://github.com/owner/repository','next',$encrypt,$decrypt);
ghCheck($changed['status']==='disconnected'&&empty($changed['checked_at']),'changing branch invalidates prior connection');
$other=GitHubConnect::configure($state,'generate','https://github.com/owner/another','main',$encrypt,$decrypt);
ghCheck($other['public_key']!==$state['public_key']&&$other['status']==='disconnected','different repository receives a dedicated key');
$missing=GitHubConnect::configure([],'connect','https://github.com/owner/repository','main',$encrypt,$decrypt);
ghCheck($missing['status']==='disconnected'&&str_contains($missing['error'],'Generate'),'missing key cannot report success');
