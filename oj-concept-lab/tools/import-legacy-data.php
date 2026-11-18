<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
require dirname(__DIR__,2).'/edu-games/tests/bootstrap.php';
$map=['outlier-detection'=>'Outlier_Detection','missing-values'=>'Missing_Values','data-visualization'=>'Data_Visualization','data-extraction'=>'Data_Extraction','data-structure-lab'=>'Data_Structure_Lab','ai-classifier-lab'=>'AI_Classifier_Lab'];
$data=[];
foreach($map as $id=>$name) {
    $class='EduGames\\Games\\Modules\\'.$name.'\\'.$name.'_Module';
    $data[$id]=array_values($class::rules()['samples']);
    echo $id.': '.count($data[$id]).PHP_EOL;
}
file_put_contents(dirname(__DIR__).'/resources/data/examples.json',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL);
