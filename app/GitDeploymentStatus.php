<?php
declare(strict_types=1);
require_once __DIR__.'/GitHubConnect.php';
/** Read-only deployment observations. Never consulted by the IndexBear dispatcher. */
final class GitDeploymentStatus {
 public function __construct(private PDO $db,private ?Closure $clock=null,private ?Closure $transport=null){}
 private function now():int{return $this->clock?($this->clock)():time();}
 private function query(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
 public static function identity(string $repo,string $branch,string $commit):string{return hash('sha256',$repo.':'.$branch.':'.$commit);}
 public function register(string $repo,string $branch,string $sha,string $pushed):void {
  GitHubConnect::repository('https://github.com/'.$repo);GitHubConnect::branch($branch);if(!preg_match('/^[a-f0-9]{40,64}$/D',$sha))return;
  $id=self::identity($repo,$branch,$sha);if($this->query('SELECT id FROM eg_index_git_deployments WHERE id=?',[$id])->fetchColumn())return;
  try{$this->query('INSERT INTO eg_index_git_deployments (id,repository,branch,commit_sha,pushed_at,state,detail,next_check_at) VALUES (?,?,?,?,?,?,?,?)',[$id,$repo,$branch,$sha,$pushed,'pushed','Waiting for GitHub Pages deployment status.',gmdate('Y-m-d H:i:s',$this->now())]);}
  catch(PDOException $e){if(!in_array((string)$e->getCode(),['23000','23505'],true))throw $e;}
 }
 private function control(string $name,?string $value=null):string {
  $old=$this->query('SELECT value FROM eg_index_control WHERE name=?',[$name])->fetchColumn();
  if($value!==null){if($old===false)$this->query('INSERT INTO eg_index_control (name,value) VALUES (?,?)',[$name,$value]);else $this->query('UPDATE eg_index_control SET value=? WHERE name=?',[$value,$name]);return $value;}return $old===false?'':(string)$old;
 }
 private function request(string $path):array {
  if($this->transport)return ($this->transport)($path);
  if(!function_exists('curl_init'))throw new RuntimeException('Enable PHP cURL to read GitHub deployment status.');
  $body='';$headers=[];$c=curl_init('https://api.github.com'.$path);
  curl_setopt_array($c,[CURLOPT_USERAGENT=>'EventForge-Deployment-Monitor',CURLOPT_HTTPHEADER=>['Accept: application/vnd.github+json','X-GitHub-Api-Version: 2022-11-28'],CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>6,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($c,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>524288)return 0;$body.=$chunk;return strlen($chunk);},CURLOPT_HEADERFUNCTION=>static function($c,$line)use(&$headers){$pair=explode(':',$line,2);if(count($pair)===2)$headers[strtolower(trim($pair[0]))]=trim($pair[1]);return strlen($line);}]);
  $ok=curl_exec($c);$http=(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return ['http'=>$ok===false?0:$http,'data'=>json_decode($body,true),'headers'=>$headers];
 }
 private function save(array $d,string $state,string $detail,int $delay=120,?string $processed=null):void {
  $this->query('UPDATE eg_index_git_deployments SET state=?,detail=?,checked_at=?,next_check_at=?,processed_at=? WHERE id=?',[$state,$detail,gmdate('Y-m-d H:i:s',$this->now()),$state==='processed'?null:gmdate('Y-m-d H:i:s',$this->now()+$delay),$processed,$d['id']]);
 }
 public function poll():void {
  // Register old successful pushes too; a shared commit is polled once for all its grids.
  $pushes=$this->query("SELECT DISTINCT repository,branch,commit_sha,ready_at FROM eg_index_git_preparations WHERE state='ready' AND commit_sha IS NOT NULL AND ready_at IS NOT NULL ORDER BY ready_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
  if($pushes){$ids=array_map(fn($p)=>self::identity($p['repository'],$p['branch'],$p['commit_sha']),$pushes);$known=array_fill_keys($this->query('SELECT id FROM eg_index_git_deployments WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids)->fetchAll(PDO::FETCH_COLUMN),true);
   foreach($pushes as $p){if(isset($known[self::identity($p['repository'],$p['branch'],$p['commit_sha'])]))continue;try{$this->register($p['repository'],$p['branch'],$p['commit_sha'],$p['ready_at']);}catch(InvalidArgumentException $e){/* Ignore invalid legacy metadata. */}}
  }
  if((int)$this->control('github_deploy_request_after')>$this->now())return;
  $d=$this->query("SELECT * FROM eg_index_git_deployments WHERE state<>'processed' AND next_check_at<=? ORDER BY next_check_at,id LIMIT 1",[gmdate('Y-m-d H:i:s',$this->now())])->fetch(PDO::FETCH_ASSOC);if(!$d)return;
  $this->control('github_deploy_request_after',(string)($this->now()+75));
  $discover=!$d['deployment_id']||$d['state']==='failed'||!$d['looked_up_at']||strtotime($d['looked_up_at'].' UTC')+300<=$this->now();
  $base='/repos/'.$d['repository'].'/deployments';$path=!$discover?$base.'/'.rawurlencode($d['deployment_id']).'/statuses?per_page=10':$base.'?'.http_build_query(['sha'=>$d['commit_sha'],'environment'=>'github-pages','per_page'=>10]);
  try{$r=$this->request($path);}catch(Throwable $e){$this->save($d,'unavailable','Deployment status unavailable. The Git push remains successful.',300);return;}
  $headers=$r['headers']??[];$http=(int)($r['http']??0);$rows=$r['data']??null;
  if(isset($headers['x-ratelimit-remaining'])&&(int)$headers['x-ratelimit-remaining']<=10){$reset=(int)($headers['x-ratelimit-reset']??0);$this->control('github_deploy_request_after',(string)max($this->now()+75,$reset+5));}
  if($http===403||$http===429){$delay=max(120,(int)($headers['retry-after']??0),(int)($headers['x-ratelimit-reset']??0)-$this->now());$this->control('github_deploy_request_after',(string)($this->now()+$delay));$this->save($d,'unavailable','Deployment status unavailable: GitHub denied or rate-limited the check. Retrying automatically.',$delay);return;}
  if($http===401||$http===404){$this->save($d,'unavailable','Deployment status unavailable through the public API. Private repositories require GitHub API read access.',900);return;}
  if($http!==200||!is_array($rows)||!array_is_list($rows)){$this->save($d,'unavailable','Deployment status unavailable. GitHub HTTP '.$http.'. Retrying automatically.',300);return;}
  if($discover){
   foreach($rows as $row)if(($row['sha']??'')===$d['commit_sha']&&($row['environment']??'')==='github-pages'&&preg_match('/^[0-9]+$/D',(string)($row['id']??''))){
    $this->query('UPDATE eg_index_git_deployments SET deployment_id=?,looked_up_at=? WHERE id=?',[(string)$row['id'],gmdate('Y-m-d H:i:s',$this->now()),$d['id']]);$this->save($d,'processing','GitHub Pages deployment created; checking its result.',75);return;
   }
   $this->save($d,'pushed','Pushed successfully. No Pages deployment has been reported for this commit yet.',180);return;
  }
  if(!$rows){$this->save($d,'processing','GitHub has not reported a deployment result yet.');return;}
  $status=$rows[0];$state=$status['state']??'';
  // An inactive deployment may have succeeded earlier before a newer deployment replaced it.
  if($state==='inactive')foreach($rows as $prior)if(($prior['state']??'')==='success'){$status=$prior;$state='success';break;}
  if($state==='success'){
   $at=$status['created_at']??'';$processed=null;if(is_string($at)&&preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D',$at)){try{$processed=(new DateTimeImmutable($at))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}catch(Exception $e){}}
   $this->save($d,'processed','GitHub confirmed a successful Pages deployment.',0,$processed);return;
  }
  if(in_array($state,['error','failure'],true)){$this->save($d,'failed','GitHub reported a failed Pages deployment. Check its Actions page.',300);return;}
  if(in_array($state,['queued','pending','in_progress'],true)){$this->save($d,'processing','GitHub Pages deployment is '.str_replace('_',' ',$state).'.');return;}
  $this->save($d,'pushed','Pushed successfully; GitHub has not confirmed a successful deployment.');
 }
}
