<?php
require __DIR__.'/../app/Generator.php';
require __DIR__.'/../app/IdBank.php';
function checkId(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$date=new DateTimeImmutable('2026-10-31');
checkId(IdBank::render('video-{event_name}_liv','PSG vs Barcelona',$date)==='video-psg-vs-barcelona-liv','lowercase dashed event placeholder');
checkId(IdBank::render('{event_name}-{event_name}',' Lakers   vs. Warriors ',$date)==='lakers-vs-warriors-lakers-vs-warriors','repeated placeholder and punctuation normalize safely');
checkId(IdBank::validateTemplate(' VIDEO-{EVENT_NAME}_US1 ',1)==='video-{event_name}-us1','saved literal text lowercase');
foreach(['{event_name}.html','../{event_name}','{event_name}?a=1','no-placeholder','{event_name}-{date}'] as $bad){$rejected=false;try{IdBank::validateTemplate($bad,1);}catch(InvalidArgumentException){$rejected=true;}checkId($rejected,'reject unsafe template '.$bad);}
$rejected=false;try{IdBank::render('{event_name}','!!!',$date);}catch(InvalidArgumentException){$rejected=true;}checkId($rejected,'empty event slug rejected');
$rejected=false;try{IdBank::render('prefix-{event_name}',str_repeat('a',240),$date);}catch(InvalidArgumentException){$rejected=true;}checkId($rejected,'overlong filename rejected');
echo "ALL ID BANK UNIT TESTS PASSED\n";
