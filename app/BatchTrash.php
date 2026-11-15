<?php
declare(strict_types=1);
final class BatchTrash {
 public function __construct(private PDO $db,private string $storage){}
 private function query(string $sql,array $args=[]):PDOStatement {$q=$this->db->prepare($sql);$q->execute($args);return $q;}
 public function items(int $user,int $page=1):array {
  $from=" FROM eg_jobs j JOIN eg_settings s ON s.name=CONCAT('hidden_job_',j.id) WHERE j.user_id=?";
  $total=(int)$this->query('SELECT COUNT(*)'.$from,[$user])->fetchColumn();$pages=max(1,(int)ceil($total/50));$page=max(1,min($page,$pages));
  $jobs=$this->query('SELECT j.*,s.value AS cleared_at'.$from." ORDER BY CASE WHEN s.value='1' THEN j.created_at ELSE s.value END DESC,j.created_at DESC,j.id DESC LIMIT 50 OFFSET ".(($page-1)*50),[$user])->fetchAll(PDO::FETCH_ASSOC);
  return ['jobs'=>$jobs,'total'=>$total,'page'=>$page,'pages'=>$pages];
 }
 public function empty(int $user):array {
  $ids=$this->query("SELECT j.id FROM eg_jobs j JOIN eg_settings s ON s.name=CONCAT('hidden_job_',j.id) WHERE j.user_id=?",[$user])->fetchAll(PDO::FETCH_COLUMN);
  $deleted=0;$skipped=0;
  foreach($ids as $id){try{$this->act($user,$id,'delete');++$deleted;}catch(PDOException $e){throw $e;}catch(InvalidArgumentException|RuntimeException $e){++$skipped;}}
  return ['deleted'=>$deleted,'skipped'=>$skipped];
 }
 private static function remove(string $path):void {
  if(is_link($path)||!is_dir($path)){if((file_exists($path)||is_link($path))&&!unlink($path))throw new RuntimeException('Could not remove all batch files. Check storage permissions and retry.');return;}
  $entries=scandir($path);if($entries===false)throw new RuntimeException('Could not read batch storage.');
  foreach($entries as $name)if($name!=='.'&&$name!=='..')self::remove($path.'/'.$name);
  if(!rmdir($path))throw new RuntimeException('Could not remove batch storage. Check permissions and retry.');
 }
 public function act(int $user,string $id,string $action):void {
  if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!in_array($action,['trash','restore','delete'],true))throw new InvalidArgumentException('Choose a valid batch and action.');
  $dir=$this->storage.'/'.$id;$locks=[];$detached=null;
  $this->db->beginTransaction();
  try{
   if(!$this->query('SELECT id FROM eg_jobs WHERE id=? AND user_id=? FOR UPDATE',[$id,$user])->fetchColumn())throw new InvalidArgumentException('Batch not found.');
   $hidden=(bool)$this->query('SELECT name FROM eg_settings WHERE name=?',['hidden_job_'.$id])->fetchColumn();
   if($action==='trash'){$this->query('INSERT IGNORE INTO eg_settings (name,value) VALUES (?,?)',['hidden_job_'.$id,gmdate('Y-m-d H:i:s')]);}
   else {
    if(!$hidden)throw new InvalidArgumentException('Move this batch to Trash first.');
    if($action==='restore'){
     if(!is_dir($dir)||is_link($dir))throw new RuntimeException('The batch files are unavailable on this server.');
     $this->query('DELETE FROM eg_settings WHERE name=?',['hidden_job_'.$id]);
    }else{
     if(is_dir($dir)&&!is_link($dir)){
      foreach(['batch.lock','git-upload.lock'] as $name){if(is_link($dir.'/'.$name))throw new RuntimeException('Invalid batch lock file.');$lock=fopen($dir.'/'.$name,'c');if(!$lock)throw new RuntimeException('Could not lock batch storage.');$locks[]=$lock;if(!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('This batch is processing, downloading or uploading. Wait for it to finish before deleting.');}
      // Detach atomically: stale requests cannot open fresh locks in this directory.
      $detached=$this->storage.'/.deleting-'.$id.'-'.bin2hex(random_bytes(8));
      if(!rename($dir,$detached))throw new RuntimeException('Could not prepare batch storage for deletion.');
      self::remove($detached);$detached=null;
     }elseif(is_link($dir)){self::remove($dir);}
     // Usage reservations and copied Indexing queues intentionally remain separate.
     $this->query('DELETE FROM eg_jobs WHERE id=? AND user_id=?',[$id,$user]);
     $this->query('DELETE FROM eg_settings WHERE name=?',['hidden_job_'.$id]);
    }
   }
   $this->db->commit();
  }catch(Throwable $e){
   if($this->db->inTransaction())$this->db->rollBack();
   if($detached!==null&&is_dir($detached)&&!file_exists($dir))@rename($detached,$dir);
   throw $e;
  }finally{foreach($locks as $lock){flock($lock,LOCK_UN);fclose($lock);}}
 }
}
function batchTrashPage():never {
 require_once __DIR__.'/IndexingQueue.php';$queue=new IndexingQueue(db(),ROOT.'/storage/indexing-locks');$queue->ensureSchema();
 $trash=new BatchTrash(db(),ROOT.'/storage');$user=(int)$_SESSION['user_id'];$notice='';
 if(isset($_GET['index_urls'])){$id=is_string($_GET['index_urls'])?$_GET['index_urls']:'';if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Choose a deleted Indexing batch.');$text=$queue->trashUrls($user,$id);if(session_status()===PHP_SESSION_ACTIVE)session_write_close();header('Content-Type: text/plain; charset=utf-8');header('Content-Disposition: attachment; filename="deleted-indexing-urls.txt"');header('Content-Length: '.strlen($text));echo $text;exit;}
 if($_SERVER['REQUEST_METHOD']==='POST'){
  verifyCsrf();if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
  try{$action=is_string($_POST['action']??null)?$_POST['action']:'';$id=is_string($_POST['id']??null)?$_POST['id']:'';
   if(in_array($action,['index_restore','index_delete'],true)){if($action==='index_delete'){if(($_POST['confirm']??'')!==$id)throw new InvalidArgumentException('Confirm permanent deletion first.');$queue->purgeTrash($user,$id);}else{$queue->restoreTrash($user,$id);$restored=$queue->get($user,$id);$jobId=$restored['job_id'];$q=db()->prepare("SELECT j.id FROM eg_jobs j JOIN eg_settings s ON s.name=CONCAT('hidden_job_',j.id) WHERE j.id=? AND j.user_id=?");$q->execute([$jobId,$user]);if($q->fetchColumn()&&preg_match('/^[a-f0-9]{32}$/D',$jobId)&&is_dir(ROOT.'/storage/'.$jobId)&&!is_link(ROOT.'/storage/'.$jobId))$trash->act($user,$jobId,'restore');}header('Location: /trash?done='.$action);exit;}
   if($action==='empty'){if(($_POST['confirm']??'')!=='empty_trash')throw new InvalidArgumentException('Confirm Empty Trash first.');@set_time_limit(300);$result=$trash->empty($user);$indexResult=$queue->emptyTrash($user);$result['deleted']+=$indexResult['deleted'];$result['skipped']+=$indexResult['skipped'];header('Location: /trash?done=empty&deleted='.$result['deleted'].'&skipped='.$result['skipped']);exit;}
   if($action==='delete'&&($_POST['confirm']??'')!==$id)throw new InvalidArgumentException('Confirm permanent deletion first.');
   $trash->act($user,$id,$action);header('Location: /trash?done='.$action);exit;
  }catch(InvalidArgumentException|RuntimeException $e){$notice=message($e instanceof PDOException?'Could not update Trash. Please retry.':$e->getMessage());}
 }
 $done=$_GET['done']??'';if(in_array($done,['trash','restore','delete'],true))$notice.=message(match($done){'trash'=>'Batch moved to Trash.','restore'=>'Batch restored to Recent Batches.',default=>'Batch files permanently deleted from this server.'},'success');
 if($done==='empty')$notice.=message(max(0,(int)($_GET['deleted']??0)).' batches permanently deleted. '.max(0,(int)($_GET['skipped']??0)).' batches could not be deleted.','success');
 if($done==='index_restore')$notice.=message('Indexing batch restored. Automatic submissions remain paused until you resume it.','success');
 if($done==='index_delete')$notice.=message('Indexing batch and submission history permanently deleted. Generated downloads are managed separately below.','success');
 $indexData=$queue->trashed($user,max(1,(int)($_GET['index_page']??1)));
 $data=$trash->items($user,max(1,(int)($_GET['page']??1)));$intro=$notice;
 if(($_GET['empty']??'')==='1'&&($data['total']+$indexData['total'])>0)$intro.='<section class="panel"><form method="post" class="stack">'.tokenField().'<input type="hidden" name="action" value="empty"><input type="hidden" name="confirm" value="empty_trash"><strong>Permanently delete all '.number_format($data['total']+$indexData['total']).' entries in Trash from this server?</strong><p>GitHub files will remain unchanged.</p><div class="description-actions"><button class="primary">Yes, Empty Trash</button><a class="button secondary" href="/trash">Cancel</a></div></form></section>';
 $body='<section class="panel"><div class="history-heading"><h2>Generated Files · '.number_format($data['total']).'</h2><div class="description-actions"><a class="button secondary" href="/">Back to Generator</a></div></div><p class="hint">Download or restore cleared batches. Permanent deletion removes generated files from this server only. GitHub files are unchanged.</p><div class="table-wrap"><table><thead><tr><th>Event</th><th>Progress</th><th>Cleared (UTC)</th><th>Downloads</th><th>Actions</th></tr></thead><tbody>';
 foreach($data['jobs'] as $job){
  $id=$job['id'];if(!preg_match('/^[a-f0-9]{32}$/D',$id))continue;$dir=ROOT.'/storage/'.$id;$links='';
  foreach(['zip'=>['html.zip','HTML ZIP'],'txt'=>['urls.txt','URLs TXT']] as $type=>[$file,$label])if($job['status']==='complete'&&is_file($dir.'/'.$file))$links.='<a href="/download?id='.h($id).'&amp;type='.$type.'">'.$label.'</a> ';
  $actions='<form method="post" class="description-actions">'.tokenField().'<input type="hidden" name="id" value="'.h($id).'"><button type="submit" class="secondary" name="action" value="restore">Restore</button><a class="button secondary" href="/trash?delete='.h($id).'&amp;page='.$data['page'].'">Delete Permanently</a></form>';
  if(($_GET['delete']??'')===$id)$actions='<form method="post" class="stack">'.tokenField().'<input type="hidden" name="id" value="'.h($id).'"><input type="hidden" name="action" value="delete"><input type="hidden" name="confirm" value="'.h($id).'"><strong>Delete this batch and its ZIP/URLs permanently?</strong><button class="primary">Yes, Delete Permanently</button><a href="/trash?page='.$data['page'].'">Cancel</a></form>';
  $body.='<tr><td>'.h($job['event']).'</td><td>'.(int)$job['completed'].' / '.(int)$job['total'].'</td><td>'.h($job['cleared_at']==='1'?'Not recorded':$job['cleared_at']).'</td><td>'.($links?:'Files unavailable').'</td><td>'.$actions.'</td></tr>';
 }
 if(!$data['jobs'])$body.='<tr><td colspan="5" class="hint">Trash is empty.</td></tr>';
 $body.='</tbody></table></div><div class="description-actions">';
 if($data['page']>1)$body.='<a class="button secondary" href="/trash?page='.($data['page']-1).'">Previous</a>';
 if($data['page']<$data['pages'])$body.='<a class="button secondary" href="/trash?page='.($data['page']+1).'">Next</a>';
 $body.='</div></section>';page('Trash','<div class="history-heading"><h1>Trash</h1>'.($data['total']+$indexData['total']>0?'<a class="button secondary" href="/trash?empty=1">Empty Trash</a>':'').'</div>'.$intro.indexingTrashHtml($queue,$user,$indexData).$body);exit;
}
function indexingTrashHtml(IndexingQueue $queue,int $user,array $data):string {
 $body='<section class="panel"><div class="history-heading"><h2>Indexing Batches · '.number_format($data['total']).'</h2><a class="button secondary" href="/indexing">Back to Indexing</a></div><p class="hint">Restore keeps submission history and pauses automatic submissions. Generated downloads are listed separately below.</p><div class="table-wrap"><table><thead><tr><th>Event</th><th>Accepted / Total</th><th>Deleted (UTC)</th><th>Downloads</th><th>Actions</th></tr></thead><tbody>';
 foreach($data['jobs'] as $job){
  $id=$job['id'];$links='<a href="/trash?index_urls='.h($id).'">Indexing URLs TXT</a>';
  $q=db()->prepare('SELECT id FROM eg_jobs WHERE id=? AND user_id=? AND status=?');$q->execute([$job['job_id'],$user,'complete']);
  if($q->fetchColumn()&&preg_match('/^[a-f0-9]{32}$/D',$job['job_id'])&&is_file(ROOT.'/storage/'.$job['job_id'].'/html.zip'))$links.=' <a href="/download?id='.h($job['job_id']).'&amp;type=zip">HTML ZIP</a>';
  $actions='<form method="post" class="description-actions">'.tokenField().'<input type="hidden" name="id" value="'.h($id).'"><button class="secondary" name="action" value="index_restore">Restore</button><a class="button secondary" href="/trash?index_delete='.h($id).'&amp;index_page='.$data['page'].'">Delete Permanently</a></form>';
  if(($_GET['index_delete']??'')===$id)$actions='<form method="post" class="stack">'.tokenField().'<input type="hidden" name="id" value="'.h($id).'"><input type="hidden" name="action" value="index_delete"><input type="hidden" name="confirm" value="'.h($id).'"><strong>Permanently delete this Indexing batch and submission history?</strong><button class="primary">Yes, Delete Permanently</button><a href="/trash">Cancel</a></form>';
  $name=$job['event'].((int)($job['name_number']??1)>1?' #'.(int)$job['name_number']:'');
  $body.='<tr><td>'.h($name).'</td><td>'.(int)$job['accepted'].' / '.(int)$job['total'].'</td><td>'.h($job['deleted_at']).'</td><td>'.$links.'</td><td>'.$actions.'</td></tr>';
 }
 if(!$data['jobs'])$body.='<tr><td colspan="5" class="hint">No deleted Indexing batches.</td></tr>';
 $body.='</tbody></table></div><div class="description-actions">';
 if($data['page']>1)$body.='<a class="button secondary" href="/trash?index_page='.($data['page']-1).'">Previous</a>';
 if($data['page']<$data['pages'])$body.='<a class="button secondary" href="/trash?index_page='.($data['page']+1).'">Next</a>';
 return $body.'</div></section>';
}
