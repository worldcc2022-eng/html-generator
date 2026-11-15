<?php
declare(strict_types=1);
final class GitHubSshNetworkError extends RuntimeException {}

final class GitHubConnect {
 public static function repository(string $url):string {
  $url=trim($url);
  if(!preg_match('~^https://github\.com/([A-Za-z0-9](?:[A-Za-z0-9-]{0,38}))/([A-Za-z0-9_.-]{1,100})/?$~D',$url,$m))throw new InvalidArgumentException('Enter a repository URL such as https://github.com/owner/repository.');
  $name=preg_replace('/\.git$/','',$m[2]);
  if($name===''||in_array($name,['.','..'],true))throw new InvalidArgumentException('Enter a valid GitHub repository name.');
  return $m[1].'/'.$name;
 }
 public static function branch(string $branch):string {
  $branch=trim($branch);
  if(!preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$~D',$branch)||str_contains($branch,'..')||str_contains($branch,'//')||preg_match('~(?:^|/)\.|\.lock(?:/|$)|[./]$~',$branch))throw new InvalidArgumentException('Enter a valid branch name, such as main.');
  return $branch;
 }
 public static function binary(string $name):string {
  if(!function_exists('proc_open'))throw new RuntimeException('PHP process execution is disabled. Enable proc_open for GitHub connections.');
  foreach(['/usr/bin/','/usr/local/bin/','/bin/'] as $prefix)if(is_executable($prefix.$name))return $prefix.$name;
  throw new RuntimeException('The server needs '.$name.' installed for GitHub connections.');
 }
 /** Execute argv without a shell; keep network and process checks bounded. */
 public static function run(array $argv,array $environment=[],int $timeout=20,string $input=''):array {
  $environment=array_merge(['PATH'=>'/usr/local/bin:/usr/bin:/bin','LANG'=>'C','LC_ALL'=>'C','GIT_TERMINAL_PROMPT'=>'0','GIT_CONFIG_NOSYSTEM'=>'1','GIT_CONFIG_GLOBAL'=>'/dev/null'],$environment);
  $process=proc_open($argv,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$environment,['bypass_shell'=>true]);
  if(!is_resource($process))throw new RuntimeException('Could not start Git/SSH. Check PHP process permissions.');
  stream_set_blocking($pipes[0],false);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$sent=0;$inputOpen=true;
  if($input===''){fclose($pipes[0]);$inputOpen=false;}
  $out='';$err='';$code=-1;$started=microtime(true);
  try{
   do{
    if($inputOpen){$written=@fwrite($pipes[0],substr($input,$sent,8192));if($written===false){fclose($pipes[0]);$inputOpen=false;}else{$sent+=$written;if($sent===strlen($input)){fclose($pipes[0]);$inputOpen=false;}}}
    $out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);$state=proc_get_status($process);
    if(!$state['running']){$code=$state['exitcode'];break;}
    if(microtime(true)-$started>$timeout||strlen($out)+strlen($err)>524288){proc_terminate($process,9);$code=124;break;}
    usleep(20000);
   }while(true);
   $out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);
  }finally{if($inputOpen)fclose($pipes[0]);fclose($pipes[1]);fclose($pipes[2]);$closed=proc_close($process);if($code===-1)$code=$closed;}
  return ['code'=>$code,'out'=>$out,'err'=>$err];
 }
 private static function temporaryDirectory():string {
  $path=rtrim(sys_get_temp_dir(),'/').'/eventforge-github-'.bin2hex(random_bytes(16));
  if(!mkdir($path,0700)||!chmod($path,0700))throw new RuntimeException('Cannot create a private temporary folder for SSH.');
  return $path;
 }
 private static function removeDirectory(string $path):void {
  foreach(scandir($path)?:[] as $entry){if($entry==='.'||$entry==='..')continue;$file=$path.'/'.$entry;if(is_dir($file)&&!is_link($file))self::removeDirectory($file);else @unlink($file);}
  @rmdir($path);
 }
 public static function generateKey():array {
  $binary=self::binary('ssh-keygen');$dir=self::temporaryDirectory();
  try{
   $result=self::run([$binary,'-q','-t','ed25519','-N','','-C','EventForge deploy key','-f',$dir.'/identity']);
   if($result['code']!==0)throw new RuntimeException('Could not generate the deploy key. Check ssh-keygen and temporary-folder permissions.');
   chmod($dir.'/identity',0600);
   $fingerprint=self::run([$binary,'-l','-E','sha256','-f',$dir.'/identity.pub']);
   if($fingerprint['code']!==0||!preg_match('/SHA256:[A-Za-z0-9+\/]+/',$fingerprint['out'],$m))throw new RuntimeException('Could not read the deploy-key fingerprint.');
   return ['private_key'=>file_get_contents($dir.'/identity'),'public_key'=>trim(file_get_contents($dir.'/identity.pub')),'fingerprint'=>$m[0]];
  }finally{self::removeDirectory($dir);}
 }
 public static function knownHosts(array $keys):string {
  $lines=[];
  foreach($keys as $key){
   if(!is_string($key)||!preg_match('/^ssh-ed25519 [A-Za-z0-9+\/=]+(?: [^\r\n]*)?$/D',$key))throw new InvalidArgumentException('Invalid GitHub SSH host key.');
   $parts=explode(' ',$key);$lines[]='github.com '.$parts[0].' '.$parts[1];
   $lines[]='[github.com]:443 '.$parts[0].' '.$parts[1];$lines[]='[ssh.github.com]:443 '.$parts[0].' '.$parts[1];
  }
  if(!$lines)throw new InvalidArgumentException('GitHub did not provide an Ed25519 host key. Retry later.');
  return implode("\n",$lines)."\n";
 }
 public static function githubHosts():string {
  if(!function_exists('curl_init'))throw new RuntimeException('Enable the PHP cURL extension to verify GitHub host keys.');
  $curl=curl_init('https://api.github.com/meta');$body='';
  curl_setopt_array($curl,[CURLOPT_USERAGENT=>'EventForge-Github-Connect',CURLOPT_HTTPHEADER=>['Accept: application/vnd.github+json'],CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($handle,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>262144)return 0;$body.=$chunk;return strlen($chunk);}]);
  $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
  if($ok===false||$status!==200)throw new RuntimeException('Could not securely retrieve GitHub SSH host keys. Check outbound HTTPS and retry.');
  $meta=json_decode($body,true);$keys=[];
  foreach($meta['ssh_keys']??[] as $key)if(is_string($key)&&str_starts_with($key,'ssh-ed25519 '))$keys[]=$key;
  return self::knownHosts($keys);
 }
 public static function withIdentity(string $privateKey,string $hosts,callable $operation,int $port=22,?string $expectedPublicKey=null):mixed {
  if(!in_array($port,[22,443],true))throw new InvalidArgumentException('Unsupported GitHub SSH port.');
  $ssh=self::binary('ssh');$dir=self::temporaryDirectory();
  try{
   $privateKey=rtrim(str_replace("\r\n","\n",$privateKey))."\n";
   foreach(['identity'=>$privateKey,'known_hosts'=>$hosts] as $name=>$value){if(file_put_contents($dir.'/'.$name,$value)===false||!chmod($dir.'/'.$name,0600))throw new RuntimeException('Cannot prepare the private SSH identity.');}
   $derived=self::run([self::binary('ssh-keygen'),'-y','-P','','-f',$dir.'/identity']);
   if($derived['code']!==0)throw new RuntimeException('The saved private key cannot be loaded by SSH. The connection did not reach GitHub authentication.');
   if($expectedPublicKey!==null){
    $expected=preg_split('/\s+/',trim($expectedPublicKey));$actual=preg_split('/\s+/',trim($derived['out']));
    if(array_slice($expected,0,2)!==array_slice($actual,0,2))throw new RuntimeException('The saved private key does not match the displayed public deploy key. Restore the matching key pair before connecting.');
   }
   $command=escapeshellarg($ssh).' -F /dev/null -i '.escapeshellarg($dir.'/identity').' -o IdentitiesOnly=yes -o IdentityAgent=none -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile='.escapeshellarg($dir.'/known_hosts').' -o GlobalKnownHostsFile=/dev/null -o ConnectTimeout=8 -o ConnectionAttempts=1 -o ServerAliveInterval=5 -o ServerAliveCountMax=2';
   if($port===443)$command.=' -o Hostname=ssh.github.com -p 443 -o HostKeyAlias=github.com';
   return $operation($dir,['HOME'=>$dir,'GIT_SSH_COMMAND'=>$command,'GIT_SSH_VARIANT'=>'ssh']);
  }finally{self::removeDirectory($dir);}
 }
 private static function failureDetail(array $result):string {
  $detail=trim((string)($result['err']??''));if($detail==='')$detail=trim((string)($result['out']??''));
  $detail=preg_replace('~-----BEGIN [^-\r\n]*PRIVATE KEY-----[\s\S]*?(?:-----END [^-\r\n]*PRIVATE KEY-----|$)~','[private key redacted]',$detail);
  $detail=preg_replace('~\bgh[pousr]_[A-Za-z0-9_]+\b~','[token redacted]',$detail);
  $detail=preg_replace('~[^\s"\x27]*eventforge-github-[a-f0-9]+/(?:identity|known_hosts)~','[temporary SSH file]',$detail);
  $detail=preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/','',$detail);
  return ' (exit '.(int)$result['code'].'): '.($detail!==''?mb_substr($detail,0,1600):'Git returned no diagnostic output.');
 }
 public static function commandFailure(array $result,string $step):string {return $step.self::failureDetail($result);}
 public static function isSshNetworkFailure(array $result):bool {
  return $result['code']===124||preg_match('/connect to host .* port [0-9]+:|Connection to [^\r\n]*(?:timed out|refused|reset|closed)|Connection (?:timed out|refused|reset|closed)|No route to host|Network is unreachable|Could not resolve hostname|kex_exchange_identification|Operation timed out/i',$result['err'])===1;
 }
 private static function checkResult(array $result,bool $write=false,string $phase='read'):void {
  if($result['code']===0)return;
  $detail=self::failureDetail($result);
  if($phase==='local')throw new RuntimeException('Local Git setup failed'.$detail);
  if($result['code']===124)throw new GitHubSshNetworkError('GitHub SSH network connection timed out. Check outbound SSH access.');
  if(str_contains($result['err'],'Host key verification failed'))throw new RuntimeException('GitHub SSH host verification failed. Retry the connection.');
  if(preg_match('/Load key .*:|UNPROTECTED PRIVATE KEY|bad permissions|sign_and_send_pubkey: signing failed/i',$result['err']))throw new RuntimeException('SSH could not load or use the saved private key. Check server key-file permissions and key format.');
  if(self::isSshNetworkFailure($result))throw new GitHubSshNetworkError('GitHub SSH network connection failed'.$detail);
  if(str_contains($result['err'],'Permission denied (publickey)'))throw new RuntimeException('GitHub did not accept the saved deploy key. Confirm its fingerprint is attached to this exact repository, then retry Connect.');
  if(str_contains($result['err'],'Repository not found'))throw new RuntimeException('GitHub could not find or authorize this repository. Check its exact owner/name and that this deploy key belongs to it.');
  if(str_contains($result['err'],'Permission denied')||preg_match('/Permission to .* denied|read only key/i',$result['err']))throw new RuntimeException($write?'GitHub denied write access. Enable Allow write access for this repository deploy key.':'SSH access was denied. Check server SSH permissions and repository deploy-key access.');
  throw new RuntimeException(($write?'GitHub write-access check failed':'GitHub repository read failed').$detail);
 }
 public static function connect(string $repository,string $branch,string $privateKey,?string $publicKey=null):array {
  $repository=self::repository('https://github.com/'.$repository);$branch=self::branch($branch);$git=self::binary('git');$hosts=self::githubHosts();
  $attempt=static function(int $port)use($privateKey,$hosts,$repository,$branch,$git,$publicKey):array{return self::withIdentity($privateKey,$hosts,static function($dir,$env)use($repository,$branch,$git,$port){
   $remote='git@github.com:'.$repository.'.git';$ref='refs/heads/'.$branch;
   $read=self::run([$git,'ls-remote','--exit-code',$remote,$ref],$env,20);
   if($read['code']===2)throw new RuntimeException('The branch does not exist. Enter an existing branch, or initialize the repository with a README first.');
   self::checkResult($read);
   if(!preg_match('/^([a-f0-9]{40,64})\t'.preg_quote($ref,'/').'$/m',trim($read['out']),$m))throw new RuntimeException('GitHub did not return the selected branch.');
   $init=self::run([$git,'init','--bare','--template=',$dir.'/probe.git'],$env);self::checkResult($init,false,'local');
   // The existing remote SHA is enough for an up-to-date push dry run; no clone or upload.
   $write=self::run([$git,'--git-dir='.$dir.'/probe.git','push','--dry-run','--porcelain',$remote,$m[1].':'.$ref],$env,20);self::checkResult($write,true);
   return ['status'=>'connected','repository'=>$repository,'branch'=>$branch,'checked_at'=>gmdate('c'),'ssh_port'=>$port];
  },$port,$publicKey);};
  return self::withSshFallback($attempt);
 }
 public static function withSshFallback(callable $attempt,int $preferredPort=22):array {
  if(!in_array($preferredPort,[22,443],true))throw new InvalidArgumentException('Unsupported GitHub SSH port.');
  $otherPort=$preferredPort===22?443:22;
  try{return $attempt($preferredPort);}catch(GitHubSshNetworkError $e){
   try{return $attempt($otherPort);}catch(GitHubSshNetworkError $fallback){throw new GitHubSshNetworkError('Both GitHub SSH routes failed. Port '.$preferredPort.': '.$e->getMessage().' Port '.$otherPort.': '.$fallback->getMessage());}
  }
 }
 public static function configure(array $state,string $action,string $url,string $branch,callable $encrypt,callable $decrypt):array {
  if(!in_array($action,['generate','regenerate','connect'],true))throw new InvalidArgumentException('Invalid GitHub connection action.');
  $repository=self::repository($url);$branch=self::branch($branch);
  $same=($state['repository']??'')===$repository;
  if(in_array($action,['generate','regenerate'],true)){
   if($action==='regenerate'||!$same||empty($state['private_key'])||empty($state['public_key'])){
    $pair=self::generateKey();$state=['repository'=>$repository,'private_key'=>$encrypt($pair['private_key']),'public_key'=>$pair['public_key'],'fingerprint'=>$pair['fingerprint'],'status'=>'disconnected','checked_at'=>''];
   }
   if(($state['branch']??'')!==$branch){$state['status']='disconnected';$state['checked_at']='';}
   $state['branch']=$branch;$state['error']='';
   return $state;
  }
  $state['status']='disconnected';$state['checked_at']='';$state['error']='';
  if(!$same||empty($state['private_key'])){$state['error']='Generate a deploy key for this repository first.';return $state;}
  $state['branch']=$branch;
  try{$checked=self::connect($repository,$branch,$decrypt($state['private_key']),$state['public_key']??null);$state=array_merge($state,$checked);}
  catch(InvalidArgumentException|RuntimeException $e){$state['error']=$e->getMessage();}
  return $state;
 }
 public static function publicState(array $state):array {
  return ['repository_url'=>!empty($state['repository'])?'https://github.com/'.$state['repository']:'','branch'=>$state['branch']??'main','public_key'=>$state['public_key']??'','fingerprint'=>$state['fingerprint']??'','status'=>($state['status']??'')==='connected'?'connected':'disconnected','checked_at'=>$state['checked_at']??'','error'=>$state['error']??''];
 }
}
