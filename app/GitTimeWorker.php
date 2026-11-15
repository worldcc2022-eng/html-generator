<?php
declare(strict_types=1);
require_once __DIR__.'/IndexingQueue.php';
require_once __DIR__.'/GitHubTimestamps.php';
require_once __DIR__.'/GitHubUpload.php';
final class GitTimeWorker {
 public function __construct(private PDO $db,private IndexingQueue $queue,private string $storage,private ?Closure $clock=null){}
 private function now():int{return $this->clock?($this->clock)():time();}
 private function files(array $p):array {
  if(!preg_match('/^[a-f0-9]{32}$/D',$p['job_id']))throw new RuntimeException('Invalid source generation batch.');
  $q=$this->db->prepare('SELECT id FROM eg_jobs WHERE id=? AND user_id=? AND status=?');$q->execute([$p['job_id'],$p['user_id'],'complete']);if(!$q->fetchColumn())throw new RuntimeException('The original completed generation batch is unavailable.');
  $dir=$this->storage.'/'.$p['job_id'];$path=$dir.'/state.json';
  if(!is_file($path)||is_link($path))throw new RuntimeException('The source batch state is unavailable.');
  $state=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$allowed=array_fill_keys(GitHubUpload::filenames($state['rows']??[]),true);
  if(is_file($dir.'/github-upload.json')){
   $upload=json_decode(file_get_contents($dir.'/github-upload.json'),true,512,JSON_THROW_ON_ERROR);
   if(($upload['repository']??'')!==$p['repository']||($upload['branch']??'')!==$p['branch'])throw new RuntimeException('This batch was uploaded to a different repository or branch. Select that connection or upload the batch here first.');
  }
  $out=[];foreach(json_decode($p['urls_json'],true,512,JSON_THROW_ON_ERROR) as $url){$name=GitHubTimestamps::filename($url);if(!isset($allowed[$name]))throw new RuntimeException('A pending file ID does not belong to the source generation batch.');$out[$name]=$p['schema_at'];}
  return $out;
 }
 public function cycle(array $connection,callable $publish):array {
  if($this->queue->control('github_time_enabled')!=='1')return ['ready'=>[],'errors'=>[],'updated'=>0];
  $gate=new GitTimeQueue($this->db,$this->clock);$this->queue->control('github_time_heartbeat',(string)$this->now());
  return $this->queue->prepareGithub(function($plans)use($connection,$publish,$gate){
   if($this->queue->control('github_time_enabled')!=='1')return ['ready'=>[],'errors'=>[],'updated'=>0];
   $pending=[];$names=[];
   foreach($plans as $p){
    if($p['state']==='ready'||($p['next_attempt_at']&&$p['next_attempt_at']>gmdate('Y-m-d H:i:s',$this->now())))continue;
    try{
     if(($connection['status']??'')!=='connected'||$p['repository']!==($connection['repository']??'')||$p['branch']!==($connection['branch']??'main'))throw new RuntimeException('Connect this batch’s GitHub repository in Settings.');
     $files=$this->files($p);
     $next=array_replace($names,array_fill_keys(array_keys($files),true));if(count($next)>1000)continue;
     $started=$gate->started($p['id']);$p['schema_at']=$started['schema_at'];foreach($files as &$stamp)$stamp=$p['schema_at'];unset($stamp);
     $names=$next;$pending[$p['id']]=['files'=>$files,'schema_at'=>$p['schema_at']];
    }catch(Throwable $e){$gate->failed($p['id'],$e->getMessage());}
   }
   if(!$pending)return ['ready'=>[],'errors'=>[],'updated'=>0];
   $this->queue->control('github_time_heartbeat',(string)$this->now());
   try{
    $result=$publish($pending);
    foreach($result['ready'] as $id)$gate->ready($id,$result['commit'],$pending[$id]['schema_at']);
    foreach($result['errors'] as $id=>$error)$gate->failed($id,$error,$pending[$id]['schema_at']);
    $this->queue->control('github_time_last_commit',(string)($result['commit']??''));
    return $result;
   }catch(Throwable $e){foreach(array_keys($pending) as $id)$gate->failed($id,$e->getMessage(),$pending[$id]['schema_at']);throw $e;}
   finally{$this->queue->control('github_time_heartbeat',(string)$this->now());}
  });
 }
}
