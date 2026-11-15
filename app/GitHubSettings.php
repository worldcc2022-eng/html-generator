<?php
declare(strict_types=1);
require_once __DIR__.'/GitHubConnect.php';

function githubConnectApi():never {
 $action=$_POST['action']??'';$url=$_POST['repository_url']??'';$branch=$_POST['branch']??'main';
 if(!is_string($action)||!is_string($url)||!is_string($branch))throw new InvalidArgumentException('Enter a repository URL and branch.');
 GitHubConnect::repository($url);GitHubConnect::branch($branch);
 @set_time_limit(100);
 db()->beginTransaction();
 try{
  db()->prepare('INSERT IGNORE INTO eg_settings (name,value) VALUES (?,?)')->execute(['github_connect','{}']);
  $q=db()->prepare('SELECT value FROM eg_settings WHERE name=? FOR UPDATE');$q->execute(['github_connect']);
  $state=json_decode($q->fetchColumn()?:'{}',true)?:[];
  $state=GitHubConnect::configure($state,$action,$url,$branch,fn($key)=>cryptKey($key),fn($key)=>cryptKey($key,true));
  saveSetting('github_connect',json_encode($state,JSON_THROW_ON_ERROR));db()->commit();
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
 githubConnectResponse($state);
}

function githubConnectResponse(array $state):never {
 if(str_contains($_SERVER['HTTP_ACCEPT']??'','application/json'))jsonOut(['connection'=>GitHubConnect::publicState($state)]);
 header('Location: /settings#github-connect-section',true,303);exit;
}

function githubConnectPanel():string {
 $state=GitHubConnect::publicState(json_decode(setting('github_connect','{}'),true)?:[]);
 if(isset($_SESSION['github_connect_error'])){$state['error']=$_SESSION['github_connect_error'];unset($_SESSION['github_connect_error']);$state['status']='disconnected';}
 $connected=$state['status']==='connected';
 $status=$connected?'Connected — ready to upload':($state['public_key']!==''?'Deploy key ready — add it to GitHub, then connect.':'Not connected');
 $checked=$state['checked_at']!==''?(new DateTimeImmutable($state['checked_at']))->setTimezone(new DateTimeZone('Asia/Dhaka'))->format('d F Y, h:i:s A').' (Bangladesh time)':'';
 $keyLink=$state['repository_url']!==''?$state['repository_url'].'/settings/keys':'https://github.com/';
 return '<section class="panel github-connect" id="github-connect-section"><h2>Github Connect</h2><form id="github-connect-form" class="stack" action="/api/github-connect" method="post">'.tokenField().'<label>Repository URL<input type="url" name="repository_url" value="'.h($state['repository_url']).'" placeholder="https://github.com/owner/repository" required autocomplete="off"></label><label>Branch<input type="text" name="branch" value="'.h($state['branch']).'" required maxlength="200" autocomplete="off"></label><div class="description-actions github-actions"><button type="submit" name="action" value="generate" class="secondary" id="github-generate" '.($connected?'hidden':'').'>'.activitySpinner(true).'<span class="github-button-label">Generate Deploy Key</span></button><button type="submit" name="action" value="regenerate" class="secondary" id="github-regenerate" '.($state['public_key']===''?'hidden':'').'>'.activitySpinner(true).'<span class="github-button-label">Regenerate Deploy Key</span></button><button type="submit" name="action" value="connect" class="primary'.($connected?' is-connected':'').'" id="github-connect">'.activitySpinner(true).'<span class="github-button-label">'.($connected?'Connected':'Connect').'</span></button></div><div id="github-key-panel" '.($connected||$state['public_key']===''?'hidden':'').'><label>Public Deploy Key<textarea id="github-public-key" readonly rows="3" spellcheck="false">'.h($state['public_key']).'</textarea></label><div class="description-actions"><button type="button" class="secondary" id="github-copy-key">Copy Public Key</button><a class="button secondary" id="github-key-link" href="'.h($keyLink).'" target="_blank" rel="noopener noreferrer">Open Deploy Keys</a></div><p class="hint github-key-help">Add this public key to the repository and enable <strong>Allow write access</strong>.</p><small id="github-fingerprint">'.h($state['fingerprint']).'</small></div><div id="github-status" class="notice '.($connected?'success':'info').'" role="status" aria-live="polite">'.h($status).'</div><small id="github-checked">'.h($checked).'</small><div id="github-error" class="notice error" role="alert" '.($state['error']===''?'hidden':'').'>'.h($state['error']).'</div></form></section>';
}
