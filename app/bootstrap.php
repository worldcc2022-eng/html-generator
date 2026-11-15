<?php
declare(strict_types=1);
if(!defined('EVENTGEN')){http_response_code(404);exit;}
const ROOT=__DIR__.'/..';
const LOGIN_SESSION_SECONDS=30*24*60*60;
require_once __DIR__.'/Generator.php';
require_once __DIR__.'/SavedXml.php';
require_once __DIR__.'/TemplateRenderer.php';
require_once __DIR__.'/TitleBank.php';
require_once __DIR__.'/IdBank.php';
require_once __DIR__.'/MovieInput.php';
require_once __DIR__.'/SchemaTime.php';
require_once __DIR__.'/BroadcastDurations.php';
require_once __DIR__.'/GeneratorUi.php';
require_once __DIR__.'/EventKeywords.php';
ini_set('display_errors','0');
if(PHP_SAPI!=='cli'){
ini_set('session.use_strict_mode','1');
ini_set('session.gc_maxlifetime',(string)LOGIN_SESSION_SECONDS);
$sessionDir=ROOT.'/storage/sessions';
if(!is_dir($sessionDir))@mkdir($sessionDir,0700);
if(is_dir($sessionDir)&&is_writable($sessionDir))session_save_path($sessionDir);
session_name('eventgen_session');
session_set_cookie_params(['lifetime'=>LOGIN_SESSION_SECONDS,'httponly'=>true,'secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'),'samesite'=>'Strict','path'=>'/']);
session_start();
header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: same-origin');header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data: https://surprisesports.com/favicon.png; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
}
function h(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function csrf():string{return $_SESSION['csrf']??=bin2hex(random_bytes(32));}
function tokenField():string{return '<input type="hidden" name="csrf" value="'.h(csrf()).'">';}
function verifyCsrf():void{if(!is_string($_POST['csrf']??null)||!hash_equals(csrf(),$_POST['csrf']))throw new InvalidArgumentException('Your session expired. Refresh the page and try again.');}
function config():array{static $c;return $c??=require ROOT.'/config.php';}
function db():PDO{static $db;if(!$db){$c=config();$db=new PDO('mysql:host='.$c['db_host'].';port='.$c['db_port'].';dbname='.$c['db_name'].';charset=utf8mb4',$c['db_user'],$c['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}return $db;}
function setting(string $name,string $fallback=''):string{$q=db()->prepare('SELECT value FROM eg_settings WHERE name=?');$q->execute([$name]);$v=$q->fetchColumn();return $v===false?$fallback:(string)$v;}
function saveSetting(string $name,string $value):void{db()->prepare('INSERT INTO eg_settings (name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute([$name,$value]);}
function cryptKey(string $value,bool $decrypt=false):string{
 $key=hex2bin(config()['secret']);
 if($decrypt){$v=base64_decode($value,true);if($v===false||strlen($v)<28)throw new RuntimeException('Saved API key is invalid. Save it again.');$plain=openssl_decrypt(substr($v,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($v,0,12),substr($v,12,16));if($plain===false)throw new RuntimeException('Unable to decrypt API key.');return $plain;}
 $iv=random_bytes(12);$encrypted=openssl_encrypt($value,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);if($encrypted===false)throw new RuntimeException('Unable to secure API key.');return base64_encode($iv.$tag.$encrypted);
}
function requireLogin():void{
 if(empty($_SESSION['user_id'])||time()-($_SESSION['last_active']??0)>LOGIN_SESSION_SECONDS){$_SESSION=[];if(str_starts_with(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'','/api/')){http_response_code(401);header('Content-Type: application/json');echo json_encode(['error'=>'Sign in again, then resume the batch.']);exit;}header('Location: /login');exit;}
 $_SESSION['last_active']=time();
 if(PHP_SAPI!=='cli'&&session_status()===PHP_SESSION_ACTIVE){
  $cookie=session_get_cookie_params();
  setcookie(session_name(),session_id(),['expires'=>time()+LOGIN_SESSION_SECONDS,'path'=>$cookie['path'],'domain'=>$cookie['domain'],'secure'=>$cookie['secure'],'httponly'=>$cookie['httponly'],'samesite'=>$cookie['samesite']]);
 }
}

function page(string $title,string $content,bool $public=false,string $headerExtra=''):void{
 if(!$public&&$headerExtra===''){require_once __DIR__.'/IndexingPage.php';$headerExtra=indexingWorkerBadge(indexingHeaderWorker(),csrf());}
 $currentPath=rtrim(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/','/')?:'/';$links='';
 foreach(['/'=>'Generator','/movies'=>'Movies','/conn'=>'CONN','/indexing'=>'Indexing','/settings'=>'Settings'] as $url=>$label){
  $active=$currentPath===$url||($url!=='/'&&str_starts_with($currentPath,$url.'/'));
  $links.='<a class="button '.($active?'primary':'secondary').'" href="'.h($url).'"'.($active?' aria-current="page"':'').'>'.h($label).'</a>';
 }
 $nav=$public?'':'<nav class="header-navigation">'.$links.$headerExtra.'</nav><form class="header-logout" method="post" action="/logout">'.tokenField().'<button class="secondary">Sign Out</button></form>';
 echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.h($title).' · EventForge</title><link rel="icon" href="https://surprisesports.com/favicon.png?v=1.9.52" type="image/png"><link rel="stylesheet" href="/assets/style.css?v=1.9.80"><script src="/assets/github-connect.js?v=1.9.52" defer></script><script src="/assets/github-upload.js?v=1.9.52" defer></script><script src="/assets/server-upload.js?v=1.9.86" defer></script><script src="/assets/rocket-indexing.js?v=1.9.67" defer></script><script src="/assets/indexing.js?v=1.9.85" defer></script><script src="/assets/saved-xml.js?v=1.9.84" defer></script><script src="/assets/broadcast-durations.js?v=1.9.66" defer></script><script src="/assets/schema-spread.js?v=1.9.77" defer></script><script src="/assets/event-keywords.js?v=1.9.68" defer></script><script src="/assets/app.js?v=1.9.86" defer></script><script src="/assets/conn.js?v=1.9.86" defer></script></head><body><header><a class="brand" href="/" aria-label="EventForge"><span class="brand-first">EVENT</span><span class="brand-last">FORGE</span></a>'.$nav.'</header><main class="'.($public?'auth':'').'">'.$content.'</main><footer>EventForge · Your event file workspace</footer></body></html>';
}
function input(string $label,string $name,string $type='text',string $value='',string $hint='',bool $required=true):string{
 return '<label>'.h($label).'<input type="'.h($type).'" name="'.h($name).'" value="'.h($value).'" '.($required?'required':'').' '.($type==='password'?'autocomplete="new-password"':'').'>'.($hint?'<small>'.h($hint).'</small>':'').'</label>';
}
function message(string $s,string $class='error'):string{return '<div class="notice '.h($class).'" role="status">'.h($s).'</div>';}
function jsonOut(array $data,int $status=200):never{http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
function jobDir(string $id):string{if(!preg_match('/^[a-f0-9]{32}$/',$id))throw new InvalidArgumentException('Invalid batch.');return ROOT.'/storage/'.$id;}
function writeFileAtomic(string $file,string $data):void{if(file_put_contents($file.'.tmp',$data,LOCK_EX)===false||!rename($file.'.tmp',$file))throw new RuntimeException('Unable to write storage. Check directory permissions.');}

function templatePath():string{
 $file=setting('template_file');
 if($file!==''&&preg_match('/^template-[a-f0-9]{24}\.html$/',$file))return ROOT.'/storage/'.$file;
 return ROOT.'/storage/template.html';
}

function recentBatchRows(int $userId):string{
 $q=db()->prepare("SELECT j.* FROM eg_jobs j WHERE j.user_id=? AND NOT EXISTS (SELECT 1 FROM eg_settings s WHERE s.name=CONCAT('hidden_job_',j.id)) ORDER BY j.created_at DESC,j.id DESC LIMIT 20");$q->execute([$userId]);$html='';
 foreach($q as $job){
  $id=h($job['id']);$html.='<tr><td>'.h($job['event']).'</td><td>'.(int)$job['completed'].' / '.(int)$job['total'].'</td><td>'.h($job['created_at']).'</td><td>';
  if($job['status']==='complete')$html.='<a href="/download?id='.$id.'&amp;type=zip">HTML ZIP</a> <a href="/download?id='.$id.'&amp;type=txt">URLs TXT</a>';
  else $html.='<button type="button" class="resume secondary" data-id="'.$id.'">Resume</button>';
  $html.='<form method="post" action="/trash" class="description-actions">'.tokenField().'<input type="hidden" name="id" value="'.$id.'"><button class="secondary" name="action" value="trash">Move to Trash</button></form></td></tr>';
 }
 return $html?:'<tr><td colspan="4" class="hint">Your generated batches will appear here.</td></tr>';
}
function activitySpinner(bool $hidden=false):string{
 return '<span class="activity-spinner" aria-hidden="true"'.($hidden?' hidden':'').'>'.str_repeat('<i></i>',12).'</span>';
}
