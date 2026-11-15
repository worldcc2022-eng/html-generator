<?php
declare(strict_types=1);
final class BroadcastDurations {
 public static function defaults():array{return ['Event'=>120,'Soccer'=>130,'Football'=>130,'Tennis'=>360,'NFL'=>120,'NBA'=>150,'Basketball'=>150,'NHL'=>180,'MLB'=>210,'Baseball'=>210,'Cricket'=>480,'Rugby'=>130,'Golf'=>360,'Boxing'=>240,'MMA'=>240,'UFC'=>240,'Motorsport'=>180,'Wrestling'=>180,'Marathon'=>360,'College Football'=>270,'CFL'=>270,'WNBA'=>150,'AFL'=>210,'Soccer (Knockout)'=>210,'Tennis (Best of Three)'=>240,'Tennis (Best of Five)'=>360,'Tennis (Session)'=>480,'Cricket (T20)'=>270,'Cricket (ODI)'=>540,'Cricket (Test Day)'=>480,'Rugby Union'=>180,'Rugby League'=>180,'UFC (Main Card)'=>240,'UFC (Full Card)'=>420,'MMA (Main Card)'=>240,'MMA (Full Card)'=>420,'Boxing (Main Event)'=>120,'Boxing (Full Card)'=>420,'Formula 1'=>180,'NASCAR'=>300,'MotoGP'=>120,'Badminton'=>120,'Volleyball'=>180,'Snooker'=>300];}
 public static function read(string $json,int $version=2):array {
  if($json==='')return self::defaults();$values=json_decode($json,true,512,JSON_THROW_ON_ERROR);if(!is_array($values)||!$values)throw new InvalidArgumentException('Save valid broadcast durations in Settings.');
  $config=self::parse(array_map('strval',array_keys($values)),array_values($values),array_fill(0,count($values),'minutes'));$result=[];$general=null;$other=null;
  foreach($config as $sport=>$minutes){$key=mb_strtolower((string)$sport,'UTF-8');if($key==='event')$general=$minutes;elseif($key==='other')$other=$minutes;else $result[$sport]=$minutes;}
  $result=['Event'=>$general??$other??120]+$result;
  if($version<2){$seen=[];foreach($result as $sport=>$minutes)$seen[mb_strtolower((string)$sport,'UTF-8')]=true;foreach(self::defaults() as $sport=>$minutes)if(!isset($seen[mb_strtolower($sport,'UTF-8')]))$result[$sport]=$minutes;}
  return $result;
 }
 public static function parse(mixed $sports,mixed $values,mixed $units):array {
  if(!is_array($sports)||!is_array($values)||!is_array($units)||!$sports||count($sports)>100||count($sports)!==count($values)||count($sports)!==count($units))throw new InvalidArgumentException('Add 1–100 sports with broadcast durations.');
  $result=[];$seen=[];
  foreach(array_values($sports) as $i=>$sport){
   $value=array_values($values)[$i];$unit=array_values($units)[$i];
   if(!is_string($sport)||!mb_check_encoding($sport,'UTF-8')||($sport=trim($sport))===''||mb_strlen($sport)>100||preg_match('/[<>\x00-\x1f]/u',$sport))throw new InvalidArgumentException('Enter a valid sport name, up to 100 characters.');
   $key=mb_strtolower($sport,'UTF-8');if(isset($seen[$key]))throw new InvalidArgumentException('Each sport may have only one broadcast duration.');$seen[$key]=true;
   if((!is_string($value)&&!is_int($value)&&!is_float($value))||!is_numeric($value)||!in_array($unit,['minutes','hours'],true))throw new InvalidArgumentException('Enter a numeric duration in minutes or hours.');
   $minutes=(float)$value*($unit==='hours'?60:1);if(!is_finite($minutes)||$minutes<1||$minutes>10080||abs($minutes-round($minutes))>0.00001)throw new InvalidArgumentException('Duration must equal a whole number of minutes from 1 to 10,080.');$result[$sport]=(int)round($minutes);
  }return $result;
 }
 public static function minutes(array $config,string $sport):int {
  $normalized=mb_strtolower(trim($sport),'UTF-8');$fallback=120;$hasGeneral=false;foreach($config as $name=>$minutes){$key=mb_strtolower(trim((string)$name),'UTF-8');if($key==='event'){$fallback=$minutes;$hasGeneral=true;}elseif($key==='other'&&!$hasGeneral)$fallback=$minutes;if($key===$normalized)return $minutes;}return $fallback;
 }
 public static function forGeneration(array $config,string $selected,string $sport,mixed $value=null,mixed $unit=null):int {
  if($selected!=='Event'||($value===null&&$unit===null))return self::minutes($config,$sport);
  return self::parse(['Event'],[$value],[$unit])['Event'];
 }
 public static function generalControl(array $config):string {
  $minutes=self::minutes($config,'Event');$hours=$minutes%60===0;$value=$hours?intdiv($minutes,60):$minutes;
  return '<div id="general-broadcast-duration" class="grid"><label>Custom Broadcast Duration<input name="broadcast_duration" type="number" min="0.0166666667" max="10080" step="any" value="'.$value.'" required></label><label>Duration Unit<select name="broadcast_duration_unit"><option value="minutes" '.(!$hours?'selected':'').'>Minutes</option><option value="hours" '.($hours?'selected':'').'>Hours</option></select></label></div>';
 }
 public static function options(array $config):string {
  $html='<option value="Event">General Event</option>';foreach($config as $sport=>$minutes)if(!in_array(mb_strtolower((string)$sport,'UTF-8'),['event','other'],true))$html.='<option value="'.htmlspecialchars((string)$sport,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'">'.htmlspecialchars((string)$sport,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</option>';return $html;
 }
 public static function row(string $sport='',int $minutes=120):string {
  $hours=$minutes%60===0;$value=$hours?intdiv($minutes,60):$minutes;
  return '<div class="grid" data-duration-row><label>Sport<input name="broadcast_sport[]" value="'.htmlspecialchars((string)$sport,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'" maxlength="100" required></label><label>Duration<input name="broadcast_value[]" type="number" step="any" min="0.0166666667" max="10080" value="'.$value.'" required></label><label>Unit<select name="broadcast_unit[]"><option value="minutes" '.(!$hours?'selected':'').'>Minutes</option><option value="hours" '.($hours?'selected':'').'>Hours</option></select></label><button type="button" class="secondary" data-remove-duration>Remove</button></div>';
 }
 public static function editor(array $config):string {
  $rows='';foreach($config as $sport=>$minutes)$rows.=self::row((string)$sport,$minutes);
  return '<hr><details id="broadcast-durations"><summary><h2 style="display:inline;margin:0">Broadcast Durations</h2></summary><div class="stack"><p class="hint">Estimated live window by sport. Broadcast start uses schema time; end adds this duration. General Event is the fallback for unlisted sports.</p><input type="hidden" name="broadcast_durations_present" value="1"><div id="broadcast-duration-rows" class="stack">'.$rows.'</div><template id="broadcast-duration-template">'.self::row().'</template><button type="button" class="secondary" id="add-broadcast-sport">Add Sport</button></div></details>';
 }
}
