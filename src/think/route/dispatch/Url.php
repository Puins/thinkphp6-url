<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2021 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
declare(strict_types=1);

namespace think\route\dispatch;

use think\exception\HttpException;
use think\helper\Str;
use think\Request;
use think\route\Rule;
use think\route\dispatch\Controller;

/**
 * Url Dispatcher
 */
class Url extends Controller
{
    /**
     * 配置参数
     * @var array
     */
    protected $config = [
        // 自动搜索控制器
        'controller_auto_search' => true,
    ];

    public function __construct(Request $request, Rule $rule, $dispatch)
    {
        $this->request = $request;
        $this->rule    = $rule;

        if (!is_null($this->rule->config('controller_auto_search'))) {
            $this->config['controller_auto_search'] = $this->rule->config('controller_auto_search');
        }

        // 解析默认的URL规则
        $dispatch = $this->parseUrl($dispatch);

        parent::__construct($request, $rule, $dispatch, $this->param);
    }

    /**
     * 解析URL地址
     * @access protected
     * @param  string $url URL
     * @return array
     */
    protected function parseUrl(string $url): array
    {
        $depr = $this->rule->config('pathinfo_depr');
        $bind = $this->rule->getRouter()->getDomainBind();

        if ($bind && preg_match('/^[a-z]/is', $bind)) {
            $bind = str_replace('/', $depr, $bind);
            // 如果有域名绑定
            $url = $bind . ('.' != substr($bind, -1) ? $depr : '') . ltrim($url, $depr);
        }

        $path = $this->rule->parseUrlPath($url);
        if (empty($path)) {
            return [null, null];
        }

        // 解析控制器
        if ($this->config['controller_auto_search']) {
            $app = app('http')->getName();
            $controller = $this->autoFindController($app, $path);
        } else {
            $controller = !empty($path) ? array_shift($path) : null;
        }

        if ($controller && !preg_match('/^[A-Za-z0-9][\w|\.]*$/', $controller)) {
            throw new HttpException(404, 'controller not exists:' . $controller);
        }

        // 解析操作
        $action = !empty($path) ? array_shift($path) : null;
        $var    = [];

        // 解析额外参数
        if ($path) {
            preg_replace_callback('/(\w+)\|([^\|]+)/', function ($match) use (&$var) {
                $var[$match[1]] = strip_tags($match[2]);
            }, implode('|', $path));
        }

        $panDomain = $this->request->panDomain();
        if ($panDomain && $key = array_search('*', $var)) {
            // 泛域名赋值
            $var[$key] = $panDomain;
        }

        // 设置当前请求的参数
        $this->param = $var;

        // 封装路由
        $route = [$controller, $action];

        if ($this->hasDefinedRoute($route)) {
            throw new HttpException(404, 'invalid request:' . str_replace('|', $depr, $url));
        }

        return $route;
    }

    /**
     * 检查URL是否已经定义过路由
     * @access protected
     * @param  array $route 路由信息
     * @return bool
     */
    protected function hasDefinedRoute(array $route): bool
    {
        [$controller, $action] = $route;

        // 检查地址是否被定义过路由
        $name = strtolower(Str::studly($controller) . '/' . $action);

        $host   = $this->request->host(true);
        $method = $this->request->method();

        if ($this->rule->getRouter()->getName($name, $host, $method)) {
            return true;
        }

        return false;
    }

    /**
     * 自动定位控制器类
     * @access protected
     * @param  string    $app 应用名
     * @param  array     $path   URL
     * @return string
     */
    protected function autoFindController($app, &$path)
    {
        $dir    = app()->getAppPath() .  $this->rule->config('controller_layer');
        $suffix = $this->rule->config('controller_suffix') ? ucfirst($this->rule->config('controller_layer')) : '';

        $item = [];
        $find = false;

        foreach ($path as $val) {
            $item[] = $val;
            $file   = $dir . '/' . str_replace('.', '/', $val) . $suffix . '.php';
            $file   = pathinfo($file, PATHINFO_DIRNAME) . '/' . parse_name(pathinfo($file, PATHINFO_FILENAME), 1) . '.php';
            if (is_file($file)) {
                $find = true;
                break;
            } else {
                $dir .= '/' . parse_name($val);
            }
        }

        if ($find) {
            $controller = implode('.', $item);
            $path       = array_slice($path, count($item));
        } else {
            $controller = array_shift($path);
        }

        return $controller;
    }
}
