<?php
declare(strict_types=1);
interface IndexingApi {
 public function balance():array;
 public function submit(array $urls,string $name,string $key):array;
 public function status(string $id):array;
}
final class IndexBearError extends RuntimeException {
 public function __construct(string $message,public string $kind='uncertain',public int $retryAfter=60,public bool $definite=false){parent::__construct($message);}
}
final class IndexBearClient implements IndexingApi {
 public function __construct(private string $apiKey,private ?Closure $transport=null){}
 private function request(string $method,string $path,?array $payload=null,?string $idempotency=null):array {
  if($this->apiKey==='')throw new IndexBearError('Connect your IndexBear API key first.','unauthorized',0,true);
  $headers=['x-api-key: '.$this->apiKey,'Accept: application/json','Content-Type: application/json'];if($idempotency!==null)$headers[]='Idempotency-Key: '.$idempotency;
  if($this->transport){$response=($this->transport)($method,$path,$payload,$headers);}
  else{
   if(!function_exists('curl_init'))throw new IndexBearError('Enable PHP cURL to connect IndexBear.','configuration',0,true);
   $curl=curl_init('https://api.indexbear.app'.$path);$body='';$responseHeaders=[];
   curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'EventForge-Indexer',CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($c,$v)use(&$body){if(strlen($body)+strlen($v)>16777216)return 0;$body.=$v;return strlen($v);},CURLOPT_HEADERFUNCTION=>static function($c,$v)use(&$responseHeaders){if(str_contains($v,':')){[$k,$value]=explode(':',$v,2);$responseHeaders[strtolower(trim($k))]=trim($value);}return strlen($v);}]);
   if($payload!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
   $ok=curl_exec($curl);$http=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);$response=['http'=>$ok===false?0:$http,'body'=>$body,'headers'=>$responseHeaders];
  }
  $http=(int)$response['http'];$data=is_array($response['body'])?$response['body']:json_decode($response['body'],true);$rh=$response['headers']??[];
  if($http>=200&&$http<300&&is_array($data)&&($data['status']??'')==='ok')return $data;
  $kind=is_array($data)?(string)($data['code']??'uncertain'):'uncertain';
  $message=is_array($data)&&is_string($data['error']??null)?$data['error']:'IndexBear did not confirm the request. Retry safely using the existing submission.';
  $message=str_replace($this->apiKey,'[API key]',mb_substr($message,0,1000));
  $retry=max(1,(int)($rh['retry-after']??60));$definite=in_array($http,[400,401,402,403,404,415,429],true);
  if($http===429)$kind='rate_limited';
  if($http===0)$kind='uncertain';
  throw new IndexBearError($message,$kind,$retry,$definite);
 }
 public function balance():array{return $this->request('GET','/v1/balance');}
 public static function apiName(string $name):string {
  $suffix='';if(preg_match('/\s+#([0-9]+)$/D',$name,$match)){$suffix=' - '.$match[1];$name=substr($name,0,-strlen($match[0]));}
  // Keep the original normalization for unnumbered names, including pending-request replays.
  $base=trim(preg_replace('/[^A-Za-z0-9 ._-]/',' ',$name))?:'Event batch';
  return $suffix===''?substr($base,0,80):rtrim(substr($base,0,max(1,80-strlen($suffix)))).$suffix;
 }
 public function submit(array $urls,string $name,string $key):array {return $this->submitPrepared($urls,self::apiName($name),$key);}
 public function submitPrepared(array $urls,string $name,string $key):array {
  if(!preg_match('/^[A-Za-z0-9 ._-]{1,80}$/D',$name))throw new IndexBearError('Invalid saved submission name.','invalid_name',0,true);
  return $this->request('POST','/v1/submit',['engine'=>'google','mode'=>'instant','name'=>$name,'urls'=>array_values($urls)],$key);
 }
 public function status(string $id):array {
  if(!preg_match('/^[A-Za-z0-9_-]{1,160}$/D',$id))throw new IndexBearError('Invalid IndexBear submission ID.','invalid_response',0,true);
  $data=$this->request('GET','/v1/submissions/'.rawurlencode($id));$row=$data['submission']??null;
  if(!is_array($row)||($row['id']??'')!==$id||!in_array($row['status']??'',['queued','processing','completed','failed'],true))throw new IndexBearError('IndexBear returned an unrecognized status.','invalid_response',60);
  return $row;
 }
}
