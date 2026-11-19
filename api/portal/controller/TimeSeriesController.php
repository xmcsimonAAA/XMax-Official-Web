<?php
// +----------------------------------------------------------------------
// | ThinkCMF [ WE CAN DO IT MORE SIMPLE ]
// +----------------------------------------------------------------------
// | Copyright (c) 2013-2019 http://www.thinkcmf.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: Admin <admin@example.com>
// +----------------------------------------------------------------------
namespace api\portal\controller;

// use api\portal\model\TimeSeriesModel;
use cmf\controller\RestBaseController;
use think\db\Query;
use think\facade\Cache;

class TimeSeriesController extends RestBaseController
{
   // API配置
    private $apiKey = '';
    private $apiKey2 = '';
    private $baseUrl = 'https://api.twelvedata.com';
    private $symbol = 'XMAX';
    private $exchange = 'NASDAQ';
    private $interval = '1min';
    private $order = 'asc';
    private $limitBeing = 8; //每分钟限制8次请求
    private $limitDaily = 800;  // 每天限制800次请求


    protected function initialize()
    {
        $this->apiKey = trim((string)env('TWELVEDATA_API_KEY', ''));
        $this->apiKey2 = trim((string)env('TWELVEDATA_API_KEY_2', ''));
    }

    /**
     * 获取时间序列数据接口
     * @return \think\response\Json
     */
    public function getData()
    {
        $openingDate = $this->getCurrentOrLastOpeningDate();

        // 从当前日期开始往前找，直到找到有缓存数据的一天
        $allData = [];

        for ($i = 0; $i <= 7; $i++) {
            $checkDate = date('Y-m-d', strtotime("{$openingDate} -{$i} days"));
            $cacheKey = 'time_series_data_' . $checkDate;
            $allData = Cache::get($cacheKey, []);

            if (!empty($allData)) {
                break;
            }
        }

        $quoteCacheKey = 'quote_data_' . $this->symbol;
        $quoteData = Cache::get($quoteCacheKey, []);

        // 兜底：若缓存完全为空（首次部署或全过期），调用带自愈的读取逻辑
        if (empty($allData) && empty($quoteData)) {
            $completeData = $this->getLastCompleteData(true);
            if ($completeData['timeSeries'] !== '无数据') {
                $allData = $completeData['timeSeries'];
            }
            if ($completeData['quote'] !== '无数据') {
                $quoteData = $completeData['quote'];
            }
        }

        if (empty($allData)) {
            $allData = '无数据';
        }

        if (empty($quoteData)) {
            $quoteData = '无数据';
        }

        $this->success('获取成功', [
            'allData' => $allData,
            'quoteData' => $quoteData,
        ]);
    }

    /**
     * 清空缓存记录
     */
    public function clearCache()
    {
        for ($i = 0; $i < 10; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $cacheKey = 'time_series_data_' . $date;
            Cache::delete($cacheKey);
        }
        Cache::delete('quote_data_' . $this->symbol);
        Cache::delete('last_full_data');
        Cache::delete('time_series_last_bootstrap');
        Cache::delete('time_series_bootstrap_lock');
        Cache::delete('time_series_self_heal_lock');

        $this->success('缓存已清空');
    }

    /**
     * 获取当前或上一个开盘日期的字符串标识
     * @return string
     */
    private function getCurrentOrLastOpeningDate()
    {
        $now = new \DateTime('now', new \DateTimeZone('America/New_York'));
        $dayOfWeek = (int)$now->format('w'); // 0=周日, 6=周六

        // 周末直接返回周五
        if ($dayOfWeek === 0) {
            // 周日，往前推2天到周五
            $lastTradingDay = date('Y-m-d', strtotime('-2 days'));
            return $lastTradingDay;
        } elseif ($dayOfWeek === 6) {
            // 周六，往前推1天到周五
            $lastTradingDay = date('Y-m-d', strtotime('-1 day'));
            return $lastTradingDay;
        }

        // 工作日，判断是否还没到开盘时间
        $today = $now->format('Y-m-d');
        $openingTime = $this->getOpeningHourNY($today);

        // 如果当前时间还没到开盘时间，则获取前一个交易日
        if ($now < $openingTime) {
            $yesterday = new \DateTime('yesterday', new \DateTimeZone('America/New_York'));
            $yesterdayDayOfWeek = (int)$yesterday->format('w');

            // 如果昨天是周末，往前推到周五
            if ($yesterdayDayOfWeek === 0) {
                $lastTradingDay = date('Y-m-d', strtotime('-3 days'));
            } elseif ($yesterdayDayOfWeek === 6) {
                $lastTradingDay = date('Y-m-d', strtotime('-2 days'));
            } else {
                $lastTradingDay = date('Y-m-d', strtotime('-1 day'));
            }

            return $lastTradingDay;
        }

        return $today;
    }

    /**
     * 获取纽约时区的开盘时间
     * @param string $date 日期
     * @return \DateTime
     */
    private function getOpeningHourNY($date)
    {
        // 纽约时间开盘时间固定为 09:30 ET
        return new \DateTime("{$date} 09:30:00", new \DateTimeZone('America/New_York'));
    }

    /**
     * 从API获取数据并存入数据库（供内部调用）
     * @return array
     */
    private function fetchAndSaveData()
    {
        $limitCheck = $this->checkRequestLimit();

        if (!$limitCheck['allowed']) {
            return ['success' => false, 'error' => '请求频率超限'];
        }

        $apiKey = $limitCheck['key'];

        // 使用最近一个交易日作为起始日，避免今天尚未产生数据导致接口返回空
        $startDate = $this->getCurrentOrLastOpeningDate();
        $endDate = date('Y-m-d', strtotime("{$startDate} +1 day"));
        $url = "{$this->baseUrl}/time_series?apikey={$apiKey}&symbol={$this->symbol}&exchange={$this->exchange}&interval={$this->interval}&order={$this->order}&start_date={$startDate}&end_date={$endDate}";

        $response = $this->httpGet($url);

        if (!$response) {
            return ['success' => false, 'error' => 'API请求失败'];
        }

        $data = json_decode($response, true);
        if (!isset($data['values']) || !is_array($data['values'])) {
            $errorMsg = 'API返回数据格式错误';
            cmf_log($errorMsg . ': ' . substr($response, 0, 500));
            return ['success' => false, 'error' => $errorMsg];
        }

        $this->incrementKeyCount($limitCheck['prefix']);

        // 获取当前key的使用情况
        $minuteKey = "time_series_minute_count_{$limitCheck['prefix']}";
        $dailyKey = "time_series_daily_count_{$limitCheck['prefix']}";
        $usedMinute = (int)Cache::get($minuteKey, 0);
        $usedDaily = (int)Cache::get($dailyKey, 0);
        $remainingMinute = $this->limitBeing - $usedMinute;
        $remainingDaily = $this->limitDaily - $usedDaily;

        // 获取已有缓存数据
        $cacheKey = 'time_series_data_' . $startDate;
        $existingData = Cache::get($cacheKey, []);

        // 计算新增数据条数
        $existingDates = array_column($existingData, 'datetime');
        $addCount = 0;
        foreach ($data['values'] as $item) {
            if (!in_array($item['datetime'], $existingDates)) {
                $addCount++;
            }
        }

        // 合并新数据到已有数据中
        $mergeResult = $this->mergeTimeSeriesData($existingData, $data['values']);
        $mergedData = $mergeResult['data'];
        $updateCount = $mergeResult['updateCount'];

        // 保存合并后的数据（有效期7天）
        Cache::set($cacheKey, $mergedData, 604800);

        return [
            'success' => true,
            'allCount' => count($data['values']),
            'addCount' => $addCount,
            'updateCount' => $updateCount,
            'data' => $mergedData,
            'keyInfo' => [
                'prefix' => $limitCheck['prefix'],
                'usedMinute' => $usedMinute,
                'remainingMinute' => $remainingMinute,
                'usedDaily' => $usedDaily,
                'remainingDaily' => $remainingDaily
            ]
        ];
    }

    /**
     * 从API获取数据并存入数据库（供外部直接调用）
     */
    public function fetchAndSave()
    {
        $result = $this->fetchAndSaveData();
        if (!$result['success']) {
            $this->error($result['error']);
        }
        $this->success('数据获取并保存成功', $result);
    }

    /**
     * 检查请求频率限制
     * @return array ['allowed' => bool, 'key' => string, 'prefix' => string]
     */
    private function checkRequestLimit()
    {
        $keys = [
            ['key' => $this->apiKey, 'prefix' => 'key1'],
            ['key' => $this->apiKey2, 'prefix' => 'key2'],
        ];

        foreach ($keys as $keyInfo) {
            if ($keyInfo['key'] === '') {
                continue;
            }

            $prefix = $keyInfo['prefix'];
            $minuteKey = "time_series_minute_count_{$prefix}";
            $dailyKey = "time_series_daily_count_{$prefix}";
            $dailyDateKey = "time_series_daily_date_{$prefix}";

            $currentMinute = (int)Cache::get($minuteKey, 0);
            $currentDaily = (int)Cache::get($dailyKey, 0);
            $lastDailyDate = Cache::get($dailyDateKey, '');

            $today = date('Y-m-d');
            if ($lastDailyDate !== $today) {
                Cache::set($dailyKey, 0);
                Cache::set($dailyDateKey, $today);
                $currentDaily = 0;
            }

            if ($currentMinute < $this->limitBeing && $currentDaily < $this->limitDaily) {
                return ['allowed' => true, 'key' => $keyInfo['key'], 'prefix' => $keyInfo['prefix']];
            }
        }

        return ['allowed' => false, 'key' => null, 'prefix' => null];
    }

    /**
     * 请求成功后增加计数
     * @param string $prefix
     */
    private function incrementKeyCount($prefix)
    {
        $minuteKey = "time_series_minute_count_{$prefix}";
        $dailyKey = "time_series_daily_count_{$prefix}";

        $currentMinute = (int)Cache::get($minuteKey, 0);
        $currentDaily = (int)Cache::get($dailyKey, 0);

        Cache::set($minuteKey, $currentMinute + 1, 60);
        Cache::set($dailyKey, $currentDaily + 1);
    }

    /**
     * 合并时间序列数据并去重
     * @param array $existingData 已有数据
     * @param array $newData 新数据
     * @return array ['data' => array, 'updateCount' => int]
     */
    private function mergeTimeSeriesData($existingData, $newData)
    {
        $merged = [];
        $seen = [];
        $updateCount = 0;

        // 先添加已有数据
        foreach ($existingData as $item) {
            $datetime = $item['datetime'];
            if (!isset($seen[$datetime])) {
                $seen[$datetime] = $item;
                $merged[] = $item;
            }
        }

        // 再处理新数据（去重+覆盖）
        foreach ($newData as $item) {
            $datetime = $item['datetime'];
            if (!isset($seen[$datetime])) {
                // 新时间点，直接添加
                $seen[$datetime] = $item;
                $merged[] = $item;
            } else {
                // 已存在的时间点，检查是否需要覆盖
                $existingItem = $seen[$datetime];
                // 如果旧数据volume为0且新数据volume不为0，则覆盖
                if (($existingItem['volume'] == 0 || $existingItem['volume'] === '0')
                    && $item['volume'] != 0 && $item['volume'] !== '0'
                ) {
                    // 在merged数组中找到对应项并替换
                    foreach ($merged as $key => $mergedItem) {
                        if ($mergedItem['datetime'] === $datetime) {
                            $merged[$key] = $item;
                            $seen[$datetime] = $item;
                            $updateCount++;
                            break;
                        }
                    }
                }
            }
        }

        // 按时间排序（从早到晚）
        usort($merged, function ($a, $b) {
            return strcmp($a['datetime'], $b['datetime']);
        });

        return ['data' => $merged, 'updateCount' => $updateCount];
    }

    /**
     * 判断当前是否在美股交易时段
     * @return bool
     */
    private function isInTradingWindow()
    {
        $now = new \DateTime('now', new \DateTimeZone('America/New_York'));
        $dayOfWeek = (int)$now->format('w'); // 0=周日, 6=周六

        // 排除周末
        if ($dayOfWeek === 0 || $dayOfWeek === 6) {
            return false;
        }

        $hour = (int)$now->format('G');
        $minute = (int)$now->format('i');
        $currentTime = $hour * 60 + $minute;

        // 纽约时间交易时段固定为：09:30 - 16:00 ET
        $start = 9 * 60 + 30;
        $end = 16 * 60;

        return $currentTime >= $start && $currentTime < $end;
    }

    /**
     * 获取当年3月第二个周日
     * @return \DateTime
     */
    private function getSecondSundayOfMarch()
    {
        $year = (int)(new \DateTime('now', new \DateTimeZone('America/New_York')))->format('Y');
        $date = new \DateTime("{$year}-03-01", new \DateTimeZone('America/New_York'));
        $dayOfWeek = (int)$date->format('w');
        $daysToAdd = $dayOfWeek === 0 ? 7 : (7 - $dayOfWeek + 7) % 7 + 7;
        $date->modify("+{$daysToAdd} days");
        return $date;
    }

    /**
     * 获取当年11月第一个周日
     * @return \DateTime
     */
    private function getFirstSundayOfNovember()
    {
        $year = (int)(new \DateTime('now', new \DateTimeZone('America/New_York')))->format('Y');
        $date = new \DateTime("{$year}-11-01", new \DateTimeZone('America/New_York'));
        $dayOfWeek = (int)$date->format('w');
        $daysToAdd = $dayOfWeek === 0 ? 0 : 7 - $dayOfWeek;
        $date->modify("+{$daysToAdd} days");
        return $date;
    }
    // /**
    //  * 从Yahoo获取数据
    //  */
    // public function getDataFromYahoo()
    // {
    //     $symbol = $this->request->param('symbol', 'XWIN');
    //     $range = $this->request->param('range', '1d');
    //     $interval = $this->request->param('interval', '1m');

    //     $response = $this->getYahooFinanceData($symbol, $range, $interval);

    //     if (!$response['success']) {
    //         $this->error($response['error']);
    //     }

    //     $chartData = $this->extractChartData($response);

    //     if (!$chartData['success']) {
    //         $this->error($chartData['error']);
    //     }

    //     $this->success('获取成功', $chartData);
    // }

    // /**
    //  * 获取Yahoo Finance数据
    //  * @param string $symbol
    //  * @param string $range
    //  * @param string $interval
    //  * @return array
    //  */
    // private function getYahooFinanceData($symbol = 'XWIN', $range = '1d', $interval = '1m')
    // {
    //     $baseUrl = 'https://query1.finance.yahoo.com/v8/finance/chart/';
    //     $url = $baseUrl . urlencode($symbol);

    //     $params = http_build_query([
    //         'range' => $range,
    //         'interval' => $interval,
    //     ]);

    //     $fullUrl = $url . '?' . $params;

    //     $ch = curl_init();
    //     curl_setopt_array($ch, [
    //         CURLOPT_URL => $fullUrl,
    //         CURLOPT_RETURNTRANSFER => true,
    //         CURLOPT_FOLLOWLOCATION => true,
    //         CURLOPT_HTTPHEADER => [
    //             'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    //             'Accept: application/json',
    //         ],
    //         CURLOPT_TIMEOUT => 30,
    //         CURLOPT_SSL_VERIFYPEER => true,
    //     ]);

    //     $response = curl_exec($ch);
    //     $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    //     $error = curl_error($ch);
    //     curl_close($ch);

    //     if ($error) {
    //         return [
    //             'success' => false,
    //             'error' => $error,
    //         ];
    //     }

    //     if ($httpCode !== 200) {
    //         return [
    //             'success' => false,
    //             'error' => "HTTP Error: $httpCode",
    //         ];
    //     }

    //     $data = json_decode($response, true);

    //     if (json_last_error() !== JSON_ERROR_NONE) {
    //         return [
    //             'success' => false,
    //             'error' => 'JSON decode error: ' . json_last_error_msg(),
    //         ];
    //     }

    //     return [
    //         'success' => true,
    //         'data' => $data,
    //     ];
    // }

    // /**
    //  * 提取图表数据
    //  * @param array $response/
    //  * @return array
    //  */
    // private function extractChartData($response)
    // {
    //     if (!$response['success']) {
    //         return $response;
    //     }

    //     $result = $response['data']['chart']['result'][0] ?? null;

    //     if (!$result) {
    //         return [
    //             'success' => false,
    //             'error' => 'No chart data found',
    //         ];
    //     }

    //     $meta = $result['meta'] ?? [];
    //     $timestamp = $result['timestamp'] ?? [];
    //     $indicators = $result['indicators']['quote'][0] ?? [];

    //     return [
    //         'success' => true,
    //         'symbol' => $meta['symbol'] ?? null,
    //         'currency' => $meta['currency'] ?? null,
    //         'exchangeName' => $meta['exchangeName'] ?? null,
    //         'regularMarketPrice' => $meta['regularMarketPrice'] ?? null,
    //         'chartPreviousClose' => $meta['chartPreviousClose'] ?? null,
    //         'timestamps' => $timestamp,
    //         'open' => $indicators['open'] ?? [],
    //         'high' => $indicators['high'] ?? [],
    //         'low' => $indicators['low'] ?? [],
    //         'close' => $indicators['close'] ?? [],
    //         'volume' => $indicators['volume'] ?? [],
    //     ];
    // }
    /**
     * 获取最新的价格数据
     */
    public function getLatestPrice()
    {
        // 检查是否在10秒内已经执行过（避免重复请求）
        $lastFetchTime = Cache::get('last_time_series_fetch_time');
        if ($lastFetchTime && time() - $lastFetchTime < 10) {
            $this->error('请求过于频繁，请稍后再试');
        }

        // 设置请求间隔
        Cache::set('last_time_series_fetch_time', time(), 10);

        $url = "{$this->baseUrl}/price?apikey={$this->apiKey2}&symbol={$this->symbol}";
        // 发起HTTP请求
        $response = $this->httpGet($url);

        if (!$response) {
            $this->error('API请求失败');
        }

        $data = json_decode($response, true);
        if (!isset($data['price']) || !is_numeric($data['price'])) {
            $this->error('API返回数据格式错误');
        }
        $this->success('获取成功', $data['price']);
    }

    /**
     * 获取quote数据并缓存（供内部调用）
     * @return array
     */
    private function fetchQuoteData()
    {
        $limitCheck = $this->checkRequestLimit();

        if (!$limitCheck['allowed']) {
            return ['success' => false, 'error' => '请求频率超限'];
        }

        $apiKey = $limitCheck['key'];

        $url = "{$this->baseUrl}/quote?symbol={$this->symbol}&apikey={$apiKey}";
        $response = $this->httpGet($url);

        if (!$response) {
            return ['success' => false, 'error' => 'API请求失败'];
        }

        $data = json_decode($response, true);

        if (!isset($data['symbol'])) {
            $errorMsg = 'API返回数据格式错误';
            cmf_log($errorMsg . ': ' . substr($response, 0, 500));
            return ['success' => false, 'error' => $errorMsg];
        }

        $this->incrementKeyCount($limitCheck['prefix']);

        // 缓存数据（有效期7天）
        $cacheKey = 'quote_data_' . $this->symbol;
        Cache::set($cacheKey, $data, 604800);

        return [
            'success' => true,
            'symbol' => $data['symbol'],
            'data' => $data
        ];
    }

    /**
     * 获取quote数据并缓存（供外部直接调用）
     */
    public function fetchQuote()
    {
        // 检查是否在55秒内已经执行过（避免重复请求）
        $lastFetchTime = Cache::get('last_fetch_quote_time');
        if ($lastFetchTime && time() - $lastFetchTime < 55) {
            $this->error('请求过于频繁，请55秒后再试');
        }

        // 设置请求间隔标记
        Cache::set('last_fetch_quote_time', time(), 120);

        $result = $this->fetchQuoteData();
        if (!$result['success']) {
            $this->error($result['error']);
        }
        $this->success('数据获取并保存成功', $result);
    }

    /**
     * 前端获取quote缓存数据
     */
    public function getQuote()
    {
        $cacheKey = 'quote_data_' . $this->symbol;
        $data = Cache::get($cacheKey, []);

        if (empty($data)) {
            $this->error('暂无数据');
        }

        $this->success('获取成功', $data);
    }
    /**
     * 定时任务入口（由系统定时调用）
     */
    public function cronFetch()
    {
        // $this->clearCache();
        if (!$this->isInTradingWindow()) {
            // 收盘后30分钟内（16:00-16:30）仍需刷新一次，其余非交易时段返回最后一次完整数据
            $nyNow = new \DateTime('now', new \DateTimeZone('America/New_York'));
            $dayOfWeek = (int)$nyNow->format('w');

            if ($dayOfWeek !== 0 && $dayOfWeek !== 6) {
                $currentTime = (int)$nyNow->format('G') * 60 + (int)$nyNow->format('i');

                if ($currentTime >= 16 * 60 && $currentTime < 16 * 60 + 30) {
                    $timeSeriesResult = $this->fetchAndSaveData();
                    $quoteResult = $this->fetchQuoteData();
                    $this->saveLastFullData($timeSeriesResult, $quoteResult);
                    cmf_log('cronFetch[收盘后] 刷新完成');
                    $this->success('收盘后数据获取成功', [
                        'timeSeries' => $timeSeriesResult,
                        'quote' => $quoteResult
                    ]);
                    return;
                }
            }

            // 非交易时段：优先读 last_full_data，若完全为空则自愈抓取一次最近交易日数据
            $data = $this->getLastCompleteData(true);
            cmf_log('cronFetch[非交易] 返回 last_full_data');
            $this->success('非交易时段，返回最后一次完整数据', $data);
            return;
        }

        $timeSeriesResult = $this->fetchAndSaveData();
        $quoteResult = $this->fetchQuoteData();
        $this->saveLastFullData($timeSeriesResult, $quoteResult);
        cmf_log('cronFetch[交易时段] 刷新完成');

        $this->success('数据获取成功', [
            'timeSeries' => $timeSeriesResult,
            'quote' => $quoteResult
        ]);
    }

    /**
     * 将最后一次成功的完整数据持久化到固定 key，供非交易时段直接读取
     * @param array $timeSeriesResult fetchAndSaveData 的返回
     * @param array $quoteResult fetchQuoteData 的返回
     */
    private function saveLastFullData($timeSeriesResult, $quoteResult)
    {
        $timeSeries = ($timeSeriesResult['success'] ?? false) && !empty($timeSeriesResult['data'])
            ? $timeSeriesResult['data']
            : Cache::get('time_series_data_' . $this->getCurrentOrLastOpeningDate(), []);

        $quote = !empty($quoteResult['data'])
            ? $quoteResult['data']
            : Cache::get('quote_data_' . $this->symbol, []);

        if (empty($timeSeries) && empty($quote)) {
            cmf_log('saveLastFullData 跳过：timeSeries 和 quote 都为空');
            return;
        }

        // TTL=0：永久缓存，避免过期后非交易时段读不到；仅通过 clearCache 主动清理
        Cache::set('last_full_data', [
            'timeSeries' => $timeSeries,
            'quote' => $quote,
            'saved_at' => time(),
        ], 0);
        cmf_log('saveLastFullData 成功写入 last_full_data');
    }

    /**
     * 从缓存中获取最后一次完整数据（非交易时段回退用）
     * @param bool $autoFetch 当缓存全空时是否主动抓取一次最近交易日数据（避免 TTL 过期后长期空白）
     * @return array
     */
    private function getLastCompleteData($autoFetch = false)
    {
        // 优先读取持久化的最后一次完整数据
        $lastFull = Cache::get('last_full_data');
        if (!empty($lastFull['timeSeries']) || !empty($lastFull['quote'])) {
            return [
                'timeSeries' => !empty($lastFull['timeSeries']) ? $lastFull['timeSeries'] : '无数据',
                'quote' => !empty($lastFull['quote']) ? $lastFull['quote'] : '无数据',
            ];
        }

        // last_full_data 为空时，回退为从最近交易日缓存中查找
        $openingDate = $this->getCurrentOrLastOpeningDate();

        $allData = [];
        for ($i = 0; $i <= 7; $i++) {
            $checkDate = date('Y-m-d', strtotime("{$openingDate} -{$i} days"));
            $allData = Cache::get('time_series_data_' . $checkDate, []);
            if (!empty($allData)) {
                break;
            }
        }

        $quoteData = Cache::get('quote_data_' . $this->symbol, []);

        // 自愈：缓存全空时（TTL 过期或首次部署），主动抓取一次最近交易日数据
        if ($autoFetch && empty($allData) && empty($quoteData)) {
            cmf_log('getLastCompleteData 触发自愈抓取（last_full_data 与缓存均为空）');
            try {
                $lockKey = 'time_series_self_heal_lock';
                if (!Cache::get($lockKey)) {
                    Cache::set($lockKey, 1, 300);

                    $tsResult = $this->fetchAndSaveData();
                    $qResult = $this->fetchQuoteData();
                    $this->saveLastFullData($tsResult, $qResult);

                    if (!empty($tsResult['data'])) {
                        $allData = $tsResult['data'];
                    }
                    if (!empty($qResult['data'])) {
                        $quoteData = $qResult['data'];
                    }
                }
            } catch (\Throwable $e) {
                cmf_log('getLastCompleteData 自愈异常：' . $e->getMessage());
            } finally {
                Cache::delete($lockKey);
            }
        }

        return [
            'timeSeries' => empty($allData) ? '无数据' : $allData,
            'quote' => empty($quoteData) ? '无数据' : $quoteData,
        ];
    }

    /**
     * 强制刷新指定日期的数据（绕过交易时段限制，用于手动调试/首次部署）
     * @param string|null $date 指定日期 Y-m-d，为空则自动取最近交易日
     * @param string $type all|timeSeries|quote  刷新类型
     */
    public function forceRefresh()
    {
        $date = $this->request->param('date', '');
        $type = $this->request->param('type', 'all');

        // 清空当前 key 锁，确保可以立即请求
        Cache::delete('time_series_self_heal_lock');

        $result = [];

        // 处理 timeSeries 数据
        if ($type === 'all' || $type === 'timeSeries') {
            $tsResult = $this->forceFetchTimeSeries($date);
            $result['timeSeries'] = $tsResult;
        }

        // 处理 quote 数据
        if ($type === 'all' || $type === 'quote') {
            $qResult = $this->fetchQuoteData();
            $result['quote'] = $qResult;
        }

        // 成功后写入 last_full_data
        if (isset($result['timeSeries']) && isset($result['quote'])) {
            $this->saveLastFullData($result['timeSeries'], $result['quote']);
        } elseif (isset($result['timeSeries'])) {
            // 只刷 timeSeries 时，尝试保留已有 quote
            $cachedQuote = Cache::get('quote_data_' . $this->symbol, []);
            $this->saveLastFullData($result['timeSeries'], ['data' => $cachedQuote]);
        } elseif (isset($result['quote'])) {
            $cachedTS = Cache::get('time_series_data_' . ($date ?: $this->getCurrentOrLastOpeningDate()), []);
            $this->saveLastFullData(['success' => true, 'data' => $cachedTS], $result['quote']);
        }

        $this->success('强制刷新完成', [
            'requested_date' => $date ?: 'auto',
            'type' => $type,
            'result' => $result,
        ]);
    }

    /**
     * 强制获取指定日期的 timeSeries 数据
     * @param string|null $date
     * @return array
     */
    private function forceFetchTimeSeries($date = null)
    {
        $limitCheck = $this->checkRequestLimit();
        if (!$limitCheck['allowed']) {
            return ['success' => false, 'error' => '请求频率超限'];
        }

        $apiKey = $limitCheck['key'];

        // 如果指定了日期，直接用；否则用最近交易日
        $startDate = $date ?: $this->getCurrentOrLastOpeningDate();
        $endDate = date('Y-m-d', strtotime("{$startDate} +1 day"));

        $url = "{$this->baseUrl}/time_series?apikey={$apiKey}&symbol={$this->symbol}&exchange={$this->exchange}&interval={$this->interval}&order={$this->order}&start_date={$startDate}&end_date={$endDate}";

        cmf_log("forceFetchTimeSeries 请求日期：{$startDate} → {$endDate}");
        $response = $this->httpGet($url);

        if (!$response) {
            return ['success' => false, 'error' => 'API请求失败'];
        }

        $data = json_decode($response, true);
        if (!isset($data['values']) || !is_array($data['values'])) {
            $errorMsg = 'API返回数据格式错误';
            cmf_log($errorMsg . ': ' . substr($response, 0, 500));
            return ['success' => false, 'error' => $errorMsg];
        }

        $this->incrementKeyCount($limitCheck['prefix']);

        // 获取已有缓存数据进行合并
        $cacheKey = 'time_series_data_' . $startDate;
        $existingData = Cache::get($cacheKey, []);

        $existingDates = array_column($existingData, 'datetime');
        $addCount = 0;
        foreach ($data['values'] as $item) {
            if (!in_array($item['datetime'], $existingDates)) {
                $addCount++;
            }
        }

        $mergeResult = $this->mergeTimeSeriesData($existingData, $data['values']);
        $mergedData = $mergeResult['data'];
        $updateCount = $mergeResult['updateCount'];

        Cache::set($cacheKey, $mergedData, 604800);

        cmf_log("forceFetchTimeSeries 成功：{$startDate} 共 " . count($data['values']) . " 条，新增 {$addCount}，更新 {$updateCount}");

        return [
            'success' => true,
            'date' => $startDate,
            'allCount' => count($data['values']),
            'addCount' => $addCount,
            'updateCount' => $updateCount,
            'data' => $mergedData,
        ];
    }

    /**
     * HTTP GET请求封装
     * @param string $url
     * @return bool|string
     */
    private function httpGet($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            curl_close($ch);
            return false;
        }

        curl_close($ch);
        return $response;
    }


}
