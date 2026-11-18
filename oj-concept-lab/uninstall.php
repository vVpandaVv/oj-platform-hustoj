<?php
defined('WP_UNINSTALL_PLUGIN') || exit;
// Learning work is local; uninstall never touches Edu Games scores or attempts.
if(defined('OJ_CONCEPT_LAB_REMOVE_DATA')&&OJ_CONCEPT_LAB_REMOVE_DATA) delete_option('oj_concept_lab_closed');
