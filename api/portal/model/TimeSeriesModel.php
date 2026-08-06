<?php
// +----------------------------------------------------------------------
// | ThinkCMF [ WE CAN DO IT MORE SIMPLE ]
// +----------------------------------------------------------------------
// | Copyright (c) 2013-2017 http://www.thinkcmf.com All rights reserved.
// +----------------------------------------------------------------------
// | Author: pl125 <xskjs888@163.com>
// +----------------------------------------------------------------------

namespace api\portal\model;

use think\Model;

class TimeSeriesModel extends Model
{
    /**
     * 模型名称
     * @var string
     */
    protected $name = 'time_series';

    // 开启自动写入时间戳字段
    protected $autoWriteTimestamp = true;
}