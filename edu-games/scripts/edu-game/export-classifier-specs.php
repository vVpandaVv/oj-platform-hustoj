<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
define('EDU_GAMES_PATH',dirname(__DIR__,2).'/');
require dirname(__DIR__,2).'/src/Core/Autoloader.php';
EduGames\Core\Autoloader::register();
echo json_encode(EduGames\Games\Modules\AI_Classifier_Lab\Strategy_Board::experiment_data(),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
