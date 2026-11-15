<?php
declare(strict_types=1);
require_once __DIR__.'/IndexingProviders.php';
function indexingBalanceLabel(array $meta):string {return 'Connected · Balance: '.intdiv(max(0,(int)($meta['balance']??0)),max(1,(int)($meta['cost']??10))).' URLs Remaining';}
function indexingProjectionLabel(array $meta,array $batches):string {
 $pending=0;foreach($batches as $b)if(($b['provider']??'indexbear')===($meta['provider']??'indexbear'))$pending+=max(0,(int)$b['remaining']);$cost=(int)($meta['cost']??10);if($cost<1)$cost=10;
 $required=$pending*$cost;$balance=max(0,(int)($meta['balance']??0));$after=$balance-$required;
 return 'Pending: '.$pending.' URLs · Required: '.$required." credits\n".($after>=0?'Estimated after pending: '.$after.' credits · '.intdiv($after,$cost).' URLs remaining':'Shortfall: '.(-$after).' credits · '.(int)ceil(-$after/$cost).' URLs over balance');
}
function indexingTime(?string $utc):string{return $utc?(new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Dhaka'))->format('d M Y, H:i'):'—';}
function indexingReply(array $data):never {
 if(!str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')){header('Location: /indexing',true,303);exit;}jsonOut($data);
}
function indexingRefreshData(IndexingQueue $queue,int $user):array {
 // Browser polling must never wait for a provider response. The CLI worker
 // owns provider status polling; the page only reads its persisted results.
 return ['batches'=>indexingViewData($queue->listing($user),(int)$queue->control('cooldown_until')),'worker'=>indexingWorkerState($queue)];
}
function indexingApi(string $path):never {
 $user=(int)$_SESSION['user_id'];
 // Authentication and CSRF were checked by the router. External API calls
 // must not hold this user's session lock and block Save or other requests.
 if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
 if($path==='/api/indexing-worker-status')indexingReply(['worker'=>indexingHeaderWorker()]);
 $provider=$_POST['provider']??'indexbear';if(!is_string($provider))throw new InvalidArgumentException('Choose an indexing provider.');indexingProviderName($provider);
 $queue=indexingQueue($provider);
 if(!in_array($path,['/api/indexing-balance','/api/indexing-connection','/api/indexing-refresh','/api/indexing-move'],true)){
  $id=$_POST['id']??'';if(!is_string($id))throw new InvalidArgumentException('Choose an Indexing batch.');$batch=$queue->get($user,$id);$provider=$batch['provider'];$queue=indexingQueue($provider);
 }
 if($path==='/api/indexing-balance'){
  $saved=json_decode(setting($provider.'_connection','{}'),true)?:[];$snapshot=$queue->cachedBalance();
  if($snapshot&&strtotime($snapshot['checked_at'])>=strtotime($saved['checked_at']??'1970-01-01'))$saved=$snapshot;
  indexingReply(['connection'=>$saved?:null]);
 }
 if($path==='/api/indexing-connection'){
  $input=$_POST['api_key']??'';if(!is_string($input))throw new InvalidArgumentException('Enter your '.indexingProviderName($provider).' API key.');$input=trim($input);
  if($input!==''&&(strlen($input)<10||strlen($input)>512||preg_match('/\s/',$input)))throw new InvalidArgumentException('Enter a valid '.indexingProviderName($provider).' API key.');
  $saved=setting($provider.'_api_key');if($input===''&&$saved==='')throw new InvalidArgumentException('Enter your '.indexingProviderName($provider).' API key.');
  $api=($provider==='rocketindexer'?new RocketIndexerClient($input!==''?$input:cryptKey($saved,true)):new IndexBearClient($input!==''?$input:cryptKey($saved,true)));$balance=$api->balance();
  $meta=['balance'=>(int)($balance['balance']??0),'cost'=>(int)($balance['creditCosts']['google_instant']??10),'checked_at'=>gmdate('c')];
  db()->beginTransaction();try{if($input!=='')saveSetting($provider.'_api_key',cryptKey($input));saveSetting($provider.'_connection',json_encode($meta,JSON_THROW_ON_ERROR));db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
  indexingReply(['connection'=>$meta]);
 }
 if($path==='/api/indexing-move'){
  $id=$_POST['id']??'';if(!is_string($id))throw new InvalidArgumentException('Choose a completed batch.');$dir=jobDir($id);
  $q=db()->prepare('SELECT event FROM eg_jobs WHERE id=? AND user_id=? AND status=?');$q->execute([$id,$user,'complete']);$job=$q->fetch();if(!$job)throw new InvalidArgumentException('Completed batch not found.');
  if(!is_file($dir.'/urls.txt'))throw new InvalidArgumentException('The URL download is unavailable. Generate this batch again.');
  $urls=IndexingQueue::urls(file_get_contents($dir.'/urls.txt'));$batch=$queue->move($user,$id,$job['event'],$urls);indexingReply(['batch'=>$batch]);
 }
 if($path==='/api/indexing-refresh'){
  indexingReply(['batches'=>indexingViewData(indexingAllBatches($user)),'worker'=>indexingWorkerState($queue)]);
 }
 if($path==='/api/indexing-delete'){$id=$_POST['id']??'';if(!is_string($id))throw new InvalidArgumentException('Choose an Indexing batch.');$queue->delete($user,$id);$q=db()->prepare("INSERT IGNORE INTO eg_settings (name,value) SELECT CONCAT('hidden_job_',id),? FROM eg_jobs WHERE id=? AND user_id=?");$q->execute([gmdate('Y-m-d H:i:s'),$batch['job_id'],$user]);indexingReply(['deleted'=>$id]);}
 if($path==='/api/indexing-download-urls'){
  $id=$_POST['id']??'';if(!is_string($id))throw new InvalidArgumentException('Choose an Indexing batch.');$text=$queue->urlText($user,$id);
  header('Content-Type: text/plain; charset=utf-8');header('Content-Disposition: attachment; filename="indexing-urls.txt"');header('Cache-Control: no-store');header('Content-Length: '.strlen($text));echo $text;exit;
 }
 if($path==='/api/indexing-add-domains'){
  $id=$_POST['id']??'';$domains=$_POST['new_domains']??'';if(!is_string($id)||!is_string($domains))throw new InvalidArgumentException('Enter the new domains, one per line.');
  $out=$queue->addDomains($user,$id,$domains);$out['batch']=indexingViewData([$out['batch']],(int)$queue->control('cooldown_until'))[0];indexingReply($out);
 }
 if($path==='/api/indexing-remove-domain'){
  $id=$_POST['id']??'';$domain=$_POST['domain']??'';if(!is_string($id)||!is_string($domain)||$domain==='')throw new InvalidArgumentException('Choose the domain to remove.');
  $out=$queue->removeDomain($user,$id,$domain);$out['batch']=indexingViewData([$out['batch']],(int)$queue->control('cooldown_until'))[0];indexingReply($out);
 }
 if($path==='/api/indexing-reduce-domain'){
  $id=$_POST['id']??'';$domain=$_POST['domain']??'';$count=$_POST['remove_count']??'';
  if(!is_string($id)||!is_string($domain)||$domain===''||!is_string($count)||!preg_match('/^[1-9][0-9]{0,3}$/D',$count))throw new InvalidArgumentException('Choose a domain and enter a whole number of pending URLs to remove.');
  $out=$queue->reduce($user,$id,(int)$count,$domain);$out['batch']=indexingViewData([$out['batch']],(int)$queue->control('cooldown_until'))[0];indexingReply($out);
 }
 if($path==='/api/indexing-pause'){
  $id=$_POST['id']??'';$paused=$_POST['paused']??'';if(!is_string($id)||!in_array($paused,['0','1'],true))throw new InvalidArgumentException('Choose Pause or Resume.');
  indexingReply(['batch'=>indexingViewData([$queue->setPaused($user,$id,$paused==='1')],(int)$queue->control('cooldown_until'))[0]]);
 }
 if($path==='/api/indexing-reduce'){
  $id=$_POST['id']??'';$count=$_POST['remove_count']??'';if(!is_string($id)||!is_string($count)||!preg_match('/^[1-9][0-9]{0,3}$/D',$count))throw new InvalidArgumentException('Enter a whole number of pending URLs to remove.');
  $out=$queue->reduce($user,$id,(int)$count);$out['batch']=indexingViewData([$out['batch']],(int)$queue->control('cooldown_until'))[0];indexingReply($out);
 }
 if($path==='/api/indexing-randomize'){
  $id=$_POST['id']??'';if(!is_string($id))throw new InvalidArgumentException('Choose an Indexing batch.');
  $out=$queue->randomize($user,$id);$out['batch']=indexingViewData([$out['batch']],(int)$queue->control('cooldown_until'))[0];indexingReply($out);
 }
 if($path==='/api/indexing-replace-domain'){
  $id=$_POST['id']??'';$old=$_POST['old_domain']??'';$new=$_POST['new_domain']??'';
  if(!is_string($id)||!is_string($old)||!is_string($new))throw new InvalidArgumentException('Choose a domain and enter its replacement.');
  $out=$queue->replaceDomain($user,$id,$old,$new);$out['batch']=indexingViewData([$out['batch']],(int)$queue->control('cooldown_until'))[0];indexingReply($out);
 }
 if($path==='/api/indexing-submit'){
  $id=$_POST['id']??'';if(!is_string($id))throw new InvalidArgumentException('Choose an Indexing batch.');
  indexingReply(['batch'=>indexingViewData([$queue->submitNow($user,$id)],(int)$queue->control('cooldown_until'))[0]]);
 }
 if($path==='/api/indexing-configure'){
  $id=$_POST['id']??'';$mode=$_POST['mode']??'';$start=$_POST['start_time']??'';$date=$_POST['start_date']??'';
  $count=filter_var($_POST['per_submit']??'',FILTER_VALIDATE_INT);$gap=filter_var($_POST['gap_minutes']??'',FILTER_VALIDATE_INT);
  if(!is_string($id)||!is_string($mode)||!is_string($start)||!is_string($date)||$count===false||$gap===false)throw new InvalidArgumentException('Enter the batch mode, start time, URL count and gap.');
  $target=$_POST['provider']??$provider;if(!is_string($target))throw new InvalidArgumentException('Choose an indexing provider.');$label=indexingProviderName($target);
  if($mode==='automatic'&&setting($target.'_api_key')==='')throw new InvalidArgumentException('Connect '.$label.' before enabling Automatic mode.');
  $queue->configure($user,$id,$mode,$start,$count,$gap,$date,$target);
  indexingReply(['batch'=>indexingViewData([$queue->get($user,$id)],(int)indexingQueue($target)->control('cooldown_until'))[0]]);
 }
 throw new InvalidArgumentException('Unknown Indexing action.');
}
function indexingWorkerState(IndexingQueue $queue):array {
 return indexingWorkerSummary((int)$queue->control('worker_heartbeat'));
}
function indexingWorkerSummary(int $stamp):array {
 return ['running'=>$stamp>time()-90,'label'=>$stamp>time()-90?'CLI worker running':'CLI worker offline — Automatic submissions need the worker','last_seen'=>$stamp?indexingTime(gmdate('Y-m-d H:i:s',$stamp)):'Never'];
}
function indexingHeaderWorker(?PDO $source=null):array {
 $stamp=0;
 try{if($source!==null||is_file(ROOT.'/config.php')){$q=($source??db())->prepare('SELECT value FROM eg_index_control WHERE name=?');$q->execute(['worker_heartbeat']);$stamp=(int)$q->fetchColumn();}}catch(PDOException $e){/* Indexing may not have been initialized yet. */}
 return indexingWorkerSummary($stamp);
}
function indexingWorkerBadge(array $worker,?string $token=null):string {
 $label=$worker['running']?'Online':'Offline';return '<span id="indexing-worker-status" class="button secondary indexing-worker-status '.($worker['running']?'is-online':'is-offline').'"'.($token!==null?' data-csrf="'.h($token).'"':'').' role="status" aria-label="Indexing worker: '.$label.'" title="Indexing worker · Last seen: '.h($worker['last_seen']).' (Bangladesh)">'.$label.'</span>';
}
function indexingMilestoneTime(?string $value):string {
 if(!$value)return '';try{return (new DateTimeImmutable($value,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Dhaka'))->format('d M Y, H:i:s');}catch(Exception $e){return '';}
}
function indexingSubmissionProgress(array $s):string {
 if(($s['provider']??'indexbear')==='rocketindexer'){
  $data=json_decode($s['provider_data']??'{}',true)?:[];$label=match($s['status']){'completed'=>'Delivered','processing'=>'In progress','review_required'=>'Needs review',default=>ucwords(str_replace('_',' ',$s['status']))};
  $html='<strong>'.h($label).'</strong>';
  if(!empty($data['tracking_ids']))$html.='<small class="index-sub-id">Tracking IDs: '.h(implode(', ',array_filter($data['tracking_ids'],'is_scalar'))).'</small>';
  if(!empty($data['items'])){$html.='<details class="rocket-url-status"><summary>URL status</summary>';foreach($data['items'] as $item)$html.='<div><span>'.h((string)($item['url']??'')).'</span><small>'.h((string)($item['status']??'')).' · '.h((string)($item['indexed_status']??'pending')).'</small></div>';$html.='</details>';}
  return $html;
 }

 $timeline=json_decode($s['provider_timeline']??'{}',true)?:[];$status=$s['status'];
 $label=match($status){'completed'=>'Completed','failed'=>'Failed','processing'=>!empty($timeline['sentAt'])?'Delivered to Google':'Delivering','queued'=>!empty($timeline['sentAt'])?'Delivered to Google':'Queued',default=>ucwords(str_replace('_',' ',$status))};
 $html='<strong>'.h($label).'</strong><div class="index-sub-milestones">';
 $submitted=$timeline['createdAt']??$s['accepted_at']??null;
 if($s['provider_id']&&$submitted)$html.='<div><span>Submitted</span><time>'.h(indexingMilestoneTime($submitted)).'</time></div>';
 if(!empty($timeline['sentAt']))$html.='<div><span>Delivered to Google</span><time>'.h(indexingMilestoneTime($timeline['sentAt'])).'</time></div>';
 if($status==='completed'){$html.='<div><span>Completed</span>';if(!empty($timeline['completedAt']))$html.='<time>'.h(indexingMilestoneTime($timeline['completedAt'])).'</time>';$html.='<small class="index-sub-confirmed">Confirmed by the indexer</small></div>';}
 return $html.'</div>';
}
function indexingHistory(array $batch):string {
 if(!$batch['submissions'])return '<p class="hint">No submissions yet.</p>';
 $out='<div class="table-wrap"><table><thead><tr><th>Time (Bangladesh)</th><th>URLs</th><th>Status</th><th>Credits</th></tr></thead><tbody>';
 foreach($batch['submissions'] as $s){$s['provider']=$batch['provider']??'indexbear';$out.='<tr><td>'.h(indexingTime($s['created_at'])).'</td><td>'.(int)$s['url_count'].'</td><td>'.indexingSubmissionProgress($s);if($s['provider_id'])$out.='<small class="index-sub-id">'.h($s['provider_id']).'</small>';if($s['status_hint'])$out.='<small class="index-sub-hint">'.h($s['status_hint']).'</small>';if($s['last_error'])$out.='<small class="index-sub-error">'.h($s['last_error']).'</small>';$out.='</td><td>'.(int)$s['credits_used'].'</td></tr>';}
 return $out.'</tbody></table></div>';
}
function indexingStatus(array $b):string {
 if($b['completed']===$b['total'])return 'Completed';
 if(!empty($b['paused'])&&$b['remaining']>0&&$b['mode']==='automatic')return 'Paused';
 if($b['accepted']>0||!empty($b['last_started_at'])||in_array($b['status'],['running','submitted','review_required'],true))return 'In Progress';
 foreach($b['submissions']??[] as $s)if(!empty($s['attempted_at'])||!empty($s['accepted_at'])||in_array($s['status'],['submitting','retry','blocked','queued','processing','completed','failed'],true))return 'In Progress';
 if($b['mode']==='automatic'&&$b['status']==='scheduled')return 'Scheduled';
 return 'Ready';
}
function indexingBadgeClass(array $b):string{return match(indexingStatus($b)){'Completed'=>'completed','In Progress'=>'in-progress','Paused'=>'paused','Scheduled'=>'scheduled',default=>'ready'};}
function indexingOrderData(array $b,int $cooldown=0):array {
 $cooldown=(int)($b['provider_cooldown']??$cooldown);
 // Pausing suspends submission, not the batch's existing sorting priority.
 $b['paused']=0;
 $label=indexingStatus($b);$countdown=indexingCountdownData($b,$cooldown);$sending=$b['status']==='running';
 $group=match($label){'Completed'=>3,'Ready','Paused'=>2,default=>$sending||$countdown['next_at']!==null?0:1};
 return ['sort_group'=>$group,'sort_time'=>$group===0&&!$sending?($countdown['next_at']??0):0];
}
function indexingOrderedBatches(array $batches,int $cooldown=0):array {
 foreach($batches as &$b)$b=array_merge($b,indexingOrderData($b,$cooldown));unset($b);
 usort($batches,fn($a,$b)=>[$a['sort_group'],$a['sort_time']]<=>[$b['sort_group'],$b['sort_time']]?:strcmp($b['created_at']??'',$a['created_at']??'')?:strcmp($a['id'],$b['id']));return $batches;
}
function indexingCountdownData(array $batch,int $cooldown=0,?int $now=null):array {
 $cooldown=(int)($batch['provider_cooldown']??$cooldown);
 $now??=time();$state='scheduled';$next=null;
 if($batch['remaining']===0){$state='done';$label=$batch['completed']===$batch['total']?'Completed':'All URLs submitted';}
 elseif(!empty($batch['paused'])&&$batch['mode']==='automatic'){$state='paused';$label='Paused';}
 elseif($batch['mode']!=='automatic'){$state='manual';$label='Manual mode';}
 elseif(in_array($batch['status'],['error','review_required'],true)){$state='paused';$label='Paused — check error';}
 elseif($batch['status']==='running'){$state='submitting';$label='Submitting…';}
 elseif(empty($batch['next_run_at'])){$state='paused';$label='No submission scheduled';}
 else{
  $next=(new DateTimeImmutable($batch['next_run_at'],new DateTimeZone('UTC')))->getTimestamp();
  foreach($batch['submissions']??[] as $submission)if($submission['status']==='retry'&&!empty($submission['next_attempt_at']))$next=max($next,(new DateTimeImmutable($submission['next_attempt_at'],new DateTimeZone('UTC')))->getTimestamp());
  $next=max($next,$cooldown);$seconds=max(0,$next-$now);$days=intdiv($seconds,86400);$clock=sprintf('%02d:%02d:%02d',intdiv($seconds%86400,3600),intdiv($seconds%3600,60),$seconds%60);$label=$seconds>0?'In '.($days?$days.'d ':'').$clock:'Due now';
 }
 return ['countdown_state'=>$state,'countdown_label'=>$label,'next_at'=>$next,'server_now'=>$now,'next_label'=>$next?indexingTime(gmdate('Y-m-d H:i:s',$next)):'—'];
}
function indexingViewData(array $batches,int $cooldown=0):array {
 $batches=indexingOrderedBatches($batches,$cooldown);
 foreach($batches as &$b){$b['status_label']=indexingStatus($b);$b['status_class']=indexingBadgeClass($b);$b['history_html']=indexingHistory($b);$b['domains_html']=indexingDomainRows($b);$b=array_merge($b,indexingCountdownData($b,$cooldown));unset($b['submissions']);}unset($b);return $batches;
}
function indexingDomainRows(array $batch):string {
 $html='';foreach($batch['domains']??[] as $domain){$html.='<div class="index-domain-row"><div><span>'.h($domain['base']).'</span><small>'.(int)$domain['pending'].' pending URLs</small></div><div class="index-domain-row-actions"><button type="button" class="secondary index-domain-open" data-domain-base="'.h($domain['base']).'" '.(!$domain['pending']?'disabled':'').'>Replace</button><button type="button" class="secondary index-domain-reduce-open" data-domain-base="'.h($domain['base']).'" data-domain-pending="'.(int)$domain['pending'].'" '.(!$domain['pending']?'disabled':'').'>Reduce URLs</button><button type="button" class="secondary index-domain-remove" data-domain-base="'.h($domain['base']).'">'.activitySpinner(true).'<span class="index-action-label">Remove</span></button></div></div>';}return $html;
}
function indexingDomainPanel(array $batch):string {
 return '<section class="indexing-domains"><h3>Domains &amp; Paths</h3><div class="index-domain-tools"><button type="button" class="secondary index-domain-add-open">Add Domains</button><form method="post" action="/api/indexing-download-urls">'.tokenField().'<input type="hidden" name="id" value="'.h($batch['id']).'"><button type="submit" class="secondary">Download URLs TXT</button></form><button type="button" class="secondary index-domain-randomize" data-randomized="'.(!empty($batch['randomized_at'])?'true':'false').'" title="Click to shuffle pending URLs again">'.activitySpinner(true).'<span class="index-action-label">'.(!empty($batch['randomized_at'])?'Randomized':'Randomize').'</span></button></div><form class="index-domain-add-form stack" method="post" action="/api/indexing-add-domains" hidden>'.tokenField().'<input type="hidden" name="id" value="'.h($batch['id']).'"><label>Additional Domains &amp; Paths<textarea name="new_domains" rows="4" maxlength="2097152" placeholder="https://example.com/path/&#10;https://another.example/path/" required></textarea><small>One per line. Redistributes pending URLs evenly across existing and new domains. Total count, IDs, XML, URL order and schedule stay unchanged.</small></label><div class="description-actions"><button type="submit" class="primary">'.activitySpinner(true).'<span class="index-action-label">Add Domains</span></button><button type="button" class="secondary index-domain-add-cancel">Cancel</button></div></form><div data-field="domains_html">'.indexingDomainRows($batch).'</div><form class="index-domain-form stack" method="post" action="/api/indexing-replace-domain" hidden>'.tokenField().'<input type="hidden" name="id" value="'.h($batch['id']).'"><input type="hidden" name="old_domain" value=""><p class="hint index-domain-source"></p><label>New Domain &amp; Path<input type="text" name="new_domain" maxlength="2048" placeholder="https://example.com/new/path/" required></label><div class="description-actions"><button type="submit" class="primary">'.activitySpinner(true).'<span class="index-action-label">Replace</span></button><button type="button" class="secondary index-domain-cancel">Cancel</button></div></form><form class="index-domain-reduce-form stack" method="post" action="/api/indexing-reduce-domain" hidden>'.tokenField().'<input type="hidden" name="id" value="'.h($batch['id']).'"><input type="hidden" name="domain" value=""><p class="hint index-domain-reduce-source"></p><label>URLs to Remove<input type="number" name="remove_count" min="1" max="0" step="1" required placeholder="e.g. 20"><small>Removes this many pending URLs from the end of the selected domain and path only.</small></label><div class="description-actions"><button type="submit" class="primary">'.activitySpinner(true).'<span class="index-action-label">Save</span></button><button type="button" class="secondary index-domain-reduce-cancel">Cancel</button></div></form><div class="notice index-domain-result" hidden role="status"></div></section>';
}
function indexingDateInput(?string $date):string {return $date??'';}
function indexingDurationLabel(int $total,int $size,int $gap):string {
 $minutes=max(0,(int)ceil(max(0,$total)/max(1,$size))-1)*max(0,$gap);$parts=[];
 $days=intdiv($minutes,1440);$hours=intdiv($minutes%1440,60);$mins=$minutes%60;
 if($days)$parts[]=$days.($days===1?' day':' days');if($hours)$parts[]=$hours.' hr';if($mins||!$parts)$parts[]=$mins.' min';return implode(' ',$parts);
}
function indexingTimeWindowLabel(string $start,int $total,int $size,int $gap):string {
 if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$start))return '—';
 [$hour,$minute]=array_map('intval',explode(':',$start));$first=$hour*60+$minute;$duration=max(0,(int)ceil(max(0,$total)/max(1,$size))-1)*max(0,$gap);
 $format=static function(int $minutes):string{$minutes%=1440;$hour=intdiv($minutes,60);$minute=$minutes%60;return ($hour%12?:12).($minute?sprintf(':%02d',$minute):'').($hour<12?'am':'pm');};
 return $format($first).' - '.$format($first+$duration);
}
function indexingCard(array $b,int $cooldown=0):string {
 $countdown=indexingCountdownData($b,$cooldown);$order=indexingOrderData($b,$cooldown);
 $out='<section class="panel indexing-batch" data-index-batch="'.h($b['id']).'" data-provider="'.h($b['provider']??'indexbear').'" data-sort-group="'.(int)$order['sort_group'].'" data-sort-time="'.(int)$order['sort_time'].'" data-sort-created="'.h($b['created_at']).'"><div class="indexing-card-heading"><h2>'.h($b['event']).'</h2><div class="indexing-heading-badges"><button type="button" class="secondary index-pause" data-paused="'.(!empty($b['paused'])?'1':'0').'" '.($b['mode']!=='automatic'||$b['remaining']===0?'hidden':'').'>'.activitySpinner(true).'<span class="index-action-label">'.(!empty($b['paused'])?'Resume':'Pause').'</span></button><span class="button indexing-duration" data-field="duration">'.h(indexingTimeWindowLabel((string)$b['start_time'],(int)$b['total'],(int)$b['per_submit'],(int)$b['gap_minutes'])).'</span><span class="indexing-state indexing-badge '.h(indexingBadgeClass($b)).'" data-field="status">'.h(indexingStatus($b)).'</span></div></div><div class="indexing-counts">';
 foreach(['total'=>'Total URLs','accepted'=>'Accepted by API','remaining'=>'Remaining','completed'=>'Completed','failed'=>'Failed'] as $key=>$label)$out.='<div><strong data-field="'.h($key).'">'.(int)$b[$key].'</strong><span>'.h($label).'</span></div>';
 $out.='</div><progress data-field="progress" value="'.(int)$b['accepted'].'" max="'.(int)$b['total'].'"></progress><div class="indexing-next"><span class="hint">Next submission</span><strong data-field="countdown" data-countdown-state="'.h($countdown['countdown_state']).'" data-next-at="'.h((string)($countdown['next_at']??'')).'" data-server-now="'.(int)$countdown['server_now'].'">'.h($countdown['countdown_label']).'</strong><small><span data-field="next_label">'.h($countdown['next_label']).'</span> (Bangladesh)</small></div><form class="indexing-batch-form stack" method="post" action="/api/indexing-configure">'.tokenField().'<input type="hidden" name="id" value="'.h($b['id']).'"><div class="grid"><label>Indexer<select name="provider" data-saved-provider="'.h($b['provider']??'indexbear').'" '.(!empty($b['provider_locked'])?'disabled':'').'><option value="indexbear" '.(($b['provider']??'indexbear')==='indexbear'?'selected':'').'>IndexBear</option><option value="rocketindexer" '.(($b['provider']??'indexbear')==='rocketindexer'?'selected':'').'>RocketIndexer</option></select></label><label>Mode<select name="mode"><option value="manual" '.($b['mode']==='manual'?'selected':'').'>Manual</option><option value="automatic" '.($b['mode']==='automatic'?'selected':'').'>Automatic · CLI Worker</option></select></label><label>Start Date<input name="start_date" type="text" value="'.h(indexingDateInput($b['start_date'])).'" placeholder="e.g. 5" inputmode="numeric" maxlength="10"></label><label>Start Time · Bangladesh (24-hour)<input name="start_time" type="text" value="'.h($b['start_time']).'" placeholder="HH:MM" pattern="(?:[01][0-9]|2[0-3]):[0-5][0-9]" maxlength="5" inputmode="numeric" required></label><label>URLs per Submission<input type="number" name="per_submit" min="1" max="'.(($b['provider']??'indexbear')==='rocketindexer'?'100':'1000').'" value="'.(int)$b['per_submit'].'" required></label><label>Gap Time (Minutes)<input type="number" name="gap_minutes" min="1" max="10080" value="'.(int)$b['gap_minutes'].'" required></label></div><div class="description-actions indexing-batch-actions"><button type="submit" class="secondary" value="save">'.activitySpinner(true).'<span class="index-action-label">Save Settings</span></button><button type="submit" class="primary index-submit-now" value="submit" formnovalidate>'.activitySpinner(true).'<span class="index-action-label">Submit Now</span></button><button type="button" class="secondary index-reduce-open">Reduce URLs · <span data-field="reduce_remaining">'.(int)$b['remaining'].'</span> pending</button><button type="button" class="secondary index-delete">'.activitySpinner(true).'<span class="index-action-label">Delete</span></button></div><div class="notice index-action-message" hidden role="status"></div></form><div class="notice error" data-field="last_error" '.($b['last_error']===''?'hidden':'').'>'.h($b['last_error']).'</div>'.'<form class="index-reduce-form stack" method="post" action="/api/indexing-reduce" hidden>'.tokenField().'<input type="hidden" name="id" value="'.h($b['id']).'"><label>URLs to Remove<input type="number" name="remove_count" min="1" max="'.(int)$b['remaining'].'" step="1" required placeholder="e.g. 20"><small>Removes this many URLs from the end of the pending list.</small></label><div class="description-actions"><button type="submit" class="primary">'.activitySpinner(true).'<span class="index-action-label">Save</span></button><button type="button" class="secondary index-reduce-cancel">Cancel</button></div><div class="notice index-reduce-result" role="status" hidden></div></form>'.indexingDomainPanel($b).'<details class="indexing-history"><summary>Submission History</summary><div data-field="history_html">'.indexingHistory($b).'</div></details></section>';
 return $out;
}
function indexingPage():never {
 $queue=indexingQueue();$batches=indexingAllBatches((int)$_SESSION['user_id']);$meta=json_decode(setting('indexbear_connection','{}'),true)?:[];$connected=setting('indexbear_api_key')!=='';$worker=indexingWorkerState($queue);
 $body='<div id="indexing-page" data-sort-user="'.(int)$_SESSION['user_id'].'" data-balance-credits="'.(int)($meta['balance']??0).'" data-url-cost="'.(int)($meta['cost']??10).'" data-indexbear-connected="'.($connected?'true':'false').'" data-worker-running="'.($worker['running']?'true':'false').'"><div class="history-heading"><h1>Indexing</h1><a class="button secondary" href="/trash">Trash</a></div><section class="panel indexing-connection-panel"><div class="indexing-connection-heading"><h2>IndexBear Connection</h2><p class="hint">Google Instant · <span id="indexing-credit-cost">'.(int)($meta['cost']??10).'</span> credits per URL</p></div><form id="indexing-connection-form" class="indexing-connection-form" method="post" action="/api/indexing-connection">'.tokenField().'<div class="indexing-connection-controls"><label>API Key<input type="password" name="api_key" autocomplete="new-password" placeholder="'.($connected?'Saved key — leave blank to keep it':'Enter your IndexBear API key').'"></label><button class="primary" type="submit">'.activitySpinner(true).'<span class="index-action-label">'.($connected?'Check Connection':'Connect').'</span></button></div><div class="indexing-connection-summary"><div id="indexing-connection-result" class="notice '.($connected?'success':'info').'" role="status">'.($connected?h(indexingBalanceLabel($meta)):'Add your key to connect.').'</div><div id="indexing-projection" class="notice info" role="status" '.($connected?'':'hidden').'>'.h(indexingProjectionLabel($meta,$batches)).'</div></div></form></section>'.indexingRocketConnection($batches).'<div id="indexing-refresh-error" class="notice error" hidden role="status"></div><div class="indexing-sort-bar"><label for="indexing-sort">Sort by</label><select id="indexing-sort"><option value="time">Nearest Start Time</option><option value="name">Name</option></select></div><div class="indexing-batches">';
 if(!$batches)$body.='<section class="panel"><h2>No batches yet</h2><p>Generate an event batch, then choose Move Indexer under Download URLs TXT.</p><a class="button secondary" href="/">Open Generator</a></section>';
 foreach(indexingOrderedBatches($batches,(int)$queue->control('cooldown_until')) as $b)$body.=indexingCard($b,(int)$queue->control('cooldown_until'));
 $body.='</div></div>';page('Indexing',$body,false,indexingWorkerBadge($worker,csrf()));exit;
}

function indexingRocketConnection(array $batches):string {
 $queue=indexingQueue('rocketindexer');$meta=json_decode(setting('rocketindexer_connection','{}'),true)?:[];$cache=$queue->cachedBalance();if($cache&&strtotime($cache['checked_at'])>=strtotime($meta['checked_at']??'1970-01-01'))$meta=$cache;
 $connected=setting('rocketindexer_api_key')!=='';$meta['cost']=1;$meta['provider']='rocketindexer';
 return '<section id="rocket-connection" class="panel indexing-connection-panel" data-connected="'.($connected?'true':'false').'" data-balance="'.(int)($meta['balance']??0).'"><div class="indexing-connection-heading"><h2>RocketIndexer Connection</h2><p class="hint">1 credit per URL</p></div><form id="rocket-connection-form" class="indexing-connection-form" method="post" action="/api/indexing-connection">'.tokenField().'<input type="hidden" name="provider" value="rocketindexer"><div class="indexing-connection-controls"><label>API Key<input type="password" name="api_key" autocomplete="new-password" placeholder="'.($connected?'Saved key — leave blank to keep it':'Enter your RocketIndexer API key').'"></label><button type="submit" class="primary">'.activitySpinner(true).'<span class="index-action-label">Check Connection</span></button></div><div class="indexing-connection-summary"><div id="rocket-connection-result" class="notice '.($connected?'success':'info').'" role="status">'.($connected?h(indexingBalanceLabel($meta)):'Add your key to connect.').'</div><div id="rocket-projection" class="notice info" role="status" '.($connected?'':'hidden').'>'.h(indexingProjectionLabel($meta,$batches)).'</div></div></form></section>';
}
