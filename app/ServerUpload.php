<?php
declare(strict_types=1);
require_once __DIR__.'/Generator.php';
require_once __DIR__.'/GitHubUpload.php';
final class ServerUpload {
 public static function destination(mixed $path,mixed $url,string $home,string $app):array {
  if(!is_string($path)||!is_string($url)||strlen($path)>4096||!str_starts_with($path,'/')||preg_match('/[\x00-\x1f\x7f]/',$path))throw new InvalidArgumentException('Enter the full server directory path, beginning with /.');
  $directory=realpath(trim($path));$home=realpath($home);$app=realpath($app);
  if($directory===false||!is_dir($directory))throw new InvalidArgumentException('Not connected: the upload directory does not exist. Confirm the full path in cPanel File Manager.');
  if(!is_writable($directory))throw new InvalidArgumentException('Not connected: the directory exists but EventForge cannot write to it. Check its ownership and permissions.');
  if($home===false||$app===false||!str_starts_with($directory,$home.'/')||$directory===$app||str_starts_with($directory,$app.'/')||str_starts_with($app,$directory.'/'))throw new InvalidArgumentException('Choose a separate website directory inside this cPanel account, outside the EventForge application.');
  $public=EventGenerator::urlDomain($url);if($public==='')throw new InvalidArgumentException('Enter the public URL matching this upload directory.');
  return ['directory'=>$directory,'public_url'=>rtrim($public,'/').'/'];
 }
 public static function checkConnection(array $destination):array {
  $directory=$destination['directory'];self::routingCheck($directory);
  $first=$directory.'/.eventforge-check-'.bin2hex(random_bytes(16)).'.tmp';$second=$first.'-renamed';$handle=null;$payload=bin2hex(random_bytes(32));
  try{
   $handle=@fopen($first,'xb');if(!$handle)throw new RuntimeException('Not connected: the app cannot create files in this directory. Check folder ownership, permissions and free disk space.');
   if(!chmod($first,0600)||fwrite($handle,$payload)!==strlen($payload)||!fflush($handle))throw new RuntimeException('Not connected: the directory write test failed. Check permissions and free disk space.');
   fclose($handle);$handle=null;
   if(!@rename($first,$second)||@file_get_contents($second)!==$payload)throw new RuntimeException('Not connected: the directory read or rename test failed. Check folder permissions.');
   if(!@unlink($second))throw new RuntimeException('Not connected: the app cannot remove test files from this directory. Check folder permissions.');
   return ['status'=>'connected','checked_at'=>gmdate('c')];
  }finally{if(is_resource($handle))fclose($handle);foreach([$first,$second] as $file)if(is_file($file))@unlink($file);}
 }
 public static function checkUrls(string $text,array $destination,array $rows):void {
  $names=GitHubUpload::filenames($rows);$found=[];
  foreach(preg_split('/\R/',$text)?:[] as $url){if(trim($url)==='')continue;

   if(!preg_match('/(?:[?&])id=([a-z0-9][a-z0-9-]{0,239})$/D',$url,$match))throw new InvalidArgumentException('The batch URL list contains an invalid file ID.');$found[]=$match[1].'.html';
  }
  sort($found);sort($names);if($found!==$names)throw new InvalidArgumentException('The saved URL list does not match the generated HTML files.');
 }
 public static function routing():string {
  return "# EventForge generated HTML routing v1\nRewriteEngine On\nRewriteCond %{QUERY_STRING} (?:^|&)id=([a-z0-9][a-z0-9-]{0,239})(?:&|$)\nRewriteRule ^$ %1.html [L]\n";
 }
 private static function routingCheck(string $directory):bool {
  $path=$directory.'/.htaccess';clearstatcache(true,$path);
  if(is_link($path)||file_exists($path)&&!is_file($path))throw new RuntimeException('The destination .htaccess must be a regular file, not a symbolic link or directory.');
  if(!is_file($path))return false;
  $content=file_get_contents($path);if($content===false)throw new RuntimeException('Could not read the existing .htaccess configuration. Check file permissions.');
  return str_contains($content,self::routing());
 }
 private static function copyAtomic(string $source,string $target):void {
  $temp=dirname($target).'/.eventforge-'.bin2hex(random_bytes(12)).'.tmp';$input=@fopen($source,'rb');$output=@fopen($temp,'xb');
  try{
   if(!$input||!$output)throw new RuntimeException('Could not write the upload. Check directory permissions and available disk space.');
   $size=filesize($source);if(stream_copy_to_stream($input,$output)!==$size||!fflush($output)||!chmod($temp,0644))throw new RuntimeException('Could not finish writing the upload. Check free disk space.');
   fclose($output);$output=null;if(!rename($temp,$target))throw new RuntimeException('Could not publish an HTML file. Check directory permissions.');
  }finally{if(is_resource($input))fclose($input);if(is_resource($output))fclose($output);if(is_file($temp))unlink($temp);}
 }
 /** Caller holds the private destination lock. Existing unrelated files are preserved. */
 public static function publish(array $destination,string $stage,array $names,?callable $progress=null):array {
  $rows=[];foreach($names as $name)$rows[]=['id'=>is_string($name)&&str_ends_with($name,'.html')?substr($name,0,-5):null];if(GitHubUpload::filenames($rows)!==$names)throw new InvalidArgumentException('Only generated HTML files can be uploaded.');
  $directory=$destination['directory'];$hasRouting=self::routingCheck($directory);$pending=[];$unchanged=0;$hashes=[];$total=count($names);$progress??=static function(array $state):void{};
  $report=static fn(string $status,int $uploaded,int $verified)=>$progress(['status'=>$status,'total'=>$total,'uploaded'=>$uploaded,'verified'=>$verified]);
  $report('preparing',0,0);
  foreach($names as $name){$source=$stage.'/'.$name;$target=$directory.'/'.$name;clearstatcache(true,$target);
   if(!is_file($source)||is_link($source))throw new RuntimeException('A staged HTML file is unavailable.');
   if(is_link($target)||file_exists($target)&&!is_file($target))throw new RuntimeException('An upload filename conflicts with an existing directory or symbolic link.');
   $hashes[$name]=hash_file('sha256',$source);if($hashes[$name]===false)throw new RuntimeException('Could not read a generated HTML file for verification.');
   if(is_file($target)){if($hashes[$name]!==hash_file('sha256',$target))throw new RuntimeException('A different file named '.$name.' already exists. Choose another upload directory.');$unchanged++;}else $pending[]=$name;
  }
  if(!$hasRouting){
   $path=$directory.'/.htaccess';$original=is_file($path)?file_get_contents($path):'';if($original===false||is_link($path))throw new RuntimeException('Could not safely read the existing .htaccess configuration.');
   if($original!==''){$backup=dirname($stage).'/server-htaccess-backup-'.bin2hex(random_bytes(12)).'.txt';if(file_put_contents($backup,$original)===false||!chmod($backup,0600))throw new RuntimeException('Could not back up the existing .htaccess configuration.');}
   $routing=$stage.'/.htaccess';$merged=self::routing()."# End EventForge generated HTML routing v1\n".$original;
   if(file_put_contents($routing,$merged)===false)throw new RuntimeException('Could not prepare URL routing.');
   // Do not overwrite an external edit made while the backup was prepared.
   if(is_link($path)||(is_file($path)?file_get_contents($path):'')!==$original)throw new RuntimeException('The .htaccess configuration changed during upload. Retry to preserve the latest rules.');
   self::copyAtomic($routing,$path);
  }
  $uploaded=$unchanged;$report('uploading',$uploaded,0);
  foreach($pending as $name){self::copyAtomic($stage.'/'.$name,$directory.'/'.$name);$report('uploading',++$uploaded,0);}
  $verified=0;$report('verifying',$uploaded,0);
  foreach($names as $name){$target=$directory.'/'.$name;clearstatcache(true,$target);if(is_link($target)||!is_file($target)||$hashes[$name]!==hash_file('sha256',$target))throw new RuntimeException('Upload verification failed for '.$name.'. Retry the upload.');$report('verifying',$uploaded,++$verified);}
  if(!self::routingCheck($directory))throw new RuntimeException('URL routing disappeared during upload. Retry the upload.');
  return ['verified'=>$verified,'uploaded'=>count($names),'unchanged'=>$unchanged,'directory'=>$directory,'public_url'=>$destination['public_url']];
 }
 public static function cleanup(string $directory):void {if(!is_dir($directory))return;foreach(scandir($directory) as $name)if($name!=='.'&&$name!=='..'&&is_file($directory.'/'.$name))unlink($directory.'/'.$name);rmdir($directory);}
}
function serverUploadSaved():array {return json_decode(setting('server_upload','{}'),true,512,JSON_THROW_ON_ERROR)?:[];}
function serverUploadDestination(mixed $path,mixed $url):array {return ServerUpload::destination($path,$url,dirname((string)realpath(ROOT)),(string)realpath(ROOT));}
function serverUploadSettingsApi():never {
 $action=$_POST['action']??'save';if(!in_array($action,['save','check'],true))throw new InvalidArgumentException('Choose Save Destination or Check Connection.');
 $previousRaw=setting('server_upload','{}');$previous=json_decode($previousRaw,true,512,JSON_THROW_ON_ERROR)?:[];$saved=serverUploadDestination($_POST['directory']??null,$_POST['public_url']??null);
 $connection=ServerUpload::checkConnection($saved);$matches=($previous['directory']??null)===$saved['directory']&&($previous['public_url']??null)===$saved['public_url'];
 if($action==='save'){$saved=array_merge($saved,$connection);saveSetting('server_upload',json_encode($saved,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));}
 elseif($matches){$updated=array_merge($saved,$connection);$q=db()->prepare('UPDATE eg_settings SET value=? WHERE name=? AND BINARY value=BINARY ?');$q->execute([json_encode($updated,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),'server_upload',$previousRaw]);if($q->rowCount()===0&&serverUploadSaved()!==$updated){$matches=false;$connection['status']='verified';}}
 else $connection['status']='verified';
 jsonOut(['destination'=>$saved,'connection'=>$connection,'saved'=>$action==='save'||$matches]);
}
function serverUploadApi():never {
 $id=$_POST['id']??'';if(!is_string($id))throw new InvalidArgumentException('Choose a completed batch.');$dir=jobDir($id);
 $q=db()->prepare('SELECT id FROM eg_jobs WHERE id=? AND user_id=? AND status=?');$q->execute([$id,$_SESSION['user_id'],'complete']);if(!$q->fetch())jsonOut(['error'=>'Completed batch not found.'],404);
 $batchLock=fopen($dir.'/batch.lock','c');if(!$batchLock||!flock($batchLock,LOCK_SH|LOCK_NB)){if(is_resource($batchLock))fclose($batchLock);throw new RuntimeException('This batch is processing or being deleted. Retry shortly.');}
 $targetLock=null;$uploadLock=null;$snapshot=null;$stage=$dir.'/server-stage-'.bin2hex(random_bytes(12));
 try{
  $uploadLock=fopen($dir.'/server-upload.lock','c');if(!$uploadLock||!flock($uploadLock,LOCK_EX|LOCK_NB))throw new RuntimeException('This batch is already uploading. Wait for it to finish.');
  $attempt=$_POST['attempt']??bin2hex(random_bytes(16));if(!is_string($attempt)||!preg_match('/^[a-f0-9]{32}$/D',$attempt))throw new InvalidArgumentException('Invalid upload request. Refresh and retry.');
  $snapshot=['attempt'=>$attempt,'status'=>'preparing','total'=>0,'uploaded'=>0,'verified'=>0,'updated_at'=>gmdate('c')];
  $report=static function(array $progress)use(&$snapshot,$dir):void{$snapshot=array_replace($snapshot,$progress,['updated_at'=>gmdate('c')]);writeFileAtomic($dir.'/server-upload-progress.json',json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));};$report([]);
  @set_time_limit(300);$saved=serverUploadSaved();$destination=serverUploadDestination($saved['directory']??null,$saved['public_url']??null);
  if(!is_file($dir.'/html.zip')||!is_file($dir.'/state.json')||!is_file($dir.'/urls.txt'))throw new RuntimeException('The completed batch files are unavailable. Generate the batch again.');
  $state=json_decode(file_get_contents($dir.'/state.json'),true,512,JSON_THROW_ON_ERROR);$conn=($state['generator']??'')==='conn';$report(['total'=>count($state['rows']??[])*($conn?2:1),'public_url'=>$destination['public_url'],'file_kind'=>$conn?'conn':'html']);if($conn){require_once __DIR__.'/ConnUpload.php';ConnUpload::checkState($state,file_get_contents($dir.'/urls.txt'),$destination);}else ServerUpload::checkUrls(file_get_contents($dir.'/urls.txt'),$destination,$state['rows']??[]);
  $targetLock=fopen(ROOT.'/storage/server-upload-'.hash('sha256',$destination['directory']).'.lock','c');if(!$targetLock||!flock($targetLock,LOCK_EX|LOCK_NB))throw new RuntimeException('Another batch is uploading to this directory. Retry shortly.');
  $names=$conn?ConnUpload::extract($dir.'/html.zip',$state['rows']??[],$stage):GitHubUpload::extract($dir.'/html.zip',$state['rows']??[],$stage);$result=$conn?ConnUpload::publish($destination,$stage,$names,$report):ServerUpload::publish($destination,$stage,$names,$report);
  writeFileAtomic($dir.'/server-upload.json',json_encode($result+['uploaded_at'=>gmdate('c')],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
  $report(['status'=>'complete','unchanged'=>$result['unchanged'],'verified_at'=>gmdate('c')]);
 }catch(Throwable $e){if($snapshot!==null){try{$report(['status'=>'error','error'=>$e instanceof PDOException?'The database connection failed. Retry shortly.':$e->getMessage()]);}catch(Throwable $ignored){}}throw $e;
 }finally{ServerUpload::cleanup($stage);if(is_resource($uploadLock)){flock($uploadLock,LOCK_UN);fclose($uploadLock);}if(is_resource($targetLock)){flock($targetLock,LOCK_UN);fclose($targetLock);}flock($batchLock,LOCK_UN);fclose($batchLock);}
 jsonOut(['upload'=>$result]);
}
function serverUploadStatusApi():never {
 $id=$_POST['id']??'';$attempt=$_POST['attempt']??null;if(!is_string($id)||$attempt!==null&&(!is_string($attempt)||!preg_match('/^[a-f0-9]{32}$/D',$attempt)))throw new InvalidArgumentException('Choose a valid upload request.');$dir=jobDir($id);
 $q=db()->prepare('SELECT id FROM eg_jobs WHERE id=? AND user_id=? AND status=?');$q->execute([$id,$_SESSION['user_id'],'complete']);if(!$q->fetch())jsonOut(['error'=>'Completed batch not found.'],404);
 $progress=['status'=>'pending'];$file=$dir.'/server-upload-progress.json';if(is_file($file)){$stored=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);if(is_array($stored)&&($attempt===null||($stored['attempt']??null)===$attempt))$progress=$stored;}
 jsonOut(['progress'=>$progress]);
}
function serverUploadPanel():string {
 $saved=serverUploadSaved();$connected=($saved['status']??'')==='connected';
 if($connected){try{serverUploadDestination($saved['directory']??null,$saved['public_url']??null);}catch(Throwable $e){$connected=false;}}
 $status=$connected?'Connected — directory verified and ready to upload':'Not Connected — save the destination to verify directory access';
 $checked=!empty($saved['checked_at'])?(new DateTimeImmutable($saved['checked_at']))->setTimezone(new DateTimeZone('Asia/Dhaka'))->format('d F Y, h:i:s A').' (Bangladesh time)':'';
 return '<section class="panel server-upload-settings" id="server-upload-settings"><h2>Upload to Server</h2><form id="server-upload-settings-form" class="stack" action="/api/server-settings" method="post">'.tokenField().'<label>Server Directory<input name="directory" type="text" maxlength="4096" value="'.h($saved['directory']??'/home/surprisehtml/eliveplay.stream/public_html/nov12thu').'" required><small>Full existing directory path in this cPanel account. Create the folder in File Manager first.</small></label><label>Public URL<input name="public_url" type="url" maxlength="2048" value="'.h($saved['public_url']??'https://eliveplay.stream/nov12thu/').'" required><small>The website URL pointing to that directory. Used to identify the upload location; generated URLs use your entered domains.</small></label><div class="description-actions"><button type="submit" class="primary">Save Destination</button><button type="button" class="secondary'.($connected?' server-connected':'').'" id="server-check-connection" data-connected="'.($connected?'true':'false').'">'.($connected?'Connected':'Check Connection').'</button></div><div id="server-connection-status" class="notice '.($connected?'success server-connected':'info').'" role="status" aria-live="polite">'.h($status).'</div><small id="server-connection-checked">'.h($checked).'</small><div id="server-upload-settings-result" class="notice" role="status" hidden></div><small>Saved destination remains in use until you change it and save again. Earlier uploads stay in their original directory.</small></form></section>';
}
