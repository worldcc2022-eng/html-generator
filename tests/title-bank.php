<?php
require __DIR__.'/../app/TitleBank.php';
function titleCheck($ok,$label){if(!$ok)throw new Exception($label);echo "PASS $label\n";}
titleCheck(TitleBank::keywordHash('  PSG   vs Barcelona ' )===TitleBank::keywordHash('psg vs barcelona'),'keyword case and whitespace normalized');
titleCheck(TitleBank::render('[𝐋𝐈𝐕𝐄] {event_name} TV — {event_date}','Vienna Open 2026',new DateTimeImmutable('2026-10-31'))==='[𝐋𝐈𝐕𝐄] Vienna Open 2026 TV — 31 October','only saved template placeholders replaced');
try{TitleBank::validateTemplate('A title without a placeholder',4);throw new Exception('missing placeholder accepted');}catch(InvalidArgumentException $e){titleCheck(str_contains($e->getMessage(),'4'),'missing event placeholder explains line');}
titleCheck(TitleBank::templateHash('[LIVE] {event_name}')===TitleBank::templateHash('  [live]   {event_name}  '),'duplicate title patterns normalized');
titleCheck(TitleBank::render('[LIVE] {event_name} — {date}','PSG vs Barcelona',new DateTimeImmutable('2026-10-31'))==='[LIVE] PSG vs Barcelona — 31 October 2026','date placeholder renders selected event date');
titleCheck(TitleBank::render('{event_name} {date} / {event_date} / {date}','Event',new DateTimeImmutable('2028-02-29'))==='Event 29 February 2028 / 29 February 2028 / 29 February 2028','both date aliases work together including leap day');
titleCheck(TitleBank::render('++[{event}@TV] {event_name} LIVE {event} {date}',"Navarrete vs Foster",new DateTimeImmutable('2026-10-31'),'Boxing')==='++[Boxing@TV] Navarrete vs Foster LIVE Boxing 31 October 2026','event type placeholder replaces every occurrence');
titleCheck(TitleBank::render('{event} {event_name}','Final',new DateTimeImmutable('2026-10-31'),'Championship Night')==='Championship Night Final','manual event type supports multiple words');
titleCheck(TitleBank::render('++[𝐒𝐓𝐑𝐄𝐀𝐌𝐈𝐍𝐆] {event_name} ＬＩＶＥ ＯＮ ＴＶ {date}','Chelsea vs Manchester United 2026',new DateTimeImmutable('2026-10-31'))==='++[𝐒𝐓𝐑𝐄𝐀𝐌𝐈𝐍𝐆] Chelsea vs Manchester United 2026 ＬＩＶＥ ＯＮ ＴＶ 31 October','event year not repeated in date placeholder');
titleCheck(TitleBank::render('{event_name} {date} / {event_date}','Event 2026',new DateTimeImmutable('2026-10-31'))==='Event 2026 31 October / 31 October','both aliases omit matching event year');
titleCheck(TitleBank::render('{event_name} {date}','Season 2026',new DateTimeImmutable('2027-01-01'))==='Season 2026 1 January 2027','different date year remains visible');
titleCheck(TitleBank::render('{event_name} {date}','Event 20260',new DateTimeImmutable('2026-10-31'))==='Event 20260 31 October 2026','year inside a longer number does not match');
echo "ALL TITLE BANK UNIT TESTS PASSED\n";
