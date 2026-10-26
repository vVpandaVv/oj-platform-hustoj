(function () {
  'use strict';

  var registered = false;
  var theme = null;
  var rendererName = 'oj_zelos';
  var categoryCatalog = {
    start: {
      name: '프로그램 시작',
      primary: '#b19a52',
      secondary: '#c4b278',
      tertiary: '#7c692f',
      blockStyle: 'oj_start_blocks',
      types: ['oj_start']
    },
    io: {
      name: '입력·출력',
      primary: '#62aab0',
      secondary: '#88bec2',
      tertiary: '#397a80',
      blockStyle: 'oj_io_blocks',
      categoryStyle: 'oj_io_category',
      types: [
        'oj_input_int', 'oj_input_float', 'oj_input_token', 'oj_input_line',
        'oj_print', 'oj_split_ints', 'oj_print_list',
        'py_to_int', 'py_to_float', 'py_to_string', 'py_to_bool'
      ]
    },
    variable: {
      name: '변수',
      primary: '#63709d',
      secondary: '#8992b4',
      tertiary: '#414d78',
      blockStyle: 'oj_variable_blocks',
      categoryStyle: 'oj_variable_category',
      aliases: ['variable_blocks', 'variable_dynamic_blocks'],
      types: ['variables_get', 'variables_set', 'math_change']
    },
    control: {
      name: '제어',
      primary: '#68a2ba',
      secondary: '#8bb8c9',
      tertiary: '#3f758e',
      blockStyle: 'oj_control_blocks',
      categoryStyle: 'oj_control_category',
      aliases: ['loop_blocks'],
      types: [
        'controls_repeat_ext', 'controls_if', 'controls_whileUntil',
        'controls_for', 'controls_forEach', 'controls_flow_statements'
      ]
    },
    logic: {
      name: '논리',
      primary: '#7a67a5',
      secondary: '#9d90bc',
      tertiary: '#524279',
      blockStyle: 'oj_logic_blocks',
      categoryStyle: 'oj_logic_category',
      aliases: ['logic_blocks'],
      types: [
        'logic_compare', 'logic_operation', 'logic_boolean',
        'logic_negate', 'logic_null'
      ]
    },
    math: {
      name: '수학',
      primary: '#74a789',
      secondary: '#94b8a2',
      tertiary: '#4b7b5d',
      blockStyle: 'oj_math_blocks',
      categoryStyle: 'oj_math_category',
      aliases: ['math_blocks'],
      types: [
        'math_number', 'math_arithmetic', 'math_modulo', 'math_single',
        'math_on_list', 'math_number_property', 'math_round',
        'math_trig', 'math_constant', 'math_constrain'
      ]
    },
    text: {
      name: '문자열',
      primary: '#d38a67',
      secondary: '#e0a488',
      tertiary: '#9b5d42',
      blockStyle: 'oj_text_blocks',
      categoryStyle: 'oj_text_category',
      aliases: ['text_blocks'],
      types: [
        'text', 'text_join', 'text_length', 'text_isEmpty',
        'text_changeCase', 'text_trim', 'py_string_get', 'py_string_slice',
        'py_string_split_ws', 'py_string_split', 'py_string_join',
        'py_string_find', 'py_string_replace'
      ]
    },
    list: {
      name: '자료구조',
      primary: '#4675a6',
      secondary: '#7296bd',
      tertiary: '#2d527b',
      blockStyle: 'oj_list_blocks',
      categoryStyle: 'oj_list_category',
      aliases: ['list_blocks'],
      types: [
        'lists_create_with', 'lists_repeat', 'lists_length', 'lists_isEmpty',
        'lists_reverse', 'py_length', 'py_list_get', 'py_list_set',
        'py_list_append', 'py_list_slice', 'py_list_find', 'py_list_sort',
        'py_tuple_from_list', 'py_tuple_get', 'py_dict_empty',
        'py_dict_from_pairs', 'py_dict_get', 'py_dict_get_default',
        'py_dict_set', 'py_dict_contains', 'py_dict_view', 'py_set_empty',
        'py_set_from_list', 'py_set_add', 'py_set_discard',
        'py_set_contains', 'py_set_operation', 'py_set_to_list'
      ]
    },
    function: {
      name: '함수',
      primary: '#9b5f7d',
      secondary: '#b8849d',
      tertiary: '#6f3d58',
      blockStyle: 'oj_function_blocks',
      categoryStyle: 'oj_function_category',
      aliases: ['procedure_blocks'],
      types: [
        'procedures_defnoreturn', 'procedures_defreturn',
        'procedures_callnoreturn', 'procedures_callreturn',
        'procedures_ifreturn'
      ]
    }
  };

  function escapeFilename(value) {
    return String(value || 'problem').replace(/[^a-zA-Z0-9_-]/g, '_');
  }

  function categoryForType(type) {
    var keys = Object.keys(categoryCatalog);
    for (var index = 0; index < keys.length; index += 1) {
      var category = categoryCatalog[keys[index]];
      if (category.types.indexOf(type) !== -1) {
        return category;
      }
    }
    return categoryCatalog.io;
  }

  function buildTheme() {
    if (theme) {
      return theme;
    }
    var blockStyles = {};
    var categoryStyles = {};
    Object.keys(categoryCatalog).forEach(function (key) {
      var category = categoryCatalog[key];
      var style = {
        colourPrimary: category.primary,
        colourSecondary: category.secondary,
        colourTertiary: category.tertiary
      };
      blockStyles[category.blockStyle] = style;
      (category.aliases || []).forEach(function (alias) {
        blockStyles[alias] = style;
      });
      if (category.categoryStyle) {
        categoryStyles[category.categoryStyle] = {
          colour: category.primary
        };
      }
    });
    theme = Blockly.Theme.defineTheme('ojLearningCategoryTheme', {
      base: Blockly.Themes.Classic,
      blockStyles: blockStyles,
      categoryStyles: categoryStyles,
      componentStyles: {
        workspaceBackgroundColour: '#edf7f6',
        toolboxBackgroundColour: '#ffffff',
        toolboxForegroundColour: '#182126',
        flyoutBackgroundColour: '#f5f7f7',
        flyoutForegroundColour: '#182126',
        flyoutOpacity: 1,
        scrollbarColour: '#bec8cd',
        insertionMarkerColour: '#056560',
        insertionMarkerOpacity: 0.25,
        cursorColour: '#056560'
      }
    });
    return theme;
  }

  function registerRenderer() {
    var type = Blockly.registry.Type.RENDERER;
    if (!Blockly.registry.hasItem(type, rendererName)) {
      class OjZelosConstants extends Blockly.zelos.ConstantProvider {
        constructor() {
          super();
          this.FIELD_DROPDOWN_NO_BORDER_RECT_SHADOW = false;
        }
      }
      class OjZelosRenderer extends Blockly.zelos.Renderer {
        makeConstants_() {
          return new OjZelosConstants();
        }
      }
      Blockly.registry.register(type, rendererName, OjZelosRenderer);
    }
  }

  function applyMessages() {
    Blockly.Msg.VARIABLES_SET = '변수 %1에 값 %2 저장';
    Blockly.Msg.MATH_CHANGE_TITLE = '변수 %1에 %2 더하기';
    Blockly.Msg.CONTROLS_REPEAT_TITLE = '%1번 반복';
    Blockly.Msg.CONTROLS_REPEAT_INPUT_DO = '실행';
    Blockly.Msg.CONTROLS_IF_MSG_IF = '만약';
    Blockly.Msg.CONTROLS_IF_MSG_THEN = '이면 실행';
    Blockly.Msg.CONTROLS_WHILEUNTIL_OPERATOR_WHILE = '조건이 참인 동안';
    Blockly.Msg.CONTROLS_WHILEUNTIL_OPERATOR_UNTIL = '조건이 참이 될 때까지';
    Blockly.Msg.CONTROLS_WHILEUNTIL_INPUT_DO = '반복 실행';
    Blockly.Msg.CONTROLS_FOR_TITLE = '변수 %1: %2부터 %3까지 %4씩 반복';
    Blockly.Msg.CONTROLS_FOR_INPUT_DO = '실행';
    Blockly.Msg.CONTROLS_FOREACH_TITLE = '목록 %2의 각 항목을 %1에 넣어 반복';
    Blockly.Msg.CONTROLS_FOREACH_INPUT_DO = '실행';
    Blockly.Msg.TEXT_LENGTH_TITLE = '문자열 %1의 길이';
    Blockly.Msg.TEXT_ISEMPTY_TITLE = '문자열 %1이 비어 있는가';
    Blockly.Msg.LISTS_REPEAT_TITLE = '값 %1을 %2번 넣은 목록 만들기';
    Blockly.Msg.LISTS_LENGTH_TITLE = '목록 %1의 길이';
    Blockly.Msg.LISTS_ISEMPTY_TITLE = '목록 %1이 비어 있는가';
    Blockly.Msg.LISTS_REVERSE_MESSAGE0 = '목록 %1 뒤집기';
    Blockly.Msg.LISTS_SORT_TITLE = '목록 %3을 %1 기준 %2으로 정렬';
    Blockly.Msg.PROCEDURES_DEFNORETURN_TITLE = '함수 정의';
    Blockly.Msg.PROCEDURES_DEFRETURN_TITLE = '함수 정의';
    Blockly.Msg.PROCEDURES_DEFRETURN_RETURN = '반환할 값';
    Blockly.Msg.PROCEDURES_CALL_BEFORE_PARAMS = '함수 호출';
  }

  function customDefinitions() {
    return [
      {
        type: 'oj_start',
        message0: '프로그램 시작',
        message1: '실행 %1',
        args1: [{ type: 'input_statement', name: 'DO' }],
        tooltip: '이 블록 아래에 실행 순서대로 블록을 연결합니다.'
      },
      { type: 'oj_input_int', message0: '다음 정수 입력', output: 'Number' },
      { type: 'oj_input_float', message0: '다음 실수 입력', output: 'Number' },
      { type: 'oj_input_token', message0: '다음 문자열 입력', output: 'String' },
      { type: 'oj_input_line', message0: '다음 한 줄 입력', output: 'String' },
      {
        type: 'oj_print',
        message0: '값 %1 출력, 끝 처리 %2',
        args0: [
          { type: 'input_value', name: 'VALUE' },
          {
            type: 'field_dropdown',
            name: 'END',
            options: [
              ['줄바꿈', 'NEWLINE'],
              ['공백', 'SPACE'],
              ['없음', 'NONE']
            ]
          }
        ],
        previousStatement: null,
        nextStatement: null
      },
      {
        type: 'oj_split_ints',
        message0: '문자열 %1을 정수 목록으로 바꾸기',
        args0: [{ type: 'input_value', name: 'TEXT', check: 'String' }],
        output: 'Array'
      },
      {
        type: 'oj_print_list',
        message0: '목록 %1을 구분자 %2로 출력',
        args0: [
          { type: 'input_value', name: 'LIST', check: 'Array' },
          { type: 'field_input', name: 'DELIM', text: ' ' }
        ],
        previousStatement: null,
        nextStatement: null
      },
      {
        type: 'py_to_int',
        message0: '값 %1을 정수로 바꾸기',
        args0: [{ type: 'input_value', name: 'VALUE' }],
        output: 'Number'
      },
      {
        type: 'py_to_float',
        message0: '값 %1을 실수로 바꾸기',
        args0: [{ type: 'input_value', name: 'VALUE' }],
        output: 'Number'
      },
      {
        type: 'py_to_string',
        message0: '값 %1을 문자열로 바꾸기',
        args0: [{ type: 'input_value', name: 'VALUE' }],
        output: 'String'
      },
      {
        type: 'py_to_bool',
        message0: '값 %1을 참·거짓으로 바꾸기',
        args0: [{ type: 'input_value', name: 'VALUE' }],
        output: 'Boolean'
      },
      {
        type: 'py_string_split_ws',
        message0: '문자열 %1을 공백 기준으로 나누기',
        args0: [{ type: 'input_value', name: 'TEXT', check: 'String' }],
        output: 'Array'
      },
      {
        type: 'py_string_split',
        message0: '문자열 %1을 구분자 %2로 나누기',
        args0: [
          { type: 'input_value', name: 'TEXT', check: 'String' },
          { type: 'input_value', name: 'DELIMITER', check: 'String' }
        ],
        inputsInline: true,
        output: 'Array'
      },
      {
        type: 'py_string_join',
        message0: '목록 %1을 구분자 %2로 연결하기',
        args0: [
          { type: 'input_value', name: 'LIST', check: 'Array' },
          { type: 'input_value', name: 'DELIMITER', check: 'String' }
        ],
        output: 'String'
      },
      {
        type: 'py_string_find',
        message0: '문자열 %1에서 %2의 위치 찾기',
        args0: [
          { type: 'input_value', name: 'TEXT', check: 'String' },
          { type: 'input_value', name: 'NEEDLE', check: 'String' }
        ],
        inputsInline: true,
        output: 'Number'
      },
      {
        type: 'py_string_replace',
        message0: '문자열 %1 바꾸기, 찾을 내용 %2 새 내용 %3',
        args0: [
          { type: 'input_value', name: 'TEXT', check: 'String' },
          { type: 'input_value', name: 'OLD', check: 'String' },
          { type: 'input_value', name: 'NEW', check: 'String' }
        ],
        inputsInline: true,
        output: 'String'
      },
      {
        type: 'py_string_get',
        message0: '문자열 %1의 위치 %2 문자 가져오기',
        args0: [
          { type: 'input_value', name: 'TEXT', check: 'String' },
          { type: 'input_value', name: 'INDEX', check: 'Number' }
        ],
        inputsInline: true,
        output: 'String'
      },
      {
        type: 'py_string_slice',
        message0: '문자열 %1의 부분 가져오기',
        args0: [{ type: 'input_value', name: 'TEXT', check: 'String' }],
        message1: '시작 위치 %1',
        args1: [{ type: 'input_value', name: 'START', check: 'Number' }],
        message2: '끝 위치 %1 전',
        args2: [{ type: 'input_value', name: 'END', check: 'Number' }],
        output: 'String'
      },
      {
        type: 'py_length',
        message0: '값 %1의 길이',
        args0: [{ type: 'input_value', name: 'VALUE' }],
        output: 'Number'
      },
      {
        type: 'py_list_get',
        message0: '목록 %1 위치 %2의 항목 가져오기',
        args0: [
          { type: 'input_value', name: 'LIST', check: 'Array' },
          { type: 'input_value', name: 'INDEX', check: 'Number' }
        ],
        inputsInline: true,
        output: null
      },
      {
        type: 'py_list_set',
        message0: '목록 %1 위치 %2에 값 %3 저장',
        args0: [
          { type: 'input_value', name: 'LIST', check: 'Array' },
          { type: 'input_value', name: 'INDEX', check: 'Number' },
          { type: 'input_value', name: 'VALUE' }
        ],
        inputsInline: true,
        previousStatement: null,
        nextStatement: null
      },
      {
        type: 'py_list_append',
        message0: '목록 %1에 값 %2 추가',
        args0: [
          { type: 'input_value', name: 'LIST', check: 'Array' },
          { type: 'input_value', name: 'VALUE' }
        ],
        inputsInline: true,
        previousStatement: null,
        nextStatement: null
      },
      {
        type: 'py_list_slice',
        message0: '목록 %1의 부분 가져오기',
        args0: [{ type: 'input_value', name: 'LIST', check: 'Array' }],
        message1: '시작 위치 %1',
        args1: [{ type: 'input_value', name: 'START', check: 'Number' }],
        message2: '끝 위치 %1 전',
        args2: [{ type: 'input_value', name: 'END', check: 'Number' }],
        output: 'Array'
      },
      {
        type: 'py_list_find',
        message0: '목록 %1에서 값 %2의 위치 찾기',
        args0: [
          { type: 'input_value', name: 'LIST', check: 'Array' },
          { type: 'input_value', name: 'VALUE' }
        ],
        inputsInline: true,
        output: 'Number'
      },
      {
        type: 'py_list_sort',
        message0: '목록 %1 정렬',
        args0: [{ type: 'input_value', name: 'LIST', check: 'Array' }],
        message1: '순서 %1',
        args1: [{
          type: 'field_dropdown',
          name: 'ORDER',
          options: [['오름차순', 'ASC'], ['내림차순', 'DESC']]
        }],
        output: 'Array'
      },
      {
        type: 'py_tuple_from_list',
        message0: '목록 %1을 튜플로 바꾸기',
        args0: [{ type: 'input_value', name: 'LIST', check: 'Array' }],
        output: 'Tuple'
      },
      {
        type: 'py_tuple_get',
        message0: '튜플 %1에서 항목 가져오기',
        args0: [{ type: 'input_value', name: 'TUPLE', check: 'Tuple' }],
        message1: '위치 %1',
        args1: [{ type: 'input_value', name: 'INDEX', check: 'Number' }],
        output: null
      },
      { type: 'py_dict_empty', message0: '빈 딕셔너리 만들기', output: 'Dictionary' },
      {
        type: 'py_dict_from_pairs',
        message0: '키·값 쌍 목록 %1을 딕셔너리로 바꾸기',
        args0: [{ type: 'input_value', name: 'PAIRS', check: 'Array' }],
        output: 'Dictionary'
      },
      {
        type: 'py_dict_get',
        message0: '딕셔너리 %1에서 키 %2의 값 가져오기',
        args0: [
          { type: 'input_value', name: 'DICT', check: 'Dictionary' },
          { type: 'input_value', name: 'KEY' }
        ],
        inputsInline: true,
        output: null
      },
      {
        type: 'py_dict_get_default',
        message0: '딕셔너리 %1에서 키 %2의 값 가져오기',
        args0: [
          { type: 'input_value', name: 'DICT', check: 'Dictionary' },
          { type: 'input_value', name: 'KEY' }
        ],
        message1: '키가 없으면 %1',
        args1: [{ type: 'input_value', name: 'DEFAULT' }],
        output: null
      },
      {
        type: 'py_dict_set',
        message0: '딕셔너리 %1의 키 %2에 값 %3 저장',
        args0: [
          { type: 'input_value', name: 'DICT', check: 'Dictionary' },
          { type: 'input_value', name: 'KEY' },
          { type: 'input_value', name: 'VALUE' }
        ],
        inputsInline: true,
        previousStatement: null,
        nextStatement: null
      },
      {
        type: 'py_dict_contains',
        message0: '딕셔너리 %1의 키 포함 여부 %2',
        args0: [
          { type: 'input_value', name: 'DICT', check: 'Dictionary' },
          { type: 'input_value', name: 'KEY' }
        ],
        inputsInline: true,
        output: 'Boolean'
      },
      {
        type: 'py_dict_view',
        message0: '딕셔너리 %1의 %2 목록 가져오기',
        args0: [
          { type: 'input_value', name: 'DICT', check: 'Dictionary' },
          {
            type: 'field_dropdown',
            name: 'VIEW',
            options: [['키', 'KEYS'], ['값', 'VALUES'], ['키·값 쌍', 'ITEMS']]
          }
        ],
        inputsInline: true,
        output: 'Array'
      },
      { type: 'py_set_empty', message0: '빈 셋 만들기', output: 'Set' },
      {
        type: 'py_set_from_list',
        message0: '목록 %1을 셋으로 바꾸기',
        args0: [{ type: 'input_value', name: 'LIST', check: 'Array' }],
        output: 'Set'
      },
      {
        type: 'py_set_add',
        message0: '셋 %1에 값 %2 추가',
        args0: [
          { type: 'input_value', name: 'SET', check: 'Set' },
          { type: 'input_value', name: 'VALUE' }
        ],
        inputsInline: true,
        previousStatement: null,
        nextStatement: null
      },
      {
        type: 'py_set_discard',
        message0: '셋 %1에서 값 %2 제거',
        args0: [
          { type: 'input_value', name: 'SET', check: 'Set' },
          { type: 'input_value', name: 'VALUE' }
        ],
        inputsInline: true,
        previousStatement: null,
        nextStatement: null
      },
      {
        type: 'py_set_contains',
        message0: '셋 %1의 값 포함 여부 %2',
        args0: [
          { type: 'input_value', name: 'SET', check: 'Set' },
          { type: 'input_value', name: 'VALUE' }
        ],
        inputsInline: true,
        output: 'Boolean'
      },
      {
        type: 'py_set_operation',
        message0: '셋 %1과 셋 %2의 %3',
        args0: [
          { type: 'input_value', name: 'LEFT', check: 'Set' },
          { type: 'input_value', name: 'RIGHT', check: 'Set' },
          {
            type: 'field_dropdown',
            name: 'OP',
            options: [
              ['합집합', 'UNION'],
              ['교집합', 'INTERSECTION'],
              ['차집합', 'DIFFERENCE']
            ]
          }
        ],
        inputsInline: true,
        output: 'Set'
      },
      {
        type: 'py_set_to_list',
        message0: '셋 %1을 목록으로 바꾸기',
        args0: [{ type: 'input_value', name: 'SET', check: 'Set' }],
        output: 'Array'
      }
    ];
  }

  function registerBlocks() {
    var definitions = customDefinitions();
    definitions.forEach(function (definition) {
      definition.style = categoryForType(definition.type).blockStyle;
    });
    Blockly.defineBlocksWithJsonArray(definitions);
    Blockly.Blocks.math_round = {
      init: function () {
        this.appendValueInput('NUM')
          .setCheck('Number')
          .appendField(new Blockly.FieldDropdown([
            ['반올림', 'ROUND'],
            ['올림', 'ROUNDUP'],
            ['내림', 'ROUNDDOWN']
          ]), 'OP')
          .appendField('값');
        this.appendValueInput('PLACES')
          .setCheck('Number')
          .appendField('자리수');
        this.setInputsInline(true);
        this.setOutput(true, 'Number');
        this.setStyle(categoryCatalog.math.blockStyle);
      }
    };
  }

  function generator() {
    return window.python && window.python.pythonGenerator
      ? window.python.pythonGenerator
      : null;
  }

  function valueCode(active, block, input, fallback) {
    return active.valueToCode(block, input, active.ORDER_NONE || 0) || fallback;
  }

  function setGenerator(type, callback) {
    var active = generator();
    if (active) {
      active.forBlock[type] = callback;
    }
  }

  function registerGenerators() {
    var active = generator();
    if (!active) {
      throw new Error('Python 생성기를 찾을 수 없습니다.');
    }
    setGenerator('oj_start', function (block, current) {
      var running = current || active;
      var code = running.statementToCode(block, 'DO');
      return code.split('\n').map(function (line) {
        return line.indexOf(running.INDENT) === 0
          ? line.slice(running.INDENT.length)
          : line;
      }).join('\n');
    });
    setGenerator('oj_input_int', function () {
      return ['int(__oj_next())', active.ORDER_FUNCTION_CALL || 0];
    });
    setGenerator('oj_input_float', function () {
      return ['float(__oj_next())', active.ORDER_FUNCTION_CALL || 0];
    });
    setGenerator('oj_input_token', function () {
      return ['__oj_next()', active.ORDER_FUNCTION_CALL || 0];
    });
    setGenerator('oj_input_line', function () {
      return ['__oj_next_line()', active.ORDER_FUNCTION_CALL || 0];
    });
    setGenerator('oj_print', function (block, current) {
      var running = current || active;
      var value = valueCode(running, block, 'VALUE', '""');
      var ending = block.getFieldValue('END');
      var suffix = ending === 'SPACE'
        ? ', end=" "'
        : (ending === 'NONE' ? ', end=""' : '');
      return 'print(' + value + suffix + ')\n';
    });
    setGenerator('oj_split_ints', function (block, current) {
      var running = current || active;
      return [
        'list(map(int, str('
          + valueCode(running, block, 'TEXT', '""') + ').split()))',
        running.ORDER_FUNCTION_CALL || 0
      ];
    });
    setGenerator('oj_print_list', function (block, current) {
      var running = current || active;
      var list = valueCode(running, block, 'LIST', '[]');
      var delimiter = JSON.stringify(String(block.getFieldValue('DELIM') || ' '));
      return 'print(' + delimiter + '.join(map(str, ' + list + ')))\n';
    });
    [
      ['py_to_int', 'int', '0'],
      ['py_to_float', 'float', '0'],
      ['py_to_string', 'str', '""'],
      ['py_to_bool', 'bool', 'False']
    ].forEach(function (definition) {
      setGenerator(definition[0], function (block, current) {
        var running = current || active;
        return [
          definition[1] + '('
            + valueCode(running, block, 'VALUE', definition[2]) + ')',
          running.ORDER_FUNCTION_CALL || 0
        ];
      });
    });
    setGenerator('py_string_split_ws', function (block, current) {
      var running = current || active;
      return [
        '(str(' + valueCode(running, block, 'TEXT', '""') + ')).split()',
        running.ORDER_FUNCTION_CALL || 0
      ];
    });
    setGenerator('py_string_split', function (block, current) {
      var running = current || active;
      return [
        '(str(' + valueCode(running, block, 'TEXT', '""') + ')).split(str('
          + valueCode(running, block, 'DELIMITER', '","') + '))',
        running.ORDER_FUNCTION_CALL || 0
      ];
    });
    setGenerator('py_string_join', function (block, current) {
      var running = current || active;
      return [
        'str(' + valueCode(running, block, 'DELIMITER', '""')
          + ').join(map(str, ' + valueCode(running, block, 'LIST', '[]') + '))',
        running.ORDER_FUNCTION_CALL || 0
      ];
    });
    setGenerator('py_string_find', function (block, current) {
      var running = current || active;
      return [
        '(str(' + valueCode(running, block, 'TEXT', '""') + ')).find(str('
          + valueCode(running, block, 'NEEDLE', '""') + '))',
        running.ORDER_FUNCTION_CALL || 0
      ];
    });
    setGenerator('py_string_replace', function (block, current) {
      var running = current || active;
      return [
        '(str(' + valueCode(running, block, 'TEXT', '""') + ')).replace(str('
          + valueCode(running, block, 'OLD', '""') + '), str('
          + valueCode(running, block, 'NEW', '""') + '))',
        running.ORDER_FUNCTION_CALL || 0
      ];
    });
    setGenerator('py_string_get', function (block, current) {
      var running = current || active;
      return [
        '(str(' + valueCode(running, block, 'TEXT', '""') + '))[int('
          + valueCode(running, block, 'INDEX', '0') + ')]',
        running.ORDER_MEMBER || 0
      ];
    });
    setGenerator('py_string_slice', function (block, current) {
      var running = current || active;
      return [
        '(str(' + valueCode(running, block, 'TEXT', '""') + '))[int('
          + valueCode(running, block, 'START', '0') + '):int('
          + valueCode(running, block, 'END', '0') + ')]',
        running.ORDER_MEMBER || 0
      ];
    });
    setGenerator('py_length', function (block, current) {
      var running = current || active;
      return [
        'len(' + valueCode(running, block, 'VALUE', '[]') + ')',
        running.ORDER_FUNCTION_CALL || 0
      ];
    });
    setGenerator('py_list_get', function (block, current) {
      var running = current || active;
      return [
        '(' + valueCode(running, block, 'LIST', '[]') + ')[int('
          + valueCode(running, block, 'INDEX', '0') + ')]',
        running.ORDER_MEMBER || 0
      ];
    });
    setGenerator('py_list_set', function (block, current) {
      var running = current || active;
      return '(' + valueCode(running, block, 'LIST', '[]') + ')[int('
        + valueCode(running, block, 'INDEX', '0') + ')] = '
        + valueCode(running, block, 'VALUE', 'None') + '\n';
    });
    setGenerator('py_list_append', function (block, current) {
      var running = current || active;
      return '(' + valueCode(running, block, 'LIST', '[]') + ').append('
        + valueCode(running, block, 'VALUE', 'None') + ')\n';
    });
    setGenerator('py_list_slice', function (block, current) {
      var running = current || active;
      return [
        '(' + valueCode(running, block, 'LIST', '[]') + ')[int('
          + valueCode(running, block, 'START', '0') + '):int('
          + valueCode(running, block, 'END', '0') + ')]',
        running.ORDER_MEMBER || 0
      ];
    });
    setGenerator('py_list_find', function (block, current) {
      var running = current || active;
      return [
        '(' + valueCode(running, block, 'LIST', '[]') + ').index('
          + valueCode(running, block, 'VALUE', 'None') + ')',
        running.ORDER_FUNCTION_CALL || 0
      ];
    });
    setGenerator('py_list_sort', function (block, current) {
      var running = current || active;
      return [
        'sorted(' + valueCode(running, block, 'LIST', '[]')
          + (block.getFieldValue('ORDER') === 'DESC' ? ', reverse=True' : '')
          + ')',
        running.ORDER_FUNCTION_CALL || 0
      ];
    });
    setGenerator('py_tuple_from_list', function (block, current) {
      var running = current || active;
      return [
        'tuple(' + valueCode(running, block, 'LIST', '[]') + ')',
        running.ORDER_FUNCTION_CALL || 0
      ];
    });
    setGenerator('py_tuple_get', function (block, current) {
      var running = current || active;
      return [
        '(' + valueCode(running, block, 'TUPLE', '()') + ')[int('
          + valueCode(running, block, 'INDEX', '0') + ')]',
        running.ORDER_MEMBER || 0
      ];
    });
    setGenerator('py_dict_empty', function (block, current) {
      return ['{}', (current || active).ORDER_ATOMIC || 0];
    });
    setGenerator('py_dict_from_pairs', function (block, current) {
      var running = current || active;
      return [
        'dict(' + valueCode(running, block, 'PAIRS', '[]') + ')',
        running.ORDER_FUNCTION_CALL || 0
      ];
    });
    setGenerator('py_dict_get', function (block, current) {
      var running = current || active;
      return [
        '(' + valueCode(running, block, 'DICT', '{}') + ')['
          + valueCode(running, block, 'KEY', 'None') + ']',
        running.ORDER_MEMBER || 0
      ];
    });
    setGenerator('py_dict_get_default', function (block, current) {
      var running = current || active;
      return [
        '(' + valueCode(running, block, 'DICT', '{}') + ').get('
          + valueCode(running, block, 'KEY', 'None') + ', '
          + valueCode(running, block, 'DEFAULT', 'None') + ')',
        running.ORDER_FUNCTION_CALL || 0
      ];
    });
    setGenerator('py_dict_set', function (block, current) {
      var running = current || active;
      return '(' + valueCode(running, block, 'DICT', '{}') + ')['
        + valueCode(running, block, 'KEY', 'None') + '] = '
        + valueCode(running, block, 'VALUE', 'None') + '\n';
    });
    setGenerator('py_dict_contains', function (block, current) {
      var running = current || active;
      return [
        '(' + valueCode(running, block, 'KEY', 'None') + ' in '
          + valueCode(running, block, 'DICT', '{}') + ')',
        running.ORDER_RELATIONAL || 0
      ];
    });
    setGenerator('py_dict_view', function (block, current) {
      var running = current || active;
      var method = {
        KEYS: 'keys',
        VALUES: 'values',
        ITEMS: 'items'
      }[block.getFieldValue('VIEW')] || 'keys';
      return [
        'list((' + valueCode(running, block, 'DICT', '{}') + ').'
          + method + '())',
        running.ORDER_FUNCTION_CALL || 0
      ];
    });
    setGenerator('py_set_empty', function (block, current) {
      return ['set()', (current || active).ORDER_FUNCTION_CALL || 0];
    });
    setGenerator('py_set_from_list', function (block, current) {
      var running = current || active;
      return [
        'set(' + valueCode(running, block, 'LIST', '[]') + ')',
        running.ORDER_FUNCTION_CALL || 0
      ];
    });
    setGenerator('py_set_add', function (block, current) {
      var running = current || active;
      return '(' + valueCode(running, block, 'SET', 'set()') + ').add('
        + valueCode(running, block, 'VALUE', 'None') + ')\n';
    });
    setGenerator('py_set_discard', function (block, current) {
      var running = current || active;
      return '(' + valueCode(running, block, 'SET', 'set()') + ').discard('
        + valueCode(running, block, 'VALUE', 'None') + ')\n';
    });
    setGenerator('py_set_contains', function (block, current) {
      var running = current || active;
      return [
        '(' + valueCode(running, block, 'VALUE', 'None') + ' in '
          + valueCode(running, block, 'SET', 'set()') + ')',
        running.ORDER_RELATIONAL || 0
      ];
    });
    setGenerator('py_set_operation', function (block, current) {
      var running = current || active;
      var operator = {
        UNION: '|',
        INTERSECTION: '&',
        DIFFERENCE: '-'
      }[block.getFieldValue('OP')] || '|';
      return [
        '((' + valueCode(running, block, 'LEFT', 'set()') + ') '
          + operator + ' ('
          + valueCode(running, block, 'RIGHT', 'set()') + '))',
        running.ORDER_NONE || 0
      ];
    });
    setGenerator('py_set_to_list', function (block, current) {
      var running = current || active;
      return [
        'list(' + valueCode(running, block, 'SET', 'set()') + ')',
        running.ORDER_FUNCTION_CALL || 0
      ];
    });
    setGenerator('math_round', function (block, current) {
      var running = current || active;
      var operation = block.getFieldValue('OP') || 'ROUND';
      var number = valueCode(running, block, 'NUM', '0');
      var places = 'int(' + valueCode(running, block, 'PLACES', '0') + ')';
      if (operation === 'ROUND') {
        return [
          'round(' + number + ', ' + places + ')',
          running.ORDER_FUNCTION_CALL || 0
        ];
      }
      running.definitions_.import_math = 'import math';
      var factor = '(10 ** (' + places + '))';
      var method = operation === 'ROUNDUP' ? 'math.ceil' : 'math.floor';
      return [
        '(' + method + '((' + number + ') * ' + factor + ') / ' + factor + ')',
        running.ORDER_FUNCTION_CALL || 0
      ];
    });
  }

  function registerComponents() {
    if (registered) {
      return;
    }
    if (!window.Blockly || !generator()) {
      throw new Error('Blockly 또는 Python 생성기를 찾을 수 없습니다.');
    }
    applyMessages();
    registerRenderer();
    registerBlocks();
    registerGenerators();
    registered = true;
  }

  function block(type, extras) {
    return Object.assign({ kind: 'block', type: type }, extras || {});
  }

  function category(key, contents, custom) {
    var definition = categoryCatalog[key];
    var item = {
      kind: 'category',
      name: definition.name,
      categorystyle: definition.categoryStyle
    };
    if (custom) {
      item.custom = custom;
    } else {
      item.contents = contents;
    }
    return item;
  }

  function toolbox(profile) {
    var io = [
      block('oj_input_int'),
      block('oj_input_float'),
      block('oj_input_token'),
      block('oj_print', {
        inputs: {
          VALUE: { shadow: { type: 'text', fields: { TEXT: '' } } }
        }
      })
    ];
    var control = [
      block('controls_repeat_ext', {
        inputs: {
          TIMES: { shadow: { type: 'math_number', fields: { NUM: 5 } } }
        }
      }),
      block('controls_if')
    ];
    var logic = [
      block('logic_compare'),
      block('logic_operation'),
      block('logic_boolean')
    ];
    var math = [
      block('math_number', { fields: { NUM: 0 } }),
      block('math_arithmetic'),
      block('math_modulo')
    ];
    var text = [];
    var list = [];
    if (profile !== 'intro') {
      io.splice(
        3,
        0,
        block('oj_input_line'),
        block('oj_split_ints'),
        block('oj_print_list'),
        block('py_to_int'),
        block('py_to_float'),
        block('py_to_string'),
        block('py_to_bool')
      );
      control.push(
        block('controls_whileUntil'),
        block('controls_for'),
        block('controls_forEach'),
        block('controls_flow_statements')
      );
      logic.push(block('logic_negate'), block('logic_null'));
      math.push(
        block('math_single'),
        block('math_on_list'),
        block('math_number_property'),
        block('math_round')
      );
      text = [
        block('text'),
        block('text_join'),
        block('text_length'),
        block('text_isEmpty'),
        block('text_changeCase'),
        block('text_trim'),
        block('py_string_get'),
        block('py_string_slice'),
        block('py_string_split_ws'),
        block('py_string_split'),
        block('py_string_join'),
        block('py_string_find'),
        block('py_string_replace')
      ];
      list = [
        block('py_length'),
        block('lists_create_with'),
        block('lists_repeat'),
        block('lists_length'),
        block('lists_isEmpty'),
        block('lists_reverse'),
        block('py_list_get'),
        block('py_list_set'),
        block('py_list_append'),
        block('py_list_slice'),
        block('py_list_find'),
        block('py_list_sort'),
        block('py_tuple_from_list'),
        block('py_tuple_get')
      ];
    }
    if (profile === 'extended') {
      math.push(
        block('math_trig'),
        block('math_constant'),
        block('math_constrain')
      );
      list.push(
        { kind: 'sep', gap: '24' },
        block('py_dict_empty'),
        block('py_dict_from_pairs'),
        block('py_dict_get'),
        block('py_dict_get_default'),
        block('py_dict_set'),
        block('py_dict_contains'),
        block('py_dict_view'),
        { kind: 'sep', gap: '24' },
        block('py_set_empty'),
        block('py_set_from_list'),
        block('py_set_add'),
        block('py_set_discard'),
        block('py_set_contains'),
        block('py_set_operation'),
        block('py_set_to_list')
      );
    }
    var contents = [
      category('io', io),
      category('variable', [], 'VARIABLE'),
      category('control', control),
      category('logic', logic),
      category('math', math)
    ];
    if (profile !== 'intro') {
      contents.push(category('text', text), category('list', list));
    }
    if (profile === 'extended') {
      contents.push(category('function', [], 'PROCEDURE'));
    }
    return { kind: 'categoryToolbox', contents: contents };
  }

  function inputPrelude() {
    return [
      'import sys',
      '',
      'class __OjInput:',
      '    def __init__(self, text):',
      '        self.text = text',
      '        self.position = 0',
      '',
      '    def next(self):',
      '        size = len(self.text)',
      '        while self.position < size and self.text[self.position].isspace():',
      '            self.position += 1',
      '        start = self.position',
      '        while self.position < size and not self.text[self.position].isspace():',
      '            self.position += 1',
      '        return self.text[start:self.position]',
      '',
      '    def line(self):',
      '        size = len(self.text)',
      '        while self.position < size and self.text[self.position] in "\\r\\n":',
      '            self.position += 1',
      '        start = self.position',
      '        while self.position < size and self.text[self.position] not in "\\r\\n":',
      '            self.position += 1',
      '        return self.text[start:self.position].lstrip(" \\t")',
      '',
      '__oj_reader = __OjInput(sys.stdin.read())',
      '',
      'def __oj_next():',
      '    return __oj_reader.next()',
      '',
      'def __oj_next_line():',
      '    return __oj_reader.line()'
    ].join('\n');
  }

  function mount(root) {
    if (root.getAttribute('data-mounted') === 'true') {
      return;
    }
    registerComponents();
    var contract = JSON.parse(root.getAttribute('data-contract') || '{}');
    if (String(Blockly.VERSION) !== String(contract.engine_version)) {
      throw new Error('Blockly 엔진 버전이 서버 계약과 다릅니다.');
    }
    var workspaceElement = root.querySelector('[data-oj-blockly-workspace]');
    var profileSelect = root.querySelector('[data-oj-blockly-profile]');
    var profileValue = root.querySelector('[data-oj-blockly-profile-value]');
    var payload = root.querySelector('[data-oj-blockly-payload]');
    var code = root.querySelector('[data-oj-blockly-code]');
    var status = root.querySelector('[data-oj-blockly-status]');
    var fileInput = root.querySelector('[data-oj-blockly-file]');
    var problemId = root.getAttribute('data-problem-id') || 'unknown';
    var maximumBytes = Number(root.getAttribute('data-maximum-bytes')) || 524288;
    var storageKey = 'oj_blockly_draft_' + problemId;
    var activeProfile = profileSelect.value || contract.default_profile || 'standard';
    var saveTimer = null;
    var workspace = Blockly.inject(workspaceElement, {
      toolbox: toolbox(activeProfile),
      theme: buildTheme(),
      renderer: rendererName,
      trashcan: true,
      zoom: {
        controls: true,
        wheel: true,
        startScale: 0.9,
        maxScale: 1.5,
        minScale: 0.5,
        scaleSpeed: 1.1
      },
      move: {
        scrollbars: true,
        drag: true,
        wheel: true
      }
    });

    function packageWorkspace() {
      return {
        format: contract.format,
        engine_version: contract.engine_version,
        registry_version: contract.registry_version,
        generator_version: contract.generator_version,
        profile: activeProfile,
        workspace: Blockly.serialization.workspaces.save(workspace)
      };
    }

    function hasInputBlocks() {
      return workspace.getAllBlocks(false).some(function (item) {
        return [
          'oj_input_int', 'oj_input_float', 'oj_input_token', 'oj_input_line'
        ].indexOf(item.type) !== -1;
      });
    }

    function validate() {
      var allowed = contract.allowed_types[activeProfile] || [];
      var invalid = workspace.getAllBlocks(false).filter(function (item) {
        return allowed.indexOf(item.type) === -1;
      });
      var top = workspace.getTopBlocks(false);
      var starts = top.filter(function (item) {
        return item.type === 'oj_start';
      });
      var disconnected = top.filter(function (item) {
        return item.type !== 'oj_start'
          && item.type.indexOf('procedures_def') !== 0;
      });
      var valid = invalid.length === 0
        && starts.length === 1
        && disconnected.length === 0
        && starts[0].getInputTargetBlock('DO');
      status.classList.toggle('is-error', !valid);
      status.textContent = valid
        ? (contract.profiles[activeProfile] + ' 프로필 · 제출 가능')
        : '시작 블록과 연결 상태를 확인해 주세요.';
      return Boolean(valid);
    }

    function update() {
      var generated = generator().workspaceToCode(workspace).trim();
      if (hasInputBlocks()) {
        generated = inputPrelude() + '\n\n' + generated;
      }
      code.textContent = (generated || '# 실행할 블록을 연결하세요.') + '\n';
      var packaged = JSON.stringify(packageWorkspace());
      payload.value = packaged;
      profileValue.value = activeProfile;
      validate();
      window.clearTimeout(saveTimer);
      saveTimer = window.setTimeout(function () {
        if (packaged.length <= maximumBytes) {
          try {
            window.sessionStorage.setItem(storageKey, packaged);
          } catch (error) {
          }
        }
      }, 1000);
    }

    function loadPackage(value) {
      if (
        !value
        || value.format !== contract.format
        || value.engine_version !== contract.engine_version
        || value.registry_version !== contract.registry_version
        || value.generator_version !== contract.generator_version
        || !contract.profiles[value.profile]
        || !value.workspace
      ) {
        throw new Error('현재 버전에서 사용할 수 없는 Blockly 파일입니다.');
      }
      var serialized = JSON.stringify(value);
      if (serialized.length > maximumBytes) {
        throw new Error('Blockly 작업공간 최대 크기를 초과했습니다.');
      }
      activeProfile = value.profile;
      profileSelect.value = activeProfile;
      workspace.updateToolbox(toolbox(activeProfile));
      workspace.clear();
      Blockly.serialization.workspaces.load(value.workspace, workspace);
      lockStart();
      if (!validate()) {
        throw new Error('현재 프로필에서 허용되지 않는 블록이 있습니다.');
      }
      update();
    }

    function createStart() {
      Blockly.serialization.workspaces.load({
        blocks: {
          blocks: [{ type: 'oj_start', x: 32, y: 32 }]
        }
      }, workspace);
      lockStart();
    }

    function lockStart() {
      workspace.getBlocksByType('oj_start', false).forEach(function (item) {
        item.setDeletable(false);
        item.setMovable(true);
      });
    }

    try {
      var saved = window.sessionStorage.getItem(storageKey);
      if (saved) {
        loadPackage(JSON.parse(saved));
      } else {
        createStart();
      }
    } catch (error) {
      workspace.clear();
      createStart();
      status.textContent = error.message;
      status.classList.add('is-error');
    }

    workspace.addChangeListener(function (event) {
      if (event.isUiEvent) {
        return;
      }
      lockStart();
      update();
    });
    profileSelect.addEventListener('change', function () {
      var candidate = profileSelect.value;
      var allowed = contract.allowed_types[candidate] || [];
      var invalid = workspace.getAllBlocks(false).some(function (item) {
        return allowed.indexOf(item.type) === -1;
      });
      if (invalid) {
        profileSelect.value = activeProfile;
        status.textContent = '현재 블록을 포함할 수 있는 프로필을 선택해 주세요.';
        status.classList.add('is-error');
        return;
      }
      activeProfile = candidate;
      workspace.updateToolbox(toolbox(activeProfile));
      update();
    });

    root.querySelector('[data-oj-blockly-import]').addEventListener('click', function () {
      fileInput.click();
    });
    fileInput.addEventListener('change', function () {
      var file = fileInput.files && fileInput.files[0];
      if (!file || file.size > maximumBytes) {
        status.textContent = '불러올 파일의 크기를 확인해 주세요.';
        status.classList.add('is-error');
        return;
      }
      file.text().then(function (contents) {
        loadPackage(JSON.parse(contents));
      }).catch(function (error) {
        status.textContent = error.message || 'Blockly 파일을 불러오지 못했습니다.';
        status.classList.add('is-error');
      }).finally(function () {
        fileInput.value = '';
      });
    });
    root.querySelector('[data-oj-blockly-export]').addEventListener('click', function () {
      var contents = JSON.stringify(packageWorkspace(), null, 2);
      var blob = new Blob([contents], { type: 'application/json;charset=UTF-8' });
      var link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.download = 'problem-' + escapeFilename(problemId) + '.ojblocks.json';
      link.click();
      window.setTimeout(function () {
        URL.revokeObjectURL(link.href);
      }, 0);
    });
    root.querySelector('[data-oj-blockly-to-code]').addEventListener('click', function () {
      if (!validate()) {
        return;
      }
      if (!window.confirm('생성 코드를 직접 편집기로 옮기면 블록 동기화가 끝납니다. 계속할까요?')) {
        return;
      }
      var form = root.closest('form');
      var source = form.querySelector('[data-oj-source-editor]');
      var codeMode = form.querySelector('input[name="submission_mode"][value="code"]');
      if (source && codeMode) {
        source.value = code.textContent;
        source.dispatchEvent(new Event('input', { bubbles: true }));
        codeMode.checked = true;
        codeMode.dispatchEvent(new Event('change', { bubbles: true }));
        source.dispatchEvent(new Event('oj-source-focus'));
      }
    });
    window.addEventListener('resize', function () {
      Blockly.svgResize(workspace);
    });
    root.closest('form').addEventListener('submit', function (event) {
      update();
      if (!validate() || payload.value.length > maximumBytes) {
        event.preventDefault();
        status.textContent = payload.value.length > maximumBytes
          ? 'Blockly 작업공간 최대 크기를 초과했습니다.'
          : '블록 연결 상태를 확인해 주세요.';
        status.classList.add('is-error');
        status.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });
    update();
    root.setAttribute('data-mounted', 'true');
  }

  window.OJPlatformBlockly = Object.freeze({
    mount: mount
  });
}());
