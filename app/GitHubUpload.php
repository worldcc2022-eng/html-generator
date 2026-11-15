<?php
declare(strict_types=1);
require_once __DIR__.'/GitHubConnect.php';

final class GitHubUpload {
 public static function filenames(array $rows):array {
  if(!$rows||count($rows)>1000)throw new InvalidArgumentException('The batch must contain 1–1,000 generated HTML files.');
  $names=[];
  foreach($rows as $row){$id=$row['id']??null;if(!is_string($id)||!preg_match('/^[a-z0-9][a-z0-9-]{0,239}$/D',$id))throw new InvalidArgumentException('This batch has an invalid HTML filename.');$names[]=$id.'.html';}
  if(count(array_unique($names))!==count($names))throw new InvalidArgumentException('The batch has duplicate HTML filenames.');
  return $names;
 }
 public static function extract(string $zipPath,array $rows,string $directory):array {
  $names=self::filenames($rows);
  if(!class_exists('ZipArchive'))throw new RuntimeException('Enable the PHP ZIP extension to upload generated HTML files.');
  $zip=new ZipArchive;
  if($zip->open($zipPath)!==true)throw new RuntimeException('The generated ZIP could not be opened. Download or regenerate this batch and retry.');
  try{
   if($zip->numFiles!==count($names))throw new RuntimeException('The ZIP does not match the completed batch. Regenerate the batch.');
   if(!mkdir($directory,0700))throw new RuntimeException('Could not create a private upload folder.');
   foreach($names as $name){
    $source=$zip->getStream($name);if($source===false)throw new RuntimeException('A generated HTML file is missing from the ZIP.');
    $target=fopen($directory.'/'.$name,'xb');if($target===false){fclose($source);throw new RuntimeException('Could not stage an HTML file for upload.');}
    try{if(!chmod($directory.'/'.$name,0600)||stream_copy_to_stream($source,$target)===false)throw new RuntimeException('Could not extract HTML files. Check free disk space.');}finally{fclose($source);fclose($target);}
   }
  }finally{$zip->close();}
  return $names;
 }
 private static function checked(array $args,array $env,int $timeout=30,string $input=''):string {
  $r=GitHubConnect::run($args,$env,$timeout,$input);
  $step='command';foreach($args as $arg)if(in_array($arg,['init','fetch','rev-parse','read-tree','hash-object','update-index','write-tree','commit-tree'],true)){$step=$arg;break;}
  if($r['code']!==0){
   $message=GitHubConnect::commandFailure($r,'Git upload failed during '.$step);
   if($step==='fetch'&&GitHubConnect::isSshNetworkFailure($r))throw new GitHubSshNetworkError($message);
   throw new RuntimeException($message);
  }
  return trim($r['out']);
 }
 /** Overlay these files on the existing remote tree, never deleting unrelated files. */
 public static function publish(string $git,string $work,string $remote,string $branch,string $files,array $names,array $env,string $message):array {
  self::branchAndNames($branch,$names);
  $repo=$work.'/upload.git';self::checked([$git,'init','--bare','--template=',$repo],$env);
  $prefix=[$git,'-c','core.hooksPath=/dev/null','--git-dir='.$repo];$ref='refs/heads/'.$branch;
  $env=array_merge($env,['GIT_AUTHOR_NAME'=>'EventForge','GIT_AUTHOR_EMAIL'=>'eventforge@localhost','GIT_COMMITTER_NAME'=>'EventForge','GIT_COMMITTER_EMAIL'=>'eventforge@localhost']);
  foreach($names as $name)if(!is_file($files.'/'.$name)||is_link($files.'/'.$name))throw new InvalidArgumentException('A staged HTML file is unavailable.');
  for($attempt=0;$attempt<2;$attempt++){
   self::checked(array_merge($prefix,['fetch','--depth=1','--no-tags','--no-recurse-submodules',$remote,$ref]),$env,60);
   $parent=self::checked(array_merge($prefix,['rev-parse','FETCH_HEAD']),$env);
   $oldTree=self::checked(array_merge($prefix,['rev-parse','FETCH_HEAD^{tree}']),$env);
   self::checked(array_merge($prefix,['read-tree','FETCH_HEAD']),$env);
   $index='';
   foreach(array_chunk($names,50) as $group){
    $paths=array_map(fn($name)=>$files.'/'.$name,$group);
    $hashes=explode("\n",self::checked(array_merge($prefix,['hash-object','--no-filters','-w','--'], $paths),$env));
    if(count($hashes)!==count($group))throw new RuntimeException('Git did not process every HTML file.');
    foreach($group as $i=>$name){if(!preg_match('/^[a-f0-9]{40,64}$/D',$hashes[$i]))throw new RuntimeException('Git returned an invalid HTML object.');$index.='100644 '.$hashes[$i]."\t".$name."\n";}
   }
   self::checked(array_merge($prefix,['update-index','--index-info']),$env,30,$index);
   $tree=self::checked(array_merge($prefix,['write-tree']),$env);
   if($tree===$oldTree)return ['commit'=>$parent,'uploaded'=>count($names),'unchanged'=>true];
   $commit=self::checked(array_merge($prefix,['commit-tree',$tree,'-p',$parent,'-m',$message]),$env);
   $push=GitHubConnect::run(array_merge($prefix,['push','--porcelain',$remote,$commit.':'.$ref]),$env,90);
   if($push['code']===0)return ['commit'=>$commit,'uploaded'=>count($names),'unchanged'=>false];
   // Refetch before retrying: preserve concurrent commits and recover a lost success response.
  }
  if($push['code']===124)throw new RuntimeException('The push timed out. Retry to check whether the files reached GitHub.');
  throw new RuntimeException(GitHubConnect::commandFailure($push,'GitHub rejected the upload during push'));
 }
 private static function branchAndNames(string $branch,array $names):void {
  GitHubConnect::branch($branch);
  $rows=[];foreach($names as $name){if(!is_string($name)||!str_ends_with($name,'.html'))throw new InvalidArgumentException('Only generated HTML files can be uploaded.');$rows[]=['id'=>substr($name,0,-5)];}
  if(self::filenames($rows)!==$names)throw new InvalidArgumentException('Invalid upload filenames.');
 }
 public static function upload(array $connection,string $privateKey,string $zipPath,array $rows,string $batch):array {
  $repository=GitHubConnect::repository('https://github.com/'.($connection['repository']??''));$branch=GitHubConnect::branch($connection['branch']??'main');
  if(($connection['status']??'')!=='connected'||$privateKey==='')throw new InvalidArgumentException('Connect your repository in Settings before uploading.');
  $git=GitHubConnect::binary('git');$hosts=GitHubConnect::githubHosts();
  $attempt=static function(int $port)use($privateKey,$hosts,$git,$repository,$branch,$zipPath,$rows,$batch,$connection):array{return GitHubConnect::withIdentity($privateKey,$hosts,static function($dir,$env)use($git,$repository,$branch,$zipPath,$rows,$batch){
   $names=self::extract($zipPath,$rows,$dir.'/files');
   $result=self::publish($git,$dir,'git@github.com:'.$repository.'.git',$branch,$dir.'/files',$names,$env,'Upload generated HTML batch '.$batch);
   return array_merge($result,['repository'=>$repository,'branch'=>$branch,'commit_url'=>'https://github.com/'.$repository.'/commit/'.$result['commit']]);
  },$port,$connection['public_key']??null);};
  return GitHubConnect::withSshFallback($attempt,(int)($connection['ssh_port']??22));
 }
}

function githubUploadApi():never {
 $id=$_POST['id']??'';if(!is_string($id))throw new InvalidArgumentException('Choose a completed batch.');$dir=jobDir($id);
 $q=db()->prepare('SELECT id FROM eg_jobs WHERE id=? AND user_id=? AND status=?');$q->execute([$id,$_SESSION['user_id'],'complete']);
 if(!$q->fetch())jsonOut(['error'=>'Completed batch not found.'],404);
 $lock=fopen($dir.'/git-upload.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if(is_resource($lock))fclose($lock);throw new RuntimeException('This batch is already uploading. Wait for it to finish.');}
 try{
  if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
  @set_time_limit(300);
  $connection=json_decode(setting('github_connect','{}'),true)?:[];
  if(($connection['status']??'')!=='connected'||empty($connection['private_key']))throw new InvalidArgumentException('Connect your repository in Settings before uploading.');
  if(!is_file($dir.'/html.zip')||!is_file($dir.'/state.json'))throw new RuntimeException('The completed batch files are unavailable. Generate the batch again.');
  $state=json_decode(file_get_contents($dir.'/state.json'),true,512,JSON_THROW_ON_ERROR);
  if(($state['generator']??'')==='conn')throw new InvalidArgumentException('CONN currently supports server upload only.');
  $result=GitHubUpload::upload($connection,cryptKey($connection['private_key'],true),$dir.'/html.zip',$state['rows']??[],$id);
  writeFileAtomic($dir.'/github-upload.json',json_encode(['repository'=>$result['repository'],'branch'=>$result['branch'],'commit'=>$result['commit']],JSON_THROW_ON_ERROR));
 }finally{flock($lock,LOCK_UN);fclose($lock);}
 jsonOut(['upload'=>$result]);
}
