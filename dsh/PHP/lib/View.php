<?php
/**
 * View —— 极简模板渲染
 *
 * templates/layout.php 是整站骨架（含导航栏），其余模板是片段。
 */
declare(strict_types=1);

require_once __DIR__ . '/Config.php';

final class View
{
    /** 渲染一个模板片段，返回 HTML */
    public static function render(string $template, array $data = []): string
    {
        $file = Config::path('templates', $template . '.php');
        if (!is_file($file)) {
            return '';
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }

    /**
     * 渲染整页。
     *
     * @param array{
     *   title?:string, nav?:string, bodyClass?:string, styles?:string[],
     *   content:string, math?:bool, macros?:array, description?:string
     * } $options
     */
    public static function page(array $options): void
    {
        $options += [
            'title'       => Config::SITE_NAME,
            'nav'         => '',
            'bodyClass'   => 'theme-neutral',
            'styles'      => [],
            'content'     => '',
            'math'        => false,
            'macros'      => [],
            'description' => Config::SITE_SUBTITLE,
        ];
        extract($options, EXTR_SKIP);
        include Config::path('templates', 'layout.php');
    }
}
