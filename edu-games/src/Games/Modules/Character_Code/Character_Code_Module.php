<?php
declare(strict_types=1);

namespace EduGames\Games\Modules\Character_Code;

use EduGames\Games\Contracts\Game_Module;

final class Character_Code_Module implements Game_Module {
    public const ID = 'character-code';
    public const SHORTCODE = 'character_code';
    public const BLOCK = 'edu-games/character-code';

    public function id(): string {
        return self::ID;
    }

    public function definition(): array {
        return array(
            'id' => $this->id(),
            'title' => __('문자 코드 암호 게임', 'edu-games'),
            'category' => 'data-basics',
            'description' => __('알파벳, 공백, 숫자를 코드표로 암호화하고 복호화하는 타임어택 퍼즐입니다.', 'edu-games'),
            'status' => 'playable',
            'statusLabel' => __('플레이 가능', 'edu-games'),
            'block' => self::BLOCK,
            'shortcode' => self::SHORTCODE,
            'rules' => self::rules(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array {
        return array(
            'symbols' => array_merge(range('A', 'Z'), array(' '), array_map('strval', range(0, 9))),
            'phraseMetadata' => self::phrase_metadata(),
            'codeRange' => array(
                'min' => 10,
                'max' => 99,
            ),
            'defaultMode' => 'decode',
            'defaultDifficulty' => 'easy',
            'modes' => array(
                'decode' => array(
                    'label' => __('복호화', 'edu-games'),
                    'baseScore' => 90,
                ),
                'encode' => array(
                    'label' => __('암호화', 'edu-games'),
                    'baseScore' => 110,
                ),
                'rebuild' => array(
                    'label' => __('코드표 복원', 'edu-games'),
                    'baseScore' => 140,
                ),
            ),
            'difficulties' => array(
                'easy' => array(
                    'label' => __('쉬움', 'edu-games'),
                    'multiplier' => 1.0,
                    'roundTimeSeconds' => 24,
                    'minimumRoundTimeSeconds' => 12,
                    'timeDecreasePerStep' => 2,
                    'correctAnswersPerStep' => 3,
                    'phrases' => self::easy_phrases(),
                ),
                'normal' => array(
                    'label' => __('보통', 'edu-games'),
                    'multiplier' => 1.4,
                    'roundTimeSeconds' => 38,
                    'minimumRoundTimeSeconds' => 20,
                    'timeDecreasePerStep' => 2,
                    'correctAnswersPerStep' => 3,
                    'phrases' => self::normal_phrases(),
                ),
                'hard' => array(
                    'label' => __('어려움', 'edu-games'),
                    'multiplier' => 1.8,
                    'roundTimeSeconds' => 55,
                    'minimumRoundTimeSeconds' => 30,
                    'timeDecreasePerStep' => 2,
                    'correctAnswersPerStep' => 3,
                    'phrases' => self::hard_phrases(),
                ),
            ),
            'scoring' => array(
                'lengthBonusPerCharacter' => 4,
                'streakBonusPerAnswer' => 8,
                'maxStreakBonus' => 60,
                'rankedMistakePenalty' => 20,
                'practiceMistakePenalty' => 8,
                'hintPenalty' => 10,
                'minimumRoundScore' => 20,
            ),
        );
    }

    /**
     * @return array<int, string>
     */
    private static function phrase_metadata(): array {
        $groups=array(
            '자연·환경'=>'BIRD EARTH FARM FISH GARDEN MOON OCEAN RIVER STONE TIGER AUTUMN PLANET STAR DESERT FLOWER FOREST ENERGY ISLAND MOUNTAIN RAINY WHALE SUN WATER LEAF TREE SEA WIND RAIN SNOW',
            '예술·문화'=>'ART BOOK DANCE JAZZ MUSIC PIANO ANCIENT MUSEUM HISTORY LIBRARY PAINT SONG CULTURE THEATER FILM PHOTO',
            '음식·생활'=>'APPLE CAFE HONEY LEMON PASTA SALAD SLEEP BAKERY COOKING FAMILY HEALTHY STREET WINTER BREAD BREAKFAST DINNER FOOD FRUIT KITCHEN',
            '운동·여가'=>'SPORT YOGA BASKET MEDAL SOCCER CAMP TENNIS RUN SWIM BIKE GAME TEAM',
            '과학·기술'=>'SCIENCE SPACE SOLAR ROBOT DIGITAL DATA',
            '교통·여행'=>'TRAIN TRAVEL BUS AIRPORT MAP ROAD CITY',
        );
        $out=array();
        foreach(array_merge(self::easy_phrases(),self::normal_phrases(),self::hard_phrases()) as $phrase){
            $tags=array();$words=explode(' ',$phrase);
            foreach($groups as $label=>$terms){if(array_intersect($words,explode(' ',$terms))){$tags[]=$label;}}
            $out[$phrase]=array('tags'=>$tags?:array('일상·사회'),'length'=>strlen($phrase),'uniqueSymbols'=>count(array_unique(str_split($phrase))));
        }
        return $out;
    }

    private static function easy_phrases(): array {
        return array(
            'APPLE 3',
            'ART 7',
            'BIRD 2',
            'BOOK 5',
            'CAFE 9',
            'DANCE',
            'EARTH',
            'FARM 8',
            'FISH 4',
            'GARDEN',
            'HONEY',
            'JAZZ 6',
            'LEMON',
            'MOON 1',
            'MUSIC',
            'OCEAN',
            'PASTA',
            'PIANO',
            'RIVER',
            'SALAD',
            'SLEEP',
            'SPORT',
            'STONE',
            'TIGER',
            'TRAIN',
            'YOGA 2',
        );
    }

    /**
     * @return array<int, string>
     */
    private static function normal_phrases(): array {
        return array(
            'ANCIENT MAP',
            'AUTUMN RAIN',
            'BAKERY 24',
            'BASKET TEAM',
            'BLUE PLANET',
            'BRIGHT STAR',
            'CITY MUSEUM',
            'COOKING CLASS',
            'DESERT WIND',
            'FAMILY DINNER',
            'FLOWER MARKET',
            'FOREST WALK',
            'GOLD MEDAL',
            'GREEN ENERGY',
            'HEALTHY HABIT',
            'HISTORY 101',
            'ISLAND TRIP',
            'LIBRARY CARD',
            'MORNING NEWS',
            'MOUNTAIN 12',
            'PAINT BRUSH',
            'RAINY SEASON',
            'SCIENCE FAIR',
            'SOCCER MATCH',
            'SPACE MISSION',
            'STREET FOOD',
            'SUMMER CAMP',
            'TENNIS COURT',
            'WHALE SONG',
            'WINTER COAT',
        );
    }

    /**
     * @return array<int, string>
     */
    private static function hard_phrases(): array {
        return array(
            'AURORA OVER NORTH SEA',
            'BALANCED DAILY ROUTINE',
            'BOTANICAL GARDEN TOUR',
            'CLASSICAL MUSIC CONCERT',
            'COMMUNITY GARDEN 2026',
            'DEEP OCEAN EXPLORER',
            'FARMERS MARKET MORNING',
            'FESTIVAL PARADE AT 8',
            'FOSSIL HUNTER JOURNAL',
            'GLOBAL WEATHER REPORT',
            'HEALTHY SCHOOL LUNCH',
            'HISTORIC RAILWAY STATION',
            'INTERNATIONAL ART FAIR',
            'MOUNTAIN RESCUE TEAM',
            'NATIONAL PARK TRAIL 15',
            'OCEAN CLEANUP PROJECT',
            'PHOTOGRAPHY FIELD NOTE',
            'PLANETARIUM NIGHT SHOW',
            'PUBLIC LIBRARY ARCHIVE',
            'RAINFOREST ANIMAL SURVEY',
            'RECIPE FOR TOMATO SOUP',
            'RIVER BRIDGE RESTORATION',
            'SCIENCE MUSEUM WORKSHOP',
            'SILK ROAD HISTORY MAP',
            'SOLAR ECLIPSE VIEWING',
            'THEATER LIGHTING CREW',
            'TRAVEL JOURNAL PAGE 9',
            'URBAN FARMING PROJECT',
            'VILLAGE MUSIC FESTIVAL',
            'WILDLIFE PHOTO EXHIBIT',
        );
    }
}
