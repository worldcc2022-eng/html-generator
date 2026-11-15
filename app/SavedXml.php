<?php
declare(strict_types=1);
function saveXmlUrl(int $user,string $value,string $target='github'):string {
 if(!in_array($target,['github','server'],true))throw new InvalidArgumentException('Choose GitHub or Server XML.');
 $value=trim($value);EventGenerator::xmlBase64($value);
 saveSetting(($target==='server'?'server_xml_url_':'xml_url_').$user,$value);return $value;
}
function savedXmlForGeneration(int $user,string $provided,string $target='github'):string {
 if(!in_array($target,['github','server'],true))throw new InvalidArgumentException('Choose GitHub or Server XML.');
 $saved=setting(($target==='server'?'server_xml_url_':'xml_url_').$user);return $saved!==''?$saved:$provided;
}
function savedXmlControl(int $user):string {
 $html='<div id="xml-destinations" class="stack">';
 foreach(['github'=>'GitHub','server'=>'Server'] as $target=>$name){$url=setting(($target==='server'?'server_xml_url_':'xml_url_').$user);$saved=$url!=='';$suffix=$target==='github'?'':'-server';
 $html.='<div class="xml-url-control" data-xml-target="'.$target.'"><label class="toggle-row"><span>'.$name.' XML URL</span><input type="radio" name="xml_target" value="'.$target.'" role="switch" '.($target==='github'?'checked':'').'><span class="toggle-track" aria-hidden="true"></span></label><div class="xml-url-row"><input id="xml-url-input'.$suffix.'" aria-label="'.$name.' XML URL" type="text" name="'.($target==='github'?'xml_path':'server_xml_path').'" value="'.h($url).'"'.($saved?' readonly':($target==='github'?' required':'')).'><button type="button" class="secondary" id="xml-url-save'.$suffix.'" data-saved="'.($saved?'true':'false').'">'.activitySpinner(true).'<span class="xml-save-label">'.($saved?'Replace':'Save').'</span></button></div><div id="xml-url-result'.$suffix.'" class="notice" role="status" hidden></div></div>';
 }return $html.'</div>';
}
