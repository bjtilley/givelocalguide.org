<?php
// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

class Email_Template {
    public function render($template_name, $variables = []) {
        $template_path = plugin_dir_path(__FILE__) . "../templates/emails/{$template_name}.html";
        $content = file_get_contents($template_path);
        // Replace {{ variable }} with optional whitespace
        $content = preg_replace_callback('/{{\s*(\w+)\s*}}/', function ($matches) use ($variables) {
            $key = $matches[1];
            return isset($variables[$key]) ? esc_html($variables[$key]) : $matches[0];
        }, $content);
        return $content;
    }
}