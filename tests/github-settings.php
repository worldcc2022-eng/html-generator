<?php
require __DIR__.'/../app/GitHubSettings.php';
function h($s){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
function tokenField(){return '<input name="csrf" value="test-csrf">';}
function activitySpinner($hidden=false){return '<span class="activity-spinner" hidden></span>';}
function setting($key,$default=''){return $GLOBALS['state']??$default;}
function panelCheck($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$GLOBALS['state']='{}';$out=githubConnectPanel();
panelCheck(str_contains($out,'<h2>Github Connect</h2>')&&str_contains($out,'name="repository_url"'),'settings section and repository field rendered');
panelCheck(str_contains($out,'test-csrf')&&str_contains($out,'/api/github-connect'),'own POST form has CSRF');
panelCheck(str_contains($out,'Not connected')&&!str_contains($out,'ready to upload'),'fresh configuration is not connected');
$GLOBALS['state']=json_encode(['repository'=>'owner/repo','branch'=>'main','private_key'=>'NEVER-EXPOSE-THIS','public_key'=>'ssh-ed25519 AAAATEST key','status'=>'connected','checked_at'=>'2026-11-02T00:00:00Z']);$out=githubConnectPanel();
panelCheck(!str_contains($out,'NEVER-EXPOSE-THIS'),'rendered settings exclude private key');
panelCheck(str_contains($out,'Connected — ready to upload')&&str_contains($out,'Bangladesh time'),'saved checked status displayed with local time');
panelCheck(str_contains($out,'https://github.com/owner/repo/settings/keys')&&str_contains($out,'Allow write access'),'repository key link and setup instructions displayed');
$GLOBALS['state']=json_encode(['repository'=>'owner/repo','branch'=>'main','status'=>'disconnected','error'=>'<script>alert(1)</script>']);$out=githubConnectPanel();
panelCheck(!str_contains($out,'<script>')&&str_contains($out,'&lt;script&gt;'),'error output escaped');
panelCheck(!str_contains($out,'is-connected'),'failed state does not show green Connected button');
$GLOBALS['state']=json_encode(['repository'=>'owner/repo','branch'=>'main','status'=>'connected']);$out=githubConnectPanel();
panelCheck(str_contains($out,'is-connected')&&str_contains($out,'github-button-label'), 'connected button has persistent green state and labels');
panelCheck(substr_count($out,'activity-spinner')===2,'both action buttons contain their own spinner');
echo "ALL GITHUB SETTINGS TESTS PASSED\n";
