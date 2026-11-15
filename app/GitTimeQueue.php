<?php
declare(strict_types=1);
require_once __DIR__.'/GitDeploymentStatus.php';
/** A preparation never accepts or removes an IndexBear URL. */
final class GitTimeQueue {
 public function __construct(private PDO $db,private ?Closure $clock=null){}
 private function now():int{return $this->clock?($this->clock)():time();}
 private function query(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
 public function ensureSchema():void {
  $suffix=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
  $this->db->exec('CREATE TABLE IF NOT EXISTS eg_index_git_preparations (id VARCHAR(64) PRIMARY KEY,batch_id VARCHAR(32) NOT NULL,start_offset INT NOT NULL,urls_json MEDIUMTEXT NOT NULL,repository VARCHAR(160) NOT NULL,branch VARCHAR(200) NOT NULL,state VARCHAR(16) NOT NULL,schema_at VARCHAR(32) NULL,ready_at VARCHAR(19) NULL,manual_requested INT NOT NULL DEFAULT 0,commit_sha VARCHAR(64) NULL,last_error TEXT NOT NULL,next_attempt_at VARCHAR(19) NULL)'.$suffix);
  $this->db->exec('CREATE TABLE IF NOT EXISTS eg_index_git_deployments (id VARCHAR(64) PRIMARY KEY,repository VARCHAR(160) NOT NULL,branch VARCHAR(200) NOT NULL,commit_sha VARCHAR(64) NOT NULL,pushed_at VARCHAR(19) NOT NULL,state VARCHAR(16) NOT NULL,detail TEXT NOT NULL,deployment_id VARCHAR(30) NULL,looked_up_at VARCHAR(19) NULL,checked_at VARCHAR(19) NULL,next_check_at VARCHAR(19) NULL,processed_at VARCHAR(19) NULL)'.$suffix);
 }
 public function connection():array {
  $raw=$this->query('SELECT value FROM eg_settings WHERE name=?',['github_connect'])->fetchColumn();$c=json_decode($raw?:'{}',true)?:[];
  return ['repository'=>(string)($c['repository']??''),'branch'=>(string)($c['branch']??'main')];
 }
 public function ensure(array $batch,array $urls,int $offset):array {
  $json=json_encode($urls,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);$id=hash('sha256',$batch['id'].':'.$offset.':'.$json);$c=$this->connection();$row=$this->get($id);
  if(!$row){$this->query('INSERT INTO eg_index_git_preparations (id,batch_id,start_offset,urls_json,repository,branch,state,last_error) VALUES (?,?,?,?,?,?,?,?)',[$id,$batch['id'],$offset,$json,$c['repository'],$c['branch'],'pending','']);}
  elseif($row['repository']!==$c['repository']||$row['branch']!==$c['branch']||($row['state']==='ready'&&strtotime($row['ready_at'].' UTC')+300<$this->now())){
   // Refresh stale timestamp edits or edits made against a different connection.
   $this->query('UPDATE eg_index_git_preparations SET repository=?,branch=?,state=?,schema_at=NULL,ready_at=NULL,commit_sha=NULL,last_error=?,next_attempt_at=NULL WHERE id=?',[$c['repository'],$c['branch'],'pending','',$id]);
  }
  return $this->get($id);
 }
 public function get(string $id):?array{return $this->query('SELECT * FROM eg_index_git_preparations WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC)?:null;}
 public function started(string $id):array {
  $stamp=(new DateTimeImmutable('@'.$this->now()))->setTimezone(new DateTimeZone('-05:00'))->format('Y-m-d\TH:i:sP');
  $this->query('UPDATE eg_index_git_preparations SET state=\'pushing\',schema_at=CASE WHEN schema_at IS NULL OR schema_at<? THEN ? ELSE schema_at END,last_error=? WHERE id=?',[(new DateTimeImmutable('@'.($this->now()-600)))->setTimezone(new DateTimeZone('-05:00'))->format('Y-m-d\TH:i:sP'),$stamp,'',$id]);return $this->get($id);
 }
 public function ready(string $id,string $commit,?string $expected=null):void {$this->query('UPDATE eg_index_git_preparations SET state=?,commit_sha=?,ready_at=?,last_error=?,next_attempt_at=NULL WHERE id=?'.($expected!==null?' AND schema_at=?':''),array_merge(['ready',$commit,gmdate('Y-m-d H:i:s',$this->now()),'',$id],$expected!==null?[$expected]:[]));}
 public function failed(string $id,string $message,?string $expected=null):void {$this->query('UPDATE eg_index_git_preparations SET state=?,last_error=?,next_attempt_at=? WHERE id=?'.($expected!==null?' AND schema_at=?':''),array_merge(['error',mb_substr($message,0,2000),gmdate('Y-m-d H:i:s',$this->now()+60),$id],$expected!==null?[$expected]:[]));}
 private function local(?string $stamp):string{return $stamp?(new DateTimeImmutable($stamp,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Dhaka'))->format('d M, g:i:sa').' Bangladesh':'';}
 private function entry(array $p):array {
  $state=match($p['state']){'ready'=>'pushed','pushing'=>'pushing','error'=>'error',default=>'queued'};
  $detail=$p['last_error']!==''?$p['last_error']:'';$processed=null;
  if($state==='pushed'){
   $d=$this->query('SELECT * FROM eg_index_git_deployments WHERE id=?',[GitDeploymentStatus::identity($p['repository'],$p['branch'],$p['commit_sha']??'')])->fetch(PDO::FETCH_ASSOC);
   if($d){$state=match($d['state']){'processed'=>'processed','processing'=>'processing','failed'=>'failed',default=>'pushed'};$detail=$d['detail'];$processed=$d['processed_at'];}
   else $detail='Waiting for GitHub Pages deployment status.';
  }
  $label=match($state){'pushed'=>'Pushed to GitHub','pushing'=>'Pushing to GitHub','processed'=>'Processed by GitHub','processing'=>'Processing by GitHub','error'=>'GitHub push failed','failed'=>'GitHub deployment failed',default=>'Queued for GitHub'};
  $urls=json_decode($p['urls_json'],true)?:[];$parts=[count($urls).' URLs'];if($p['ready_at'])$parts[]='Pushed '.$this->local($p['ready_at']);if($processed)$parts[]='Processed '.$this->local($processed);if($detail!=='')$parts[]=$detail;
  $url='';if(preg_match('~^[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9_.-]+$~D',$p['repository'])&&preg_match('/^[a-f0-9]{40,64}$/D',$p['commit_sha']??''))$url='https://github.com/'.$p['repository'].'/commit/'.$p['commit_sha'];
  return ['state'=>$state,'label'=>$label,'detail'=>implode(' · ',$parts),'commit_url'=>$url,'offset'=>(int)$p['start_offset']];
 }
 public function progress(string $batch):array {
  $b=$this->query('SELECT accepted,total,mode,next_run_at,paused,urls_json,per_submit FROM eg_index_batches WHERE id=?',[$batch])->fetch(PDO::FETCH_ASSOC);
  $enabled=$this->query('SELECT value FROM eg_index_control WHERE name=?',['github_time_enabled'])->fetchColumn()==='1';$heartbeat=(int)$this->query('SELECT value FROM eg_index_control WHERE name=?',['github_time_heartbeat'])->fetchColumn();$online=$enabled&&$heartbeat>0&&$this->now()-$heartbeat<=300;
  $rows=$this->query("SELECT * FROM eg_index_git_preparations WHERE batch_id=? ORDER BY COALESCE(ready_at,'') DESC,COALESCE(schema_at,'') DESC,start_offset DESC LIMIT 12",[$batch])->fetchAll(PDO::FETCH_ASSOC);$history=array_map(fn($p)=>$this->entry($p),$rows);$active=null;
  if($b&&(int)$b['accepted']<(int)$b['total']){
   $request=$this->query('SELECT request_json FROM eg_index_submissions WHERE batch_id=? AND start_offset=?',[$batch,$b['accepted']])->fetchColumn();$urls=$request?json_decode($request,true,512,JSON_THROW_ON_ERROR)['urls']:array_slice(json_decode($b['urls_json'],true,512,JSON_THROW_ON_ERROR),(int)$b['accepted'],(int)$b['per_submit']);
   $id=hash('sha256',$batch.':'.$b['accepted'].':'.json_encode($urls,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));$current=$this->get($id);
   if($current){$active=$this->entry($current);if(!in_array($id,array_column($rows,'id'),true))array_unshift($history,$active);}
  }else $active=$history[0]??null;
  if(!$active){$detail='';if($b&&$b['mode']==='automatic'&&$b['next_run_at']&&empty($b['paused']))$detail='Push planned '.$this->local(gmdate('Y-m-d H:i:s',strtotime($b['next_run_at'].' UTC')-180));$active=['state'=>'waiting','label'=>$enabled?'Waiting for next batch':'GitHub Worker Disabled','detail'=>$detail,'commit_url'=>''];}
  return $active+['worker'=>$online?'Online':'Offline','history'=>$history];
 }
 public function summary(array $p):array {
  $label=$p['state']==='ready'?'GitHub timestamps updated · '.(new DateTimeImmutable($p['ready_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Dhaka'))->format('g:i:sa').' Bangladesh':($p['last_error']!==''?'GitHub timestamp update failed: '.$p['last_error']:'GitHub timestamp update queued');
  return ['github_time_label'=>$label];
 }
}
