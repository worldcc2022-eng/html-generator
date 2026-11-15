<?php
declare(strict_types=1);
require_once __DIR__.'/IndexBearClient.php';
final class RocketSubmissionReview extends RuntimeException {
 public function __construct(string $message,public array $providerData){parent::__construct($message);}
}
final class RocketIndexerClient implements IndexingApi {
 public function __construct(private string $apiKey,private ?Closure $transport=null){}
 private function request(string $method,string $endpoint,?array $payload=null,array $query=[]):array {
  if($this->apiKey==='')throw new IndexBearError('Connect your RocketIndexer API key first.','unauthorized',0,true);
  $path='/api/index.php?'.http_build_query(['endpoint'=>$endpoint,'token'=>$this->apiKey]+$query,'','&',PHP_QUERY_RFC3986);
  $headers=['Accept: application/json','Content-Type: application/json'];
  if($this->transport)$response=($this->transport)($method,$path,$payload,$headers);
  else{
   if(!function_exists('curl_init'))throw new IndexBearError('Enable PHP cURL to connect RocketIndexer.','configuration',0,true);
   $curl=curl_init('https://rocketindexer.com'.$path);$body='';$rh=[];
   curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'EventForge-Indexer',CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($c,$v)use(&$body){if(strlen($body)+strlen($v)>16777216)return 0;$body.=$v;return strlen($v);},CURLOPT_HEADERFUNCTION=>static function($c,$v)use(&$rh){if(str_contains($v,':')){[$k,$value]=explode(':',$v,2);$rh[strtolower(trim($k))]=trim($value);}return strlen($v);}]);
   if($payload!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
   $ok=curl_exec($curl);$http=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);$response=['http'=>$ok===false?0:$http,'body'=>$body,'headers'=>$rh];
  }
  $http=(int)$response['http'];$data=is_array($response['body'])?$response['body']:json_decode($response['body'],true);
  if($http>=200&&$http<300&&is_array($data)&&($data['success']??false)===true&&is_array($data['data']??null))return $data['data'];
  $message=is_array($data)?($data['message']??$data['error']??null):null;
  if(!is_array($data)&&$http>0)$message='RocketIndexer returned HTTP '.$http.' without a JSON API response. Check API access is enabled and your server IP is allowed in RocketIndexer settings.';
  if(!is_string($message)||$message==='')$message='RocketIndexer did not confirm the request. Check its dashboard before submitting again.';
  $message=mb_substr(str_replace([rawurlencode($this->apiKey),urlencode($this->apiKey),$this->apiKey],'[API key]',$message),0,1000);
  throw new IndexBearError($message,$http===429?'rate_limited':($http===401||$http===403?'unauthorized':'uncertain'),max(1,(int)($response['headers']['retry-after']??60)),in_array($http,[400,401,402,403,404,415,422,429],true));
 }
 public function balance():array {
  $out=$this->request('GET','balance');$available=$out['credits']['available']??null;
  if(!is_numeric($available)||$available<0)throw new IndexBearError('RocketIndexer returned an invalid balance.','invalid_response',60);
  return ['balance'=>(int)$available,'creditCosts'=>['google_instant'=>1]];
 }
 public function submit(array $urls,string $name,string $key):array {
  if(!$urls||count($urls)>100)throw new IndexBearError('RocketIndexer batches support 1–100 URLs in EventForge.','invalid_request',0,true);
  $out=$this->request('POST','submit',['urls'=>array_values($urls)]);$tracking=$out['tracking_ids']??[];
  $details=['tracking_ids'=>$tracking,'urls'=>array_values($urls),'submitted'=>$out['submitted']??null,'failed'=>$out['failed']??null,'invalid_urls'=>$out['invalid_urls']??[],'items'=>[]];
  $valid=is_array($tracking)&&count($tracking)===count($urls)&&count(array_unique($tracking,SORT_REGULAR))===count($tracking);
  if($valid)foreach($tracking as $id)if(!is_int($id)&&!(is_string($id)&&preg_match('/^[1-9][0-9]*$/D',$id))||((int)$id)<1){$valid=false;break;}
  if(!$valid||($out['submitted']??null)!==count($urls)||(int)($out['failed']??0)!==0||!empty($out['invalid_urls']))throw new RocketSubmissionReview('RocketIndexer reported partial or unrecognized acceptance. Automatic resubmission is paused; check its dashboard and the saved tracking IDs.',$details);
  return ['submissionId'=>'rocket-'.hash('sha256',$key),'urlCount'=>count($urls),'creditsUsed'=>count($urls),'balance'=>isset($out['credits_remaining'])&&is_numeric($out['credits_remaining'])?(int)$out['credits_remaining']:null,'providerData'=>$details];
 }
 public function status(string $id):array{return $this->statusBatch(['tracking_ids'=>[$id],'urls'=>[],'items'=>[]]);}
 public function statusBatch(array $saved):array {
  $ids=$saved['tracking_ids']??[];if(!is_array($ids)||!$ids||count($ids)>100)throw new IndexBearError('RocketIndexer tracking IDs are unavailable.','invalid_response',60,true);
  foreach($ids as $id)if(!preg_match('/^[1-9][0-9]*$/D',(string)$id))throw new IndexBearError('Invalid RocketIndexer tracking ID.','invalid_response',60,true);
  $out=$this->request('GET','status',null,['ids'=>implode(',',$ids),'limit'=>100]);
  if(!is_array($out['items']??null))throw new IndexBearError('RocketIndexer returned an invalid status response.','invalid_response',60);
  $known=[];foreach($saved['items']??[] as $item)if(is_array($item)&&isset($item['tracking_id']))$known[(string)$item['tracking_id']]=$item;
  $allowed=array_fill_keys(array_map('strval',$ids),true);$urls=array_fill_keys($saved['urls']??[],true);
  foreach($out['items'] as $item){
   if(!is_array($item)||!isset($allowed[(string)($item['tracking_id']??'')])||!is_string($item['url']??null)||($urls&&!isset($urls[$item['url']]))||!is_string($item['status']??null))throw new IndexBearError('RocketIndexer returned a status for an unexpected URL or tracking ID.','invalid_response',60);
   $known[(string)$item['tracking_id']]=array_intersect_key($item,array_flip(['tracking_id','url','status','indexed_status','submitted_at','updated_at']));
  }
  $seen=[];foreach($known as $item){$url=$item['url']??'';if(isset($seen[$url]))throw new IndexBearError('RocketIndexer returned conflicting tracking IDs for the same URL.','invalid_response',60);$seen[$url]=true;}
  $completed=0;$failed=0;$indexed=0;
  foreach($known as $item){$status=strtolower(trim($item['status']));if($status==='delivered')++$completed;elseif(in_array($status,['failed','error','rejected'],true))++$failed;if(strtolower((string)($item['indexed_status']??''))==='indexed')++$indexed;}
  $saved['items']=array_values($known);$all=$completed+$failed===count($ids);
  return ['status'=>$all?($failed?'failed':'completed'):'processing','statusHint'=>$completed.' delivered · '.$indexed.' indexed · '.$failed.' failed','completedCount'=>$completed,'failedCount'=>$failed,'indexedCount'=>$indexed,'providerData'=>$saved,'keepPolling'=>$indexed+$failed<count($ids)];
 }
}
