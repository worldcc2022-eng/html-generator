<?php
declare(strict_types=1);
require_once __DIR__.'/GitHubConnect.php';
final class GitHubTimestamps {
 /** Replace existing time strings, including in otherwise malformed JSON-LD. */
 public static function rewrite(string $html,string $stamp):array {
  if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}-05:00$/D',$stamp))throw new InvalidArgumentException('Invalid schema timestamp.');
  $count=0;
  $html=preg_replace_callback('~(<script\b[^>]*\btype\s*=\s*(["\x27])application/ld\+json\2[^>]*>)(.*?)(</script\s*>)~is',function($script)use($stamp,&$count){
   $body=$script[3];$stack=[];$objects=[];
   preg_match_all('~"(?:\\\\.|[^"\\\\])*"|[{}]~s',$body,$tokens,PREG_OFFSET_CAPTURE);
   foreach($tokens[0] as [$token,$offset]){
    if($token==='{')$stack[]=$offset;
    elseif($token==='}')array_pop($stack);
    else $objects[$offset]=$stack?end($stack):null;
   }
   $target=[];
   preg_match_all('~"\s*@type\s*"\s*:\s*(?:"\s*(?:NewsArticle|VideoObject)\s*"|\[(?:(?:"(?:\\\\.|[^"\\\\])*"|[^\]"{}])*?)"\s*(?:NewsArticle|VideoObject)\s*")~is',$body,$types,PREG_OFFSET_CAPTURE);
   foreach($types[0] as [$text,$offset])if(isset($objects[$offset]))$target[$objects[$offset]]=true;
   $body=preg_replace_callback('~("\s*(?:datePublished|dateModified|uploadDate)\s*"\s*:\s*)"(?:\\\\.|[^"\\\\])*"~s',function($m)use($stamp,$target,$objects,&$count){
    if(!isset($objects[$m[0][1]])||!isset($target[$objects[$m[0][1]]]))return $m[0][0];
    $count++;return $m[1][0].json_encode($stamp,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
   },$body,-1,$ignored,PREG_OFFSET_CAPTURE);
   return $script[1].$body.$script[4];
  },$html);
  if($html===null)throw new RuntimeException('Could not update the existing schema timestamp.');
  return [$html,$count];
 }
 public static function filename(string $url):string {
  $query=parse_url($url,PHP_URL_QUERY);if(!is_string($query))throw new InvalidArgumentException('A pending URL has no generated file ID.');
  // Reject ambiguous IDs, arrays and duplicate query keys rather than guessing.
  $ids=[];foreach(explode('&',$query) as $part){$pair=explode('=',$part,2);if(rawurldecode($pair[0])==='id')$ids[]=rawurldecode($pair[1]??'');}
  if(count($ids)!==1||!preg_match('/^[a-z0-9][a-z0-9-]{0,239}$/D',$ids[0]))throw new InvalidArgumentException('A pending URL has an invalid generated file ID.');
  return $ids[0].'.html';
 }
 private static function run(array $args,array $env,int $timeout=30,string $input=''):string {
  $r=GitHubConnect::run($args,$env,$timeout,$input);if($r['code']!==0){$message=GitHubConnect::commandFailure($r,'Git timestamp update failed');if(in_array('fetch',$args,true)&&GitHubConnect::isSshNetworkFailure($r))throw new GitHubSshNetworkError($message);throw new RuntimeException($message);}return rtrim($r['out'],"\r\n");
 }
 /** Stream a bounded blob to disk, never through the command runner's output limit. */
 private static function blob(array $prefix,string $oid,string $path,array $env):string {
  $size=self::run(array_merge($prefix,['cat-file','-s',$oid]),$env);
  if(!ctype_digit($size)||(int)$size>16777216)throw new RuntimeException('The remote HTML file exceeds the 16 MB editing limit.');
  $env=array_merge(['PATH'=>'/usr/local/bin:/usr/bin:/bin','LANG'=>'C','GIT_CONFIG_NOSYSTEM'=>'1','GIT_CONFIG_GLOBAL'=>'/dev/null'],$env);
  $p=proc_open(array_merge($prefix,['cat-file','blob',$oid]),[0=>['pipe','r'],1=>['file',$path,'w'],2=>['pipe','w']],$pipes,null,$env,['bypass_shell'=>true]);
  if(!is_resource($p))throw new RuntimeException('Could not read a remote HTML file.');
  chmod($path,0600);fclose($pipes[0]);stream_set_blocking($pipes[2],false);$start=microtime(true);$error='';$code=-1;
  try{do{$error.=stream_get_contents($pipes[2]);$state=proc_get_status($p);if(!$state['running']){$code=$state['exitcode'];break;}if(microtime(true)-$start>30||strlen($error)>65536){proc_terminate($p,9);$code=124;break;}usleep(20000);}while(true);}
  finally{fclose($pipes[2]);$closed=proc_close($p);if($code===-1)$code=$closed;}
  clearstatcache(true,$path);
  if($code!==0||filesize($path)!==(int)$size)throw new RuntimeException('Could not read the complete remote HTML file.');
  $html=file_get_contents($path);if($html===false)throw new RuntimeException('Could not stage the remote HTML file.');return $html;
 }
 /** Plans contain whitelisted filename => timestamp maps. All valid plans share one commit. */
 public static function publish(string $git,string $work,string $remote,string $branch,array $plans,array $env=[]):array {
  GitHubConnect::branch($branch);$desired=[];
  foreach($plans as $plan)foreach($plan['files'] as $name=>$stamp){if(!preg_match('/^[a-z0-9][a-z0-9-]{0,239}\.html$/D',$name))throw new InvalidArgumentException('Invalid generated filename.');if(!isset($desired[$name])||strcmp($stamp,$desired[$name])>0)$desired[$name]=$stamp;}
  if(!$desired||count($desired)>1000)throw new InvalidArgumentException('Prepare 1–1,000 distinct HTML files per Git commit.');
  $repo=$work.'/timestamps.git';self::run([$git,'init','--bare','--template=',$repo],$env);$prefix=[$git,'-c','core.hooksPath=/dev/null','--git-dir='.$repo];$ref='refs/heads/'.$branch;
  $env=array_merge($env,['GIT_AUTHOR_NAME'=>'EventForge','GIT_AUTHOR_EMAIL'=>'eventforge@localhost','GIT_COMMITTER_NAME'=>'EventForge','GIT_COMMITTER_EMAIL'=>'eventforge@localhost']);
  for($attempt=0;$attempt<2;$attempt++){
   self::run(array_merge($prefix,['fetch','--depth=1','--no-tags','--no-recurse-submodules',$remote,$ref]),$env,60);
   $parent=self::run(array_merge($prefix,['rev-parse','FETCH_HEAD']),$env);$old=self::run(array_merge($prefix,['rev-parse','FETCH_HEAD^{tree}']),$env);self::run(array_merge($prefix,['read-tree','FETCH_HEAD']),$env);
   $entries=[];
   foreach(array_chunk(array_keys($desired),50) as $names){$raw=self::run(array_merge($prefix,['ls-tree','-z','FETCH_HEAD','--'],$names),$env);foreach(explode("\0",$raw) as $entry)if(preg_match('/^(100644|100755) blob ([a-f0-9]{40,64})\t(.+)$/D',$entry,$m))$entries[$m[3]]=[$m[1],$m[2]];}
   $files=[];$errors=[];
   foreach($desired as $name=>$stamp){
    try{if(!isset($entries[$name]))throw new RuntimeException('Generated HTML is missing from the connected repository: '.$name);
     $path=$work.'/'.$name;$html=self::blob($prefix,$entries[$name][1],$path,$env);[$updated,$count]=self::rewrite($html,$stamp);if(!$count)throw new RuntimeException('No existing NewsArticle or VideoObject timestamp in '.$name);
     if(file_put_contents($path,$updated)===false)throw new RuntimeException('Could not stage the timestamp edit.');$files[$name]=$path;
    }catch(Throwable $e){$errors[$name]=$e->getMessage();}
   }
   $successful=[];$failed=[];$include=[];
   foreach($plans as $id=>$plan){foreach($plan['files'] as $name=>$stamp)if(isset($errors[$name])){$failed[$id]=$errors[$name];continue 2;}$successful[]=$id;foreach($plan['files'] as $name=>$stamp)$include[$name]=true;}
   if(!$successful)return ['ready'=>[],'errors'=>$failed,'commit'=>null,'updated'=>0];
   $index='';foreach(array_keys($include) as $name){$oid=self::run(array_merge($prefix,['hash-object','--no-filters','-w','--',$files[$name]]),$env);$index.=$entries[$name][0].' '.$oid."\t".$name."\n";}
   self::run(array_merge($prefix,['update-index','--index-info']),$env,30,$index);$tree=self::run(array_merge($prefix,['write-tree']),$env);
   if($tree===$old)return ['ready'=>$successful,'errors'=>$failed,'commit'=>$parent,'updated'=>count($include),'unchanged'=>true];
   $commit=self::run(array_merge($prefix,['commit-tree',$tree,'-p',$parent,'-m','Update schema times for '.count($successful).' indexing batches']),$env);
   $push=GitHubConnect::run(array_merge($prefix,['push','--porcelain',$remote,$commit.':'.$ref]),$env,90);
   if($push['code']===0)return ['ready'=>$successful,'errors'=>$failed,'commit'=>$commit,'updated'=>count($include),'unchanged'=>false];
   // Refetch and edit the latest bytes again after concurrent pushes or a lost response.
  }
  throw new RuntimeException(GitHubConnect::commandFailure($push,'GitHub timestamp push failed'));
 }
 public static function update(array $connection,string $private,array $plans):array {
  if(($connection['status']??'')!=='connected'||$private==='')throw new RuntimeException('Connect the GitHub repository in Settings.');
  $repo=GitHubConnect::repository('https://github.com/'.($connection['repository']??''));$branch=GitHubConnect::branch($connection['branch']??'main');$hosts=GitHubConnect::githubHosts();$git=GitHubConnect::binary('git');
  return GitHubConnect::withSshFallback(static fn($port)=>GitHubConnect::withIdentity($private,$hosts,static fn($dir,$env)=>self::publish($git,$dir,'git@github.com:'.$repo.'.git',$branch,$plans,$env),$port,$connection['public_key']??null),(int)($connection['ssh_port']??22));
 }
}
