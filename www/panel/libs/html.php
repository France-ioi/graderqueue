<?php

    class HTML {

        static function italic($text) {
            return '<i>'.$text.'</i>';
        }

        static function hint($caption, $content) {
            return '<span class="hint" title="'.$content.'">'.$caption.'</span>';
        }

        static function error($text) {
            return '<span class="error">'.$text.'</span>';
        }

        static function json($json_str) {
            return '<button class="btn btn-xs btn-info btn-json" onclick="ui.previewJSON(this)">{json}<code>'.$json_str.'</code></button>';
        }

        static function expander($caption, $content, $type = '') {
            return
                '<span>'.
                    $caption.
                    '<button class="btn btn-xs btn-default" onclick="ui.toggleExpander(this)">...</button>'.
                    '<pre style="display: none">'.htmlspecialchars($content).'</pre>'.
                '</span>';
        }

        static function lines() {
            $res = array();
            for($i=0; $i<func_num_args(); $i++) {
                $arg = func_get_arg($i);
                if(is_array($arg)) {
                    foreach($arg as $l) {
                        if(!is_null($l)) $res[] = $l;
                    }
                } else if(!is_null($arg)) $res[] = $arg;
            }
            return implode('<br/>', $res);
        }

    }