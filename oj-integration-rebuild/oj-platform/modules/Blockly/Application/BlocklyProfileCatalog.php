<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Blockly\Application;

use DomainException;

final class BlocklyProfileCatalog
{
    public const ENGINE_VERSION = '12.5.1';

    public const FORMAT = 'blockly-json-v1';

    public const REGISTRY_VERSION = 'oj-blocks-v1';

    public const GENERATOR_VERSION = 'oj-python-v1';

    /**
     * @var array<string, string>
     */
    private const PROFILES = [
        'intro' => '입문',
        'standard' => '표준',
        'extended' => '확장',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const CATEGORY_TYPES = [
        'start' => ['oj_start'],
        'io_intro' => [
            'oj_input_int',
            'oj_input_float',
            'oj_input_token',
            'oj_print',
        ],
        'io_standard' => [
            'oj_input_line',
            'oj_split_ints',
            'oj_print_list',
            'py_to_int',
            'py_to_float',
            'py_to_string',
            'py_to_bool',
        ],
        'variable_intro' => [
            'variables_get',
            'variables_set',
        ],
        'variable_standard' => ['math_change'],
        'control_intro' => [
            'controls_repeat_ext',
            'controls_if',
        ],
        'control_standard' => [
            'controls_whileUntil',
            'controls_for',
            'controls_forEach',
            'controls_flow_statements',
        ],
        'logic_intro' => [
            'logic_compare',
            'logic_operation',
            'logic_boolean',
        ],
        'logic_standard' => [
            'logic_negate',
            'logic_null',
        ],
        'math_intro' => [
            'math_number',
            'math_arithmetic',
            'math_modulo',
        ],
        'math_standard' => [
            'math_single',
            'math_on_list',
            'math_number_property',
            'math_round',
        ],
        'math_extended' => [
            'math_trig',
            'math_constant',
            'math_constrain',
        ],
        'text_standard' => [
            'text',
            'text_join',
            'text_length',
            'text_isEmpty',
            'text_changeCase',
            'text_trim',
            'py_string_get',
            'py_string_slice',
            'py_string_split_ws',
            'py_string_split',
            'py_string_join',
            'py_string_find',
            'py_string_replace',
        ],
        'list_standard' => [
            'lists_create_with',
            'lists_repeat',
            'lists_length',
            'lists_isEmpty',
            'lists_reverse',
            'py_length',
            'py_list_get',
            'py_list_set',
            'py_list_append',
            'py_list_slice',
            'py_list_find',
            'py_list_sort',
            'py_tuple_from_list',
            'py_tuple_get',
        ],
        'data_extended' => [
            'py_dict_empty',
            'py_dict_from_pairs',
            'py_dict_get',
            'py_dict_get_default',
            'py_dict_set',
            'py_dict_contains',
            'py_dict_view',
            'py_set_empty',
            'py_set_from_list',
            'py_set_add',
            'py_set_discard',
            'py_set_contains',
            'py_set_operation',
            'py_set_to_list',
        ],
        'function_extended' => [
            'procedures_defnoreturn',
            'procedures_defreturn',
            'procedures_callnoreturn',
            'procedures_callreturn',
            'procedures_ifreturn',
        ],
    ];

    /**
     * @return array<string, string>
     */
    public function profiles(): array
    {
        return self::PROFILES;
    }

    public function label(string $profile): string
    {
        return self::PROFILES[$profile] ?? '알 수 없는 프로필';
    }

    public function assertProfile(string $profile): void
    {
        if (! isset(self::PROFILES[$profile])) {
            throw new DomainException('Blockly 도구상자 프로필이 올바르지 않습니다.');
        }
    }

    /**
     * @return list<string>
     */
    public function allowedTypes(string $profile): array
    {
        $this->assertProfile($profile);
        $groups = [
            'start',
            'io_intro',
            'variable_intro',
            'control_intro',
            'logic_intro',
            'math_intro',
        ];
        if ($profile !== 'intro') {
            array_push(
                $groups,
                'io_standard',
                'variable_standard',
                'control_standard',
                'logic_standard',
                'math_standard',
                'text_standard',
                'list_standard'
            );
        }
        if ($profile === 'extended') {
            array_push(
                $groups,
                'math_extended',
                'data_extended',
                'function_extended'
            );
        }
        $types = [];
        foreach ($groups as $group) {
            array_push($types, ...self::CATEGORY_TYPES[$group]);
        }

        return array_values(array_unique($types));
    }

    /**
     * @return array<string, mixed>
     */
    public function clientContract(string $defaultProfile): array
    {
        $this->assertProfile($defaultProfile);

        return [
            'engine_version' => self::ENGINE_VERSION,
            'format' => self::FORMAT,
            'registry_version' => self::REGISTRY_VERSION,
            'generator_version' => self::GENERATOR_VERSION,
            'default_profile' => $defaultProfile,
            'profiles' => self::PROFILES,
            'allowed_types' => [
                'intro' => $this->allowedTypes('intro'),
                'standard' => $this->allowedTypes('standard'),
                'extended' => $this->allowedTypes('extended'),
            ],
        ];
    }
}
