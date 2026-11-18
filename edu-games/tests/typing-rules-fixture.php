<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use EduGames\Typing\Rules;
$cases=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$results=array();
foreach($cases as $case){$s=Rules::create($case['samples'],60,95,1000,$case['version']??Rules::VERSION,$case['lineEnding']??'enter');$s=Rules::apply($s,$case['events'],1005);$results[]=Rules::metrics($s,1005);}
echo json_encode($results,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
