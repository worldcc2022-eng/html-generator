<?php
declare(strict_types=1);
require_once __DIR__.'/ConnGenerator.php';
final class ConnUpload {
 public static function names(array $rows):array {
  if(!$rows||count($rows)>1000)throw new InvalidArgumentException('Invalid CONN batch.');$names=[];
  foreach($rows as $row){$id=$row['id']??'';if(!is_string($id)||!preg_match('/^[1-9][0-9]{14}$/D',$id))throw new InvalidArgumentException('Invalid CONN ID.');$names[]=$id.'.html';$names[]=$id.'.xml';}
  if(count(array_unique($names))!==count($names))throw new InvalidArgumentException('Duplicate CONN ID.');return $names;
 }
 public static function checkState(array $state,string $urls,array $destination):void {
  $base=ConnGenerator::baseUrl($state['conn']['base_url']??'');$savedBase=ConnGenerator::baseUrl($destination['public_url']);if($base!==$savedBase)throw new InvalidArgumentException('CONN batch Hosting URL: '.$base.' — Settings Public URL: '.$savedBase.'. Update Settings to the batch hosting URL and its matching directory, or generate a new batch for the new hosting URL. Domain and directory path must match.');
  self::names($state['rows']??[]);$expected=[];
  $conn=$state['conn'];if(isset($conn['output_endpoints'])){
   ConnGenerator::labEndpoints(implode("\n",$conn['output_endpoints']),$base);
   if(count($conn['output_assignments']??[])!==count($state['rows']))throw new InvalidArgumentException('CONN output assignments are incomplete.');
   foreach($conn['output_assignments'] as $endpoint)if(!in_array($endpoint,$conn['output_endpoints'],true))throw new InvalidArgumentException('CONN output assignment does not match the batch.');
  }
  foreach($state['rows'] as $i=>$row)$expected[]=ConnGenerator::links($base,$row['id'],$state['conn']['uuids'][$i]??'',$conn['output_assignments'][$i]??null)['output_url'];
  if(preg_split('/\R/',trim($urls))!==$expected)throw new InvalidArgumentException('CONN URLs do not match their saved HTML/XML pairs.');
 }
 public static function extract(string $zipPath,array $rows,string $stage):array {
  $names=self::names($rows);if(!class_exists('ZipArchive'))throw new RuntimeException('Enable the PHP ZIP extension to upload CONN batches.');$zip=new ZipArchive;
  if($zip->open($zipPath)!==true)throw new RuntimeException('Could not open the CONN ZIP.');
  try{
   if($zip->numFiles!==count($names)+4)throw new RuntimeException('CONN archive does not match the batch.');
   if(!mkdir($stage,0700))throw new RuntimeException('Could not create private upload staging.');
   foreach($names as $name){$source=$zip->getStream('.eventforge-conn/'.$name);if(!$source)throw new RuntimeException('An HTML/XML pair is missing from the archive.');$target=@fopen($stage.'/'.$name,'xb');
    try{if(!$target||!chmod($stage.'/'.$name,0600)||stream_copy_to_stream($source,$target)===false||!fflush($target))throw new RuntimeException('Could not stage a CONN file.');}finally{fclose($source);if(is_resource($target))fclose($target);}
   }
  }finally{$zip->close();}return $names;
 }
 private static function regular(string $path):void {clearstatcache(true,$path);if(is_link($path)||(file_exists($path)&&!is_file($path)))throw new RuntimeException('A CONN filename conflicts with a directory or symbolic link.');}
 private static function copy(string $source,string $target):void {
  self::regular($target);$temp=dirname($target).'/.conn-'.bin2hex(random_bytes(12)).'.tmp';$in=@fopen($source,'rb');$out=@fopen($temp,'xb');
  try{if(!$in||!$out||stream_copy_to_stream($in,$out)!==filesize($source)||!fflush($out)||!chmod($temp,0644))throw new RuntimeException('Could not publish CONN files. Check permissions and disk space.');fclose($out);$out=null;self::regular($target);if(!rename($temp,$target))throw new RuntimeException('Could not publish CONN file.');}
  finally{if(is_resource($in))fclose($in);if(is_resource($out))fclose($out);if(is_file($temp))unlink($temp);}
 }
 private static function content(string $target,string $bytes,string $stage):void {
  $source=$stage.'/.conn-config-'.bin2hex(random_bytes(8));try{if(file_put_contents($source,$bytes)!==strlen($bytes))throw new RuntimeException('Could not prepare CONN routing.');self::copy($source,$target);if(hash_file('sha256',$target)!==hash('sha256',$bytes))throw new RuntimeException('Could not verify CONN routing.');}finally{if(is_file($source))unlink($source);}
 }
 /** Caller holds the existing per-destination lock. Publish complete pairs before routing. */
 public static function publish(array $destination,string $stage,array $names,?callable $progress=null):array {
  $ids=[];foreach($names as $name){if(!is_string($name)||!preg_match('/^([1-9][0-9]{14})\.(html|xml)$/D',$name,$m))throw new InvalidArgumentException('Invalid CONN upload filename.');if($m[2]==='html')$ids[]=['id'=>$m[1]];}
  if(self::names($ids)!==$names)throw new InvalidArgumentException('Incomplete CONN pairs.');
  $directory=$destination['directory'];$data=$directory.'/.eventforge-conn';clearstatcache(true,$data);if(is_link($data)||(file_exists($data)&&!is_dir($data)))throw new RuntimeException('CONN data path must be a regular directory.');
  $rootHt=$directory.'/.htaccess';$router=$directory.'/eventforge-conn.php';self::regular($rootHt);self::regular($router);self::regular($data.'/.htaccess');
  $original=is_file($rootHt)?file_get_contents($rootHt):'';if($original===false)throw new RuntimeException('Could not read the existing .htaccess.');
  if(is_file($router)&&file_get_contents($router)!==ConnGenerator::router())throw new RuntimeException('eventforge-conn.php already contains different code. Keep a backup and resolve that conflict before uploading.');
  $protection="Require all denied\n";if(is_file($data.'/.htaccess')&&file_get_contents($data.'/.htaccess')!==$protection)throw new RuntimeException('The CONN data directory has different access rules. Choose a clean destination.');
  $hashes=[];$unchanged=0;$progress??=static function(array $p):void{};$total=count($names);$report=static fn($status,$uploaded,$verified)=>$progress(['status'=>$status,'total'=>$total,'uploaded'=>$uploaded,'verified'=>$verified,'file_kind'=>'conn']);
  foreach($names as $name){$source=$stage.'/'.$name;self::regular($source);if(!is_file($source))throw new RuntimeException('A staged CONN file is missing.');$hashes[$name]=hash_file('sha256',$source);if($hashes[$name]===false)throw new RuntimeException('Could not hash CONN file.');self::regular($data.'/'.$name);if(is_file($data.'/'.$name)){if(hash_file('sha256',$data.'/'.$name)!==$hashes[$name])throw new RuntimeException('A different CONN file already uses ID '.substr($name,0,15).'. Generate a new batch.');$unchanged++;}}
  if(!is_dir($data)&&!mkdir($data,0755))throw new RuntimeException('Could not create CONN data directory.');
  self::content($data.'/.htaccess',$protection,$stage);$uploaded=0;$report('uploading',0,0);
  foreach($names as $name){if(!is_file($data.'/'.$name))self::copy($stage.'/'.$name,$data.'/'.$name);$report('uploading',++$uploaded,0);}
  $verified=0;foreach($names as $name){self::regular($data.'/'.$name);if(!is_file($data.'/'.$name)||hash_file('sha256',$data.'/'.$name)!==$hashes[$name])throw new RuntimeException('CONN file verification failed. Retry the upload.');$report('verifying',$uploaded,++$verified);}
  self::content($router,ConnGenerator::router(),$stage);
  $routing=ConnGenerator::routing();if(!str_contains($original,$routing)){
   if(str_contains($original,'# BEGIN EventForge CONN'))throw new RuntimeException('Existing CONN routing differs. Resolve the old routing block before uploading.');
   if($original!==''){$backup=dirname($stage).'/conn-htaccess-backup-'.bin2hex(random_bytes(12)).'.txt';if(file_put_contents($backup,$original)!==strlen($original)||!chmod($backup,0600))throw new RuntimeException('Could not back up the existing .htaccess.');}
   self::regular($rootHt);if((is_file($rootHt)?file_get_contents($rootHt):'')!==$original)throw new RuntimeException('The .htaccess changed during upload. Retry to preserve that edit.');self::content($rootHt,$routing.$original,$stage);
  }
  if(file_get_contents($router)!==ConnGenerator::router()||file_get_contents($data.'/.htaccess')!==$protection||!str_contains((string)file_get_contents($rootHt),$routing))throw new RuntimeException('CONN routing verification failed.');
  return ['uploaded'=>$total,'verified'=>$verified,'unchanged'=>$unchanged,'html_files'=>count($ids),'xml_files'=>count($ids),'file_kind'=>'conn','directory'=>$directory,'public_url'=>$destination['public_url']];
 }
}
