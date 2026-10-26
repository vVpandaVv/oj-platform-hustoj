<?php
declare(strict_types=1);

namespace EduGames\Games;

use EduGames\Games\Contracts\Game_Module;
use EduGames\Games\Modules\AI_Classifier_Lab\AI_Classifier_Lab_Module;
use EduGames\Games\Modules\AI_Model_Lab\AI_Model_Lab_Module;
use EduGames\Games\Modules\Binary_Conversion\Binary_Conversion_Module;
use EduGames\Games\Modules\Character_Code\Character_Code_Module;
use EduGames\Games\Modules\Code_Typing\Code_Typing_Module;
use EduGames\Games\Modules\Data_Extraction\Data_Extraction_Module;
use EduGames\Games\Modules\Data_Visualization\Data_Visualization_Module;
use EduGames\Games\Modules\Data_Structure_Lab\Data_Structure_Lab_Module;
use EduGames\Games\Modules\Device_Matching\Device_Matching_Module;
use EduGames\Games\Modules\Image_Data\Image_Data_Module;
use EduGames\Games\Modules\Keyword_Rain\Keyword_Rain_Module;
use EduGames\Games\Modules\Missing_Values\Missing_Values_Module;
use EduGames\Games\Modules\Outlier_Detection\Outlier_Detection_Module;

final class Game_Module_Registry {
    /** @var array<string, Game_Module> */
    private array $modules = array();

    public function __construct() {
        $this->register(new Binary_Conversion_Module());
        $this->register(new Character_Code_Module());
        $this->register(new Image_Data_Module());
        $this->register(new Outlier_Detection_Module());
        $this->register(new Missing_Values_Module());
        $this->register(new Data_Visualization_Module());
        $this->register(new Data_Extraction_Module());
        $this->register(new Data_Structure_Lab_Module());
        $this->register(new AI_Classifier_Lab_Module());
        $this->register(new AI_Model_Lab_Module());
        $this->register(new Code_Typing_Module());
        $this->register(new Device_Matching_Module());
        $this->register(new Keyword_Rain_Module());
    }

    public function register(Game_Module $module): void {
        $this->modules[$module->id()] = $module;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(bool $apply_visibility = true): array {
        $definitions = array();

        foreach ($this->modules as $id => $module) {
            $definition = $module->definition();
            $definitions[$id] = $apply_visibility ? Game_Visibility::apply($definition) : $definition;
        }

        return $definitions;
    }
}
