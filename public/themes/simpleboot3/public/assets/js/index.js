function formatDate(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

// 获取当前日期
const today = new Date();

// 获取前一天的日期
const yesterday = new Date(today);
yesterday.setDate(today.getDate());
const end_date = formatDate(yesterday);

// 获取前180天的日期
const thirtyOneDaysAgo = new Date(today);
thirtyOneDaysAgo.setDate(today.getDate() - 180);
const start_date = formatDate(thirtyOneDaysAgo);

const _total = $("#market_cap").text();
const getData = () => {
  $.ajax({
    url: "/api/timeSeries/getData",
    type: "GET",
    dataType: "json",
    success: function (res) {
      // 处理返回的数据
      // console.log(res);
      // const first = res.data[0];
      const quoteData = res.data.quoteData;
      const timeSeriesData = res.data.allData;
      // console.log(quoteData);
      // console.log(timeSeriesData);
      $("#volume").text(formatNumber(quoteData.volume));
      // $("#previous_close").text(data.previous_close);
      const timeArr = quoteData.datetime.split(" ");
      const dateArr = timeArr[0].split("-"); // 分割日期和时间
      $("#date").text(`${dateArr[1]}/${dateArr[2]}`);
      // const len = timeSeriesData.length;
      const currentPrice = quoteData.close;
      // console.log(currentPrice);
      $("#price").text("$" + formatNumber(currentPrice));

      $("#market_cap")
        .text("$" + formatNumber(currentPrice * _total))
        .css({ opacity: 1 });
      // 所有数据的 volume 总和
      let totalVolume = 0;
      timeSeriesData.forEach((item) => {
        totalVolume += Number(item.volume);
      });
      // console.log(totalVolume);
      // $("#volume").text(formatNumber(quoteData.volume));
      createChart(timeSeriesData);
      setTimeout(() => {
        getData();
      }, 30000);
    },
  });
};
getData();
function formatNumber(num) {
  num = parseFloat(num);

  if (isNaN(num)) {
    return "0";
  }

  if (num >= 1000000000) {
    return (num / 1000000000).toFixed(3) + "B"; // 十亿
  } else if (num >= 1000000) {
    return (num / 1000000).toFixed(3) + "M"; // 百万
  } else if (num >= 1000) {
    return (num / 1000).toFixed(3) + "K"; // 千
  } else {
    return num.toFixed(3); // 直接返回
  }
}
// const getPrice = () => {
//   $.ajax({
//     url: "/api/timeSeries/getLatestPrice",
//     type: "GET",
//     dataType: "json",
//     success: function (res) {
//       $("#price").text("$" + formatNumber(res.data));

//       $("#market_cap")
//         .text("$" + formatNumber(res.data * _total))
//         .css({ opacity: 1 });
//       // setTimeout(() => {
//       //   getPrice();
//       //   // 如果时间是00:00:01，则刷新页面
//       //   if (new Date().getHours() === 0 && new Date().getMinutes() === 0 && new Date().getSeconds() === 1) {
//       //     getData();
//       //   }
//       // }, 10000);
//     },
//   });
// };
// getPrice();
function createChart(chartJjson) {
  // 解析JSON数据并获取月日格式
  function getMonthDayFormat(data) {
    if (!Array.isArray(data)) {
      return [];
    }

    return data
      .map((item) => {
        if (!item.datetime) {
          return null;
        }

        // 从datetime字符串中提取日期部分
        const dateStr = item.datetime.split(" ")[0]; // 获取"YYYY-MM-DD"部分
        const [year, month, day] = dateStr.split("-"); // 分割年、月、日

        // 格式化月日，去掉前导零
        const formattedMonth = parseInt(month, 10);
        const formattedDay = parseInt(day, 10);
        const formattedYear = parseInt(year, 10);
        const formattedDate = `${formattedYear}-${formattedMonth}-${formattedDay}`;
        const formattedTime = `${item.datetime.split(" ")[1].substring(0, 5)}`;

        return formattedTime;
      })
      .filter((date) => date !== null); // 过滤掉无效数据
  }
  // 获取close的数组组成数组
  function getCloseArray(data) {
    if (!Array.isArray(data)) {
      return [];
    }
    return data.map((item) => {
      if (!item.close) {
        return null;
      }
      return item.close;
    });
  }
  const monthDayData = getMonthDayFormat(chartJjson);
  const closeArray = getCloseArray(chartJjson);

  var chartDom = document.getElementById("echart");
  if (chartDom) {
    // 计算数据最小值
    let dataMin = Math.min(...closeArray);
    let dataMax = Math.max(...closeArray);

    // 动态设置 Y 轴最小值
    let yMin = (dataMin - 0.1).toFixed(2); //Math.min(8, dataMin); // 核心逻辑
    let yMax = (dataMax + 0.1).toFixed(2); //Math.max(8.3, dataMax); // 核心逻辑
    var myChart = echarts.init(chartDom);
    var option = {
      tooltip: {
        show: true,
        trigger: "axis",
        backgroundColor: "rgba(255,255,255,1)",
        borderColor: "#ffffff",
        borderWidth: 1,
        textStyle: {
          color: "#999",
          fontSize: 12,
          align: "left",
          lineHeight: 24,
        },
        formatter: function (params) {
          const index = params[0].dataIndex;
          const item = chartJjson[index];

          return `<span style="color:var(--color);font-size:16px;margin-right:5px">Chart.price $${
            item.close
          }</span> <span>${
            params[0].axisValue
          }</span><p style="color:#999;font-size:12px">Chart.trxMarktVolume: ${formatNumber(item.volume)}</p>`;
        },
        axisPointer: {
          type: "line",
          lineStyle: {
            color: "rgba(255,255,255,.15)",
            width: 1,
          },
        },
      },
      grid: {
        left: "20px",
        right: "30px",
        bottom: "16px",
        top: "30px",
        containLabel: true,
      },
      xAxis: {
        type: "category",
        boundaryGap: false,
        data: monthDayData,
        axisLabel: {
          color: "#999", // 设置y轴标签颜色为白色
          fontSize: 12, // 设置y轴标签字体大小
        },
        axisLine: { show: false }, // 隐藏x轴线
        axisTick: { show: false }, // 隐藏x轴刻度
        splitLine: { show: false }, // 隐藏x轴网格线
      },
      yAxis: {
        type: "value",
        min: yMin,
        max: yMax,
        interval: 0.05,
        axisLabel: {
          color: "#999", // 设置y轴标签颜色为白色
          fontSize: 12, // 设置y轴标签字体大小
        },
        splitLine: {
          color: "#999", // 设置y轴网格线颜色为白色
          lineStyle: {
            width: 0.5,
            type: "dashed", // 设置y轴网格线为虚线
          },
        }, // 隐藏x轴网格线
      },
      series: [
        {
          data: closeArray, // 生成随机数据
          type: "line",
          symbolSize: 5,
          areaStyle: {
            color: {
              type: "linear",
              x: 0,
              y: 0,
              x2: 0,
              y2: 1,
              colorStops: [
                {
                  offset: 0,
                  color: "rgba(37, 99, 235, 0.1)", // 起始颜色
                },
                {
                  offset: 1,
                  color: "rgba(37, 99, 235, 0)", // 结束颜色
                },
              ],
            },
          },
          smooth: true,
          lineStyle: {
            width: 4,
            color: "#2563eb",
          },
        },
      ],
    };

    myChart.setOption(option);

    // 窗口大小改变时重新调整图表大小
    window.addEventListener("resize", function () {
      myChart.resize();
    });
  }
}
// 使用示例
$(function () {});
